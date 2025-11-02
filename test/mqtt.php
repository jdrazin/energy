<?php

declare(strict_types=1);
namespace Src;
require_once __DIR__ . '/../vendor/autoload.php';

use \PhpMqtt\Client\MqttClient;
use \PhpMqtt\Client\ConnectionSettings;

$server         = '192.168.1.11';
$port           = 1883;
$username       = 'jdrazin';
$password       = 'mn1CjX7lWG018hP47qkv';
$clean_session  = false;
$mqtt_version   = MqttClient::MQTT_3_1_1;

$connectionSettings = (new ConnectionSettings)  ->setUsername($username)
                                                ->setPassword($password)
                                                ->setKeepAliveInterval(60)
                                                ->setLastWillTopic('emqx/test/last-will')
                                                ->setLastWillMessage('client disconnect')
                                                ->setLastWillQualityOfService(1);


$mqtt = new MqttClient($server, $port, null, $mqtt_version);

$mqtt->connect($connectionSettings, $clean_session);
printf("client connected\n");

$mqtt->subscribe('abc/test', function ($topic, $message) {
    printf("Received message on topic [%s]: %s\n", $topic, $message);
}, 0);

for ($i = 0; $i< 10; $i++) {
    $payload = array(
        'protocol' => 'tcp',
        'date' => date('Y-m-d H:i:s'),
        'url' => 'https://github.com/emqx/MQTT-Client-Examples'
    );
    $mqtt->publish(
    // topic
        'emqx/test',
        // payload
        json_encode($payload),
        // qos
        0,
        // retain
        true
    );
    printf("msg $i send\n");
    sleep(1);
}

$mqtt->loop(true);

