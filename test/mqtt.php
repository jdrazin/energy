<?php
declare(strict_types=1);
namespace Src;

use \PhpMqtt\Client\MqttClient;

require_once __DIR__ . '/../vendor/autoload.php';
$mqtt = new Mqtt(['test', 'abc']);
$mqtt->subscribe();

for ($i = 0; $i< 10; $i++) {
    $payload = $i . ' : ' . date('Y-m-d H:i:s');
    $mqtt->publish($payload, 0,false);
    printf("msg $i send\n");
    sleep(1);
}

$mqtt->loop(true);

