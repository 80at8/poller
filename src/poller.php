<?php

require __DIR__ . '/../vendor/autoload.php';

use Amp\Loop;
use GuzzleHttp\Client;
use League\BooBoo\BooBoo;
use League\BooBoo\Formatter\CommandLineFormatter;
use League\CLImate\CLImate;
use Poller\Log;
use Poller\Services\Formatter;
use Poller\Services\Poller;
use function Amp\Promise\all;

bootstrap();

Loop::run(function () {
    $poller = new Poller();
    $data = json_decode(file_get_contents('test_data_DELETE/data.json'));
    $client = new Client();

    output("Starting polling cycle...");
    $running = false;
    Loop::repeat($msInterval = 60000, function ($watcherId) use (&$running, $poller, $data, $client) {
        if ($running === false) {
            $running = true;
            $start = time();
            $results = yield all($poller->buildCoroutines($data->data));
            $timeTaken = time() - $start;
            output("Cycle completed in $timeTaken seconds, got " . count($results) . " results.");

            try {
                $response = $client->request('POST', '/sonar', [
                    'headers' => [
                        'User-Agent' => "SonarPoller/" . getenv('SONAR_POLLER_VERSION', true) ?? 'Unknown',
                        'Accept'     => 'application/json',
                        'Content-Encoding' => 'gzip',
                    ],
                    'body' => Formatter::formatMonitoringData($results),
                ]);
                output($response->getStatusCode() . ' - ' . $response->getBody()->getContents());
            } catch (Exception $e) {
                output($e->getMessage(), true);
            }
            //todo: post to Sonar instance, where it it configured?
            //todo: log output to file system, setup logrotate and display using logio.org or something similar
            $running = false;
        }
    });
});

/**
 * @param string $message
 * @param bool $error
 */
function output(string $message, bool $error = false)
{
    $log = new Log();
    $climate = new CLImate;
    if ($error === false) {
        $log->info($message);
        $climate->lightGreen($message);
    } else {
        $log->error($message);
        $climate->red($message);
    }
}

function bootstrap()
{
    set_exception_handler(function (Throwable $e) {
        $bt = debug_backtrace();
        $caller = array_shift($bt);
        $log = new Log();
        $log->error("Uncaught exception thrown in {$caller['file']} on line {$caller['line']}:");
        $log->error($e->getTraceAsString());
    });

    $booboo = new BooBoo([
        new CommandLineFormatter()
    ]);
    $booboo->setErrorPageFormatter(new CommandLineFormatter());
    $booboo->register();
}
