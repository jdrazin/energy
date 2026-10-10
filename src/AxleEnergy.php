<?php

namespace Src;

use DateTime;
use DateTimeZone;
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
        $headers = [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $this->api['api_token']
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
        $event =    [
                        'start_time'    => '2026-10-10T12:46:27.928268+00:00',
                        'end_time'      => '2026-10-10T12:46:27.928268+00:00',
                        'import_export' => 'export',
                        'opted_out'     => false,
                        'updated_at'    => '2026-10-10T12:46:27.928268+00:00'
                    ];
        if (                  $event                                        &&
            ($start         = $this->datetime_mysql($event['start_time']))  &&
            ($stop          = $this->datetime_mysql($event['end_time']))    &&
            ($import_export = $event['import_export'])                      &&
                             !$event['opted_out']                           &&
            ($updated_at    = $event['updated_at'])) {
            $type        = self::RATE_TYPE;
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
                !$stmt->bind_param('ssddsdds', $start, $stop, $import_rate, $export_rate, $type, $import_rate, $export_rate, $type)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $this->mysqli->commit();
        }
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function datetime_mysql($datetime): string|null
    {
        $date = new DateTime($datetime);
        $date->setTimezone(new DateTimeZone('UTC'));    // force conversion to UTC to prevent server drift issues
        return $date->format('Y-m-d H:i:s');
    }
}