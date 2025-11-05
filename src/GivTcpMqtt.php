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
 * GivEnergy wrapper class for MQTT
 *
 * for GivTcpMqtt stack see: https://github.com/php-mqtt/client
 * for GivTCP see: https://github.com/britkat1980/giv_tcp/blob/main/README.md
 * for implementation tips see: https://www.aligrant.com/web/blog/2024-10-04_deploying_giv_tcp_for_local_control_of_givenergy_inverter_through_mqtt?utm_source=chatgpt.com
 *
 */

class GivTcpMqtt {
    const bool      CLEAN_SESSION         = false;

    const int       KEEP_ALIVE_SECONDS    = 60,
                    PORT                  = 1883,
                    QOS                   = 0;

    const string    SERVER                = '192.168.1.14',
                    USERNAME              = 'jdrazin',
                    PASSWORD              = 'mn1CjX7lWG018hP47qkv',
                    MQTT_VERSION          = MqttClient::MQTT_3_1_1,
                    SEPARATOR             = '/';

    private         string      $root_topic;
    protected       MqttClient  $mqtt;

    public function __construct($topic) {
        try {
            $this->root_topic   = $this->implode($topic);
            $connectionSettings = (new ConnectionSettings)  ->setUsername(self::USERNAME)
                                                            ->setPassword(self::PASSWORD)
                                                            ->setKeepAliveInterval(self::KEEP_ALIVE_SECONDS)
                                              //              ->setLastWillTopic($this->topic_base . 'last-will')
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
        $this->mqtt->subscribe($this->root_topic, function ($topic, $message) {
            printf("Received message on topic [%s]: %s\n", $topic, $message);
        }, 0);
    }

    /**
     * @throws RepositoryException
     * @throws DataTransferException
     */
    public function publish($topic, $message, $qos, $retain): void {
        $this->mqtt->publish($this->root_topic . self::SEPARATOR . $topic, $message, $qos, $retain);
    }

    private function implode(array $topic): ?string {
        return implode(self::SEPARATOR, $topic);
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