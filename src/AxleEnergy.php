<?php

namespace Src;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AxleEnergy extends Root
{
    private array $api;

    const array RATE = [
                            'import' => null,
                            'export' => 1.00
                        ];
    const string RATE_TYPE = 'ADD';

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->use_local_config();
        $this->class = $this->strip_namespace(__NAMESPACE__, __CLASS__);
        $this->api = $this->apis[$this->class];
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */

    public function getNextGridEvent(): void
    {
        $made_successful_request = false;
        if ($this->skipRequest()) { // skip request if called recently
            $this->requestResult(false); // update timestamp
            return;
        }
        try {
            $this->insertEvent($this->getEvent());
            $made_successful_request = true;
        }
        catch (exception $e) { // log warning if request fails
            $this->logDb('MESSAGE', $e->getMessage(),  null, 'WARNING');
        }
        $this->requestResult($made_successful_request); // update timestamp
    }

    /**
     * @throws GuzzleException
     */
    private function getEvent(): array
    {
        $url = $this->api['endpoint'];
        $headers = ['Authorization' => 'Bearer ' . $this->api['api_token'],
                    'Accept'        => 'application/json',
        ];
        $client = new Client();
        $response = $client->get($url, ['headers' => $headers]);
        return json_decode((string)$response->getBody(), true);
    }

    /**
     * @throws Exception
     */
    private function insertEvent($event): void
    {
        if (                  $event                   &&
            ($start         = $event['start_time'])    &&
            ($stop          = $event['end_time'])      &&
            ($import_export = $event['import_export']) &&
                             !$event['opted_out']      &&
            ($updated_at    = $event['updated_at'])) {
            $import_rate = $export_rate = null;
            switch (strtolower($import_export)) {
                case 'import': {
                    $import_rate = self::RATE['import'];
                    break;
                }
                case 'export': {
                    $export_rate = self::RATE['export'];
                    break;
                }
                default:
                    $message = 'import_export must be import or export';
                    $this->logDb('MESSAGE', $message, null, 'ERROR');
                    throw new Exception($message);
            }
            $sql = 'INSERT INTO `tariff_extraordinaries_per_kwh` (`start`, `stop`, `import_rate`, `export_rate`, `type`)
                                                      VALUES (?,        ?,      ?,             ?,             ?    )
                        ON DUPLICATE KEY UPDATE `import_rate` = ?,
                                                `export_rate` = ?,
                                                `type`        = ?,
                                                `status`      = \'CURRENT\'';
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('ssddss', $start, $stop, $import_rate, $export_rate, $type, $import_rate, $export_rate, $status)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $this->mysqli->commit();
        }
    }
}