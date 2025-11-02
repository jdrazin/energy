<?php
namespace Energy\src;
use Exception;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Exceptions\DataTransferException;
use PhpMqtt\Client\Exceptions\RepositoryException;
use PhpMqtt\Client\MqttClient;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Mqtt
{
    const false     CLEAN_SESSION         = false;

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
            $clientId = rand(5, 15);
            $this->mqtt = new MqttClient(self::SERVER, self::PORT, $clientId, self::MQTT_VERSION);
        }
        catch (exception $e) {
            $message = $e->getMessage();
        }
    }

    /**
     * @throws RepositoryException
     * @throws DataTransferException
     */
    public function subscribe($topic): void
    {
        $this->mqtt->subscribe($topic, function ($topic, $message) {
            printf("Received message on topic [%s]: %s\n", $topic, $message);
        }, 0);
    }

    public function publish() {

    }

    private function implode(array $topic): ?string {
        return implode(self::SEPARATOR, $topic);
    }
}