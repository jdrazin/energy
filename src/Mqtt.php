<?php
namespace Src;
use Exception;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Exceptions\InvalidMessageException;
use PhpMqtt\Client\Exceptions\MqttClientException;
use PhpMqtt\Client\Exceptions\ProtocolViolationException;
use PhpMqtt\Client\Exceptions\RepositoryException;
use PhpMqtt\Client\MqttClient;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

/*
 *
 * wrapper class for MQTT
 *
 * see: https://github.com/php-mqtt/client
 *
 */

class Mqtt {
    const bool      CLEAN_SESSION         = false;

    const int       KEEP_ALIVE_SECONDS    = 60,
                    PORT                  = 1883,
                    QOS                   = 1;

    const string    SERVER                = '192.168.1.11',
                    USERNAME              = 'jdrazin',
                    PASSWORD              = 'mn1CjX7lWG018hP47qkv',
                    MQTT_VERSION          = MqttClient::MQTT_3_1_1,
                    SEPARATOR             = '/';

    private         string      $topic_base;
    protected       MqttClient  $mqtt;

    public function __construct($topic) {
        try {
            $this->topic_base   = $this->implode($topic);
            $connectionSettings = (new ConnectionSettings)  ->setUsername(self::USERNAME)
                                                            ->setPassword(self::PASSWORD)
                                                            ->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS)
                                                            ->setLastWillTopic($this->topic_base . 'last-will')
                                                            ->setLastWillMessage('client disconnect')
                                                            ->setLastWillQualityOfService(self::QOS);
            $this->mqtt = new MqttClient(self::SERVER, self::PORT, null, self::MQTT_VERSION);
            $this->mqtt->connect($connectionSettings, self::CLEAN_SESSION);
        }
        catch (exception $e) {
            $message = $e->getMessage();
        }
    }

    /**
     * @throws RepositoryException
     * @throws DataTransferException
     */
    public function subscribe(): void {
        $this->mqtt->subscribe($this->topic_base, function ($topic, $message) {
            printf("Received message on topic [%s]: %s\n", $topic, $message);
        }, 0);
    }

    /**
     * @throws RepositoryException
     * @throws DataTransferException
     */
    public function publish($payload, $qos, $retain): void {
        $this->mqtt->publish($this->topic_base, $payload, $qos, $retain);
    }

    private function implode(array $topic): ?string {
        return implode(self::SEPARATOR, $topic) . self::SEPARATOR;
    }

    /**
     * @throws ProtocolViolationException
     * @throws MqttClientException
     * @throws InvalidMessageException
     * @throws DataTransferException
     */
    public function loop($flag): void {
        $this->mqtt->loop($flag);
    }

    /**
     * @throws DataTransferException
     */
    public function disconnect(): void {
        $this->mqtt->disconnect();
    }
}