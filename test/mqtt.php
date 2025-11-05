<?php
declare(strict_types=1);
namespace Src;

require_once __DIR__ . '/../vendor/autoload.php';

const ROOT_TOPIC =  [
                    'GivEnergy',
                    'control',
                    'CH2344G323'
                    ],
        TOPIC   = 'setDischargeSlot9',
        MESSAGE = '{"start":"0123","finish":"0456","dischargeToPercent":"85"}';

$mqtt = new GivTcpMqtt(ROOT_TOPIC);
$mqtt->subscribe();
$mqtt->publish(TOPIC, MESSAGE, 0,true);
// $mqtt->loop(true);
$mqtt->disconnect();



