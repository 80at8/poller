<?php

namespace Poller\DeviceMappers\Mimosa;

use Exception;
use Poller\DeviceMappers\BaseDeviceMapper;
use Poller\Log;
use Poller\Models\SnmpResult;
use Poller\Services\Formatter;

class AxAccessPoint extends BaseDeviceMapper
{
    public function map(SnmpResult $snmpResult)
    {
        return $this->setConnectedRadios(parent::map($snmpResult));
    }

    /**
     * These SMs are always connected to the 2nd interface, which is the multipoint wireless interface
     * @param SnmpResult $snmpResult
     * @return array|mixed
     */
    private function setConnectedRadios(SnmpResult $snmpResult):SnmpResult
    {
        $interfaces = $snmpResult->getInterfaces();
        foreach ($interfaces as $key => $interface) {
            if (strpos($interface->getName(), "wlan") !== false) {
                $existingMacs = $interfaces[$key]->getConnectedLayer1Macs();

                try {
                    $result = $this->walk("1.3.6.1.4.1.43356.2.1.2.9.6.1.1.2");
                    foreach ($result as $oid) {
                        try {
                            $existingMacs[] = Formatter::formatMac($oid->getValue()->__toString());
                        } catch (Exception $e) {
                            $log = new Log();
                            $log->exception($e);
                            continue;
                        }
                    }
                }
                catch (Exception $e) {
                }

                $interfaces[$key]->setConnectedLayer1Macs(array_unique($existingMacs));
                $snmpResult->setInterfaces($interfaces);
                break;
            }
        }


        return $snmpResult;
    }
}
