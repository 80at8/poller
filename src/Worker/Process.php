<?php

namespace Poller\Worker;

use Amp\Loop;
use Amp\Parallel\Context\Context;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\StatusError;
use Amp\Parallel\Sync\ChannelException;
use Amp\Parallel\Sync\ExitResult;
use Amp\Parallel\Sync\SynchronizationError;
use Amp\Process\Process as BaseProcess;
use Amp\Process\ProcessInputStream;
use Amp\Process\ProcessOutputStream;
use Amp\Promise;
use Poller\Overrides\ProcessHub;
use Throwable;
use function Amp\call;
use function sprintf;

final class Process implements Context
{
    const SCRIPT_PATH = __DIR__ . "/Internal/process-runner.php";
    const KEY_LENGTH = 32;

    /** @var string|null External version of SCRIPT_PATH if inside a PHAR. */
    private static $pharScriptPath;

    /** @var string|null PHAR path with a '.phar' extension. */
    private static $pharCopy;

    /** @var string|null Cached path to located PHP binary. */
    private static $binaryPath;

    /** @var ProcessHub */
    private $hub;

    /** @var \Amp\Process\Process */
    private $process;

    /** @var \Amp\Parallel\Sync\ChannelledSocket */
    private $channel;

    /**
     * Creates and starts the process at the given path using the optional PHP binary path.
     *
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param string|null $cwd Working directory.
     * @param mixed[] $env Array of environment variables.
     * @param string $binary Path to PHP binary. Null will attempt to automatically locate the binary.
     *
     * @return Promise<Process>
     * @throws \Exception
     */
    public static function run($script, string $cwd = null, array $env = [], string $binary = null): Promise
    {
        $process = new self($script, $cwd, $env, $binary);
        return call(function () use ($process) {
            yield $process->start();
            return $process;
        });
    }

    /**
     * @param string|array $script Path to PHP script or array with first element as path and following elements options
     *     to the PHP script (e.g.: ['bin/worker', 'Option1Value', 'Option2Value'].
     * @param string|null $cwd Working directory.
     * @param mixed[] $env Array of environment variables.
     * @param string $binary Path to PHP binary. Null will attempt to automatically locate the binary.
     *
     * @throws \Error If the PHP binary path given cannot be found or is not executable.
     * @throws \Exception
     */
    public function __construct($script, string $cwd = null, array $env = [], string $binary = null)
    {
        $this->hub = Loop::getState(self::class);
        if (!$this->hub instanceof ProcessHub) {
            $this->hub = new ProcessHub;
            Loop::setState(self::class, $this->hub);
        }

        $options = [
            "html_errors" => "0",
            "display_errors" => "0",
            "log_errors" => "1",
        ];

        if ($binary === null) {
            if (\PHP_SAPI === "cli") {
                $binary = \PHP_BINARY;
            } else {
                $binary = self::$binaryPath ?? self::locateBinary();
            }
        } elseif (!\is_executable($binary)) {
            throw new \Error(sprintf("The PHP binary path '%s' was not found or is not executable", $binary));
        }


        $scriptPath = self::SCRIPT_PATH;
        if (\is_array($script)) {
            $script = \implode(" ", \array_map("escapeshellarg", $script));
        } else {
            $script = \escapeshellarg($script);
        }

        $command = \implode(" ", [
            \escapeshellarg($binary),
            $this->formatOptions($options),
            \escapeshellarg($scriptPath),
            $this->hub->getUri(),
            $script,
        ]);

        $this->process = new BaseProcess($command, $cwd, $env);
    }

    private static function locateBinary(): string
    {
        $executable = \strncasecmp(\PHP_OS, "WIN", 3) === 0 ? "php.exe" : "php";

        $paths = \array_filter(\explode(\PATH_SEPARATOR, \getenv("PATH")));
        $paths[] = \PHP_BINDIR;
        $paths = \array_unique($paths);

        foreach ($paths as $path) {
            $path .= \DIRECTORY_SEPARATOR . $executable;
            if (\is_executable($path)) {
                return self::$binaryPath = $path;
            }
        }

        throw new \Error("Could not locate PHP executable binary");
    }

    private function formatOptions(array $options)
    {
        $result = [];

        foreach ($options as $option => $value) {
            $result[] = sprintf("-d%s=%s", $option, $value);
        }

        return \implode(" ", $result);
    }

    /**
     * Private method to prevent cloning.
     */
    private function __clone()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function start(): Promise
    {
        return call(function () {
            try {
                $pid = yield $this->process->start();

                yield $this->process->getStdin()->write($this->hub->generateKey($pid, self::KEY_LENGTH));

                $this->channel = yield $this->hub->accept($pid);

                return $pid;
            } catch (Throwable $exception) {
                $this->process->kill();
                throw new ContextException("Starting the process failed", 0, $exception);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * {@inheritdoc}
     */
    public function receive(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        return call(function () {
            try {
                $data = yield $this->channel->receive();
            } catch (ChannelException $e) {
                throw new ContextException("The context stopped responding, potentially due to a fatal error or calling exit", 0, $e);
            }

            if ($data instanceof ExitResult) {
                $data = $data->getResult();
                throw new SynchronizationError(sprintf(
                    'Process unexpectedly exited with result of type: %s',
                    \is_object($data) ? \get_class($data) : \gettype($data)
                ));
            }

            return $data;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function send($data): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        if ($data instanceof ExitResult) {
            throw new \Error("Cannot send exit result objects");
        }

        return $this->channel->send($data);
    }

    /**
     * {@inheritdoc}
     */
    public function join(): Promise
    {
        if ($this->channel === null) {
            throw new StatusError("The process has not been started");
        }

        return call(function () {
            try {
                $data = yield $this->channel->receive();
            } catch (Throwable $exception) {
                if ($this->isRunning()) {
                    $this->kill();
                }
                throw new ContextException("Failed to receive result from process", 0, $exception);
            }

            if (!$data instanceof ExitResult) {
                if ($this->isRunning()) {
                    $this->kill();
                }
                throw new SynchronizationError("Did not receive an exit result from process");
            }

            $this->channel->close();

            $code = yield $this->process->join();
            if ($code !== 0) {
                throw new ContextException(sprintf("Process exited with code %d", $code));
            }


            return $data->getResult();
        });
    }

    /**
     * Send a signal to the process.
     *
     * @see \Amp\Process\Process::signal()
     *
     * @param int $signo
     *
     * @throws \Amp\Process\ProcessException
     * @throws \Amp\Process\StatusError
     */
    public function signal(int $signo)
    {
        $this->process->signal($signo);
    }

    /**
     * Returns the PID of the process.
     *
     * @see \Amp\Process\Process::getPid()
     *
     * @return int
     *
     * @throws \Amp\Process\StatusError
     */
    public function getPid(): int
    {
        return $this->process->getPid();
    }

    /**
     * Returns the STDIN stream of the process.
     *
     * @see \Amp\Process\Process::getStdin()
     *
     * @return ProcessOutputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStdin(): ProcessOutputStream
    {
        return $this->process->getStdin();
    }

    /**
     * Returns the STDOUT stream of the process.
     *
     * @see \Amp\Process\Process::getStdout()
     *
     * @return ProcessInputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStdout(): ProcessInputStream
    {
        return $this->process->getStdout();
    }

    /**
     * Returns the STDOUT stream of the process.
     *
     * @see \Amp\Process\Process::getStderr()
     *
     * @return ProcessInputStream
     *
     * @throws \Amp\Process\StatusError
     */
    public function getStderr(): ProcessInputStream
    {
        return $this->process->getStderr();
    }

    /**
     * {@inheritdoc}
     */
    public function kill()
    {
        $this->process->kill();

        if ($this->channel !== null) {
            $this->channel->close();
        }
    }
}
