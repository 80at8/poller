<?php

namespace Poller\Services;

use Amp\Parallel\Worker\DefaultPool;
use Amp\Parallel\Worker\Pool;
use Poller\Helpers\SysInfo;
use Poller\Pipelines\DeviceFactory;
use Poller\Tasks\PingHosts;
use Poller\Tasks\SnmpGet;
use Poller\Worker\WorkerFactory;
use function Amp\call;

class Poller
{
    private Pool $icmpPool;
    private Pool $snmpPool;
    private SysInfo $sysInfo;

    public function __construct()
    {
        $this->sysInfo = new SysInfo();
        $factory = new WorkerFactory();
        $this->icmpPool = new DefaultPool($this->sysInfo->optimalIcmpQueueSize(), $factory);
        $this->snmpPool = new DefaultPool($this->sysInfo->optimalSnmpQueueSize(), $factory);
    }

    public function buildCoroutines($data):array {
        $deviceFactory = new DeviceFactory($data);
        $coroutines = [];
        $icmpDevices = $deviceFactory->getIcmpDevices();
        if (count($icmpDevices) > 0) {
            $icmpDevices = array_chunk(
                $icmpDevices,
                ceil(count($icmpDevices)/$this->sysInfo->optimalIcmpQueueSize())
            );

            foreach ($icmpDevices as $icmpDeviceChunk) {
                $pingHosts = new PingHosts($icmpDeviceChunk);
                $coroutines[] = call(function () use ($pingHosts) {
                    return yield $this->icmpPool->enqueue($pingHosts);
                });
            }
        }

        $matcher = new SysObjectIDMatcher();
        foreach ($deviceFactory->getSnmpDevices() as $ip => $device) {
            $snmpGet = new SnmpGet($device, $matcher);
            $coroutines[] = call(function () use ($snmpGet) {
                return yield $this->snmpPool->enqueue($snmpGet);
            });
        }

        return $coroutines;
    }
}
