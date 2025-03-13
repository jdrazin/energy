<?php
namespace Src;
use DateMalformedStringException;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/*

  see:  https://community.givenergy.cloud/

  GivEnergy modes

  1 - ECO mode
        ON
          - net load (load - solar) satisfied by battery until 'Battery Cutoff % Limit'
        OFF

  2 - Timed charge : charge to upper SOC % Limit between start and end

  3 - Timed discharge : charge to lower SOC % Limit between start and end

  4 - Timed export :

*/

class GivEnergy extends Root
{
    private const int   RESPONSE_OK                             = 2,
                        HOLD_TO_START_GUARD_PERIOD_SECONDS      = 0,
                        MAX_HOLD_SECONDS                        = 600,
                        CHARGE_DISCHARGE_SLOT_START             = 1,
                        CHARGE_DISCHARGE_SLOT_STOP              = 10,
                        CONTROL_CHARGE_DISCHARGE_SLOT           = 1,   // slot number used for control
                        EV_POWER_ACTIVE_IMPORT                  = 13,  // Instantaneous active power imported by EV. (W or kW)
                        EV_POWER_ACTIVE_IMPORT_UNIT             = 5,   // kW
                        EV_METER_ID                             = 0,
                        UPPER_SOC_LIMIT_PERCENT                 = 95,
                        LOWER_SOC_LIMIT_PERCENT                 = 5;
    private const array ENTITIES_BATTERY_AIO = [
                                            'SOLAR_W'                => ['solar',       'power'],
                                            'GRID_W'                 => ['grid',        'power'],
                                            'LOAD_HOUSE_W'           => ['consumption', 'power'],
                                            'BATTERY_LEVEL_PERCENT'  => ['battery',     'percent']
                                            ],
                        CONTROL_VALUES = [
                                            'Pause Battery'             => ['Not Paused'                => 0,
                                                                            'Pause Charge'              => 1,
                                                                            'Pause Discharge'           => 2,
                                                                            'Pause Charge & Discharge'  => 3],
                                        ],
                        CHARGE_DIRECTIONS = [
                                            'CHARGE',
                                            'DISCHARGE'
                                            ],
                        PRE_DEFAULTS =      [
                                            'Pause Battery' => 'Pause Charge & Discharge',
                                            ],
                        PRESET_CHARGE_DISCHARGE_BLOCKS = [
                                            'CHARGE' => [   2 => [  'start'                 => '02:00',
                                                                    'stop'                  => '05:00',
                                                                    'target_level_percent'  => self::UPPER_SOC_LIMIT_PERCENT]],
                                            'DISCHARGE' => []
                                        ],
                        INACTIVE_CHARGE_DISCHARGE_BLOCK = [
                                           'start'                 => '00:00',
                                           'stop'                  => '00:00',
                                           'target_level_percent'  => 0,
                                           ],
                        POST_DEFAULTS = [
                                            'Enable AC Charge Upper % Limit'            => 1,               // Activate upper limits, defaults to OFF
                                            'Enable Eco Mode'                           => 1,               // Set Eco mode, defaults to ON
                                            'AC Charge Enable'                          => 1,               // Activate battery charge timer settings, defaults to OFF
                                            'Enable DC Discharge'                       => 1,               // Enables battery discharge timer settings, defaults to OFF
                                            'AC Charge Upper % Limit'                   => 95,              // Sets upper limit, defaults to 100%
                                            'Battery Reserve % Limit'                   => 5,               // Set reserve limit, defaults to 4%
                                            'Battery Charge Power'                      => 6000,            // Set battery charge power, defaults to 6000W
                                            'Battery Discharge Power'                   => 6000,            // Set battery discharge power, defaults to 6000W
                                            'Battery Cutoff % Limit'                    => 5,               // Set cut-off limit, defaults to 4%
                                            'Inverter Max Output Active Power Percent'  => 100,             // Sets inverter capacity, defaults to 100%
                                            'Export Power Priority'                     => 'Load First',    // 'Battery First', 'Grid First' (does not reset, no default)
                                            'Enable EPS'                                => 1,               // Emergency Power Supply (does not reset, no default)
                                            'Inverter Charge Power Percentage'          => 100,             // Inverter charge power percentage (does not reset, no default)
                                            'Inverter Discharge Power Percentage'       => 100,             // Inverter discharge power percentage (does not reset, no default)
                                            'Pause Battery'                             => 'Not Paused',    // Pause battery (does not reset, no default)
                                            'Pause Battery Start Time'                  => '00:00',         // Pause battery start time (does not reset, no default)
                                            'Pause Battery End Time'                    => '00:00',         // Pause battery end time (does not reset, no default)
                                            'Force Off Grid'                            => 0,               // Isolate from grid (does not reset, no default)
                                        ],
                        EV_METER_IDS = [
                                          0                                                                 // single meter id=0
                                       ],
                        EV_MEASURANDS = [
                                          13,                                                               // instantaneous active power imported by EV. (W or kW)
                                        ];

    private array $api, $battery, $inverterControlSettings;

    /**
     * @throws Exception
     * @throws GuzzleException
     * @throws \Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->battery = $this->config['battery'];
        $this->api = $this->apis[$this->strip_namespace(__NAMESPACE__,__CLASS__)];
        $this->getInverterControlSettings();  // get settings
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function reset_inverter(): void
    {
        /*
         * restores autonomous battery operation settings
         */
        $this->defaults(self::PRE_DEFAULTS);
        $this->set_preset_charge_discharge_blocks();
        $this->defaults(self::POST_DEFAULTS);
    }


    /**
     * @throws GuzzleException
     */
    private function defaults($defaults): void
    {
        foreach ($defaults as $default => $value) {
            if (is_int($value)) {
                $this->command('write', $default, $value, null, __FUNCTION__);
            } elseif (is_string($value)) {
                $this->command('write', $default, null, $value, __FUNCTION__);
            } else {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'unhandled type'));
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function set_preset_charge_discharge_blocks(): void
    { // assume default settings
        foreach (self::CHARGE_DIRECTIONS as $charge_direction) {
            for ($block_number = self::CHARGE_DISCHARGE_SLOT_START; $block_number <= self::CHARGE_DISCHARGE_SLOT_STOP; $block_number++) {
                $settings = self::PRESET_CHARGE_DISCHARGE_BLOCKS[$charge_direction][$block_number] ?? self::INACTIVE_CHARGE_DISCHARGE_BLOCK;
                $this->set_charge_discharge_block($block_number, $charge_direction, $settings, __FUNCTION__);
            }
        }
        $this->propertyWrite('presetChargeDischargeBlocksSet', 'int', 1);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function clear_preset_charge_discharge_blocks(): void {
        // clear preset charge/discharge time blocks
        foreach (self::CHARGE_DIRECTIONS as $charge_direction) {
            for ($block_number = self::CHARGE_DISCHARGE_SLOT_START; $block_number <= self::CHARGE_DISCHARGE_SLOT_STOP; $block_number++) {
                $this->set_charge_discharge_block($block_number, $charge_direction, self::INACTIVE_CHARGE_DISCHARGE_BLOCK, __FUNCTION__);
            }
        }
        $this->propertyWrite('presetChargeDischargeBlocksSet', 'int', 0);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function getData(): void
    {
        /*
        if ($this->skip_request($this->strip_namespace(__NAMESPACE__,__CLASS__))) { // skip request if called recently
            $this->request_result(__CLASS__, false); // update timestamp for failed request
            return;
        }*/
        $this->getBattery();
        $this->getEVCharger();
        // $this->request_result(__CLASS__, true); // update timestamp for successful request
    }

    /**
     * @throws DateMalformedStringException
     * @throws GuzzleException
     * @throws Exception
     */
    private function getBattery(): void     // get battery data by day
    {
        $today = (new DateTime())->format(Root::MYSQL_FORMAT_DATE);
        $datetime = null;
        foreach (self::ENTITIES_BATTERY_AIO as $entity => $v) {
            $datetime_latest_value = new DateTime($this->latestValueDatetime($entity, 'MEASURED', self::EARLIEST_DATE));
            if (is_null($datetime) || $datetime_latest_value > $datetime) {
                $datetime = clone $datetime_latest_value;
            }
        }
        $date = $datetime->format(Root::MYSQL_FORMAT_DATE);
        while ($date <= $today) {
            $this->insertPointsBattery($this->getBatteryData($date));
            $date = $datetime->modify('+1 day')->format(Root::MYSQL_FORMAT_DATE);
        }
    }

    /**
     * @throws DateMalformedStringException
     * @throws GuzzleException
     * @throws Exception
     */
    private function getEVCharger(): void        // get ev charger
    {
        $now = (new DateTime())->format(Root::MYSQL_FORMAT_DATETIME);
        $datetime = new DateTime($this->latestValueDatetime('LOAD_EV_W', 'MEASURED', self::EARLIEST_DATE));
        $slots = new Slots($datetime->format(Root::MYSQL_FORMAT_DATETIME), $now);
        while ($slot = $slots->next_slot()) { // get data points for every slot until now
            $this->insertPointsEVCharger($this->getEVChargerData($slot['start'], $slot['stop']));
        }
    }

    /**
     * @throws GuzzleException
     */
    private function getBatteryData($date): array
    {
        $url = $this->api['base_url'] . '/inverter/' . $this->api['inverter_serial_number'] . '/data-points/' . $date;
        $headers = [
                        'Authorization' => 'Bearer ' . $this->api['api_token'],
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ];
        $data_points = [];
        $client = new Client();
        do {                                                    // keep loading until last page
            $response = $client->get($url, ['headers' => $headers]);
            $response_data = json_decode((string)$response->getBody(), true);
            $data_points = array_merge($data_points, $response_data['data']);
        } while ($url = $response_data['links']['next']);
        return $data_points;
    }

    /**
     * @throws GuzzleException
     */
    private function getEVChargerData($start, $stop): array
    {
        $url = $this->api['base_url'] . '/ev-charger/' . $this->api['ev_charger_uuid'] . '/meter-data/';
        $headers = [
                        'Authorization' => 'Bearer ' . $this->api['api_token'],
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ];
        $data_points = [];
        $client = new Client();
        $query = ['start_time' => $start,
            'end_time' => $stop];
        foreach (self::EV_METER_IDS as $meter_id) {
            $query['meter_ids[' . $meter_id . ']'] = $meter_id;
        }
        foreach (self::EV_MEASURANDS as $measurand_id => $measurand) {
            $query['measurands[' . $measurand_id . ']'] = $measurand;
        }
        $page = 1;
        do {                                                    // keep loading until last page
            $query['page'] = $page++;
            $response = $client->get($url, ['headers' => $headers, 'query' => $query]);
            $response_data = json_decode((string)$response->getBody(), true);
            $data_points = array_merge($data_points, $response_data['data']);
        } while ($response_data['links']['next']);
        return $data_points;
    }

    /**
     * @throws Exception
     */
    private function insertPointsBattery($points): void
    {
        $sql = 'INSERT INTO `values` (`entity`, `type`, `datetime`, `value`) 
                              VALUES (?,        ?,      ?,          ?)
                        ON DUPLICATE KEY UPDATE `value` = ?, `timestamp` = NOW()';
        $type = 'MEASURED';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssdd', $entity, $type, $datetime, $power_w, $power_w)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach (self::ENTITIES_BATTERY_AIO as $entity => $label) {
            foreach ($points as $point) {
                $power_w = (float) $point['power'][$label[0]][$label[1]];
                $datetime = rtrim($point['time'], 'Z');
                if (!$stmt->execute()) {
                    $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                    $this->logDb('MESSAGE', $message, 'ERROR');
                    throw new Exception($message);
                }
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function insertPointsEVCharger($points): void
    {
        $sql = 'INSERT INTO `values` (`entity`, `type`, `datetime`, `value`) 
                              VALUES (?,        ?,      ?,          ?)
                        ON DUPLICATE KEY UPDATE `value` = ?, `timestamp` = NOW()';
        $entity = 'LOAD_EV_W';
        $type   = 'MEASURED';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssdd', $entity, $type, $datetime, $power_w, $power_w)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($points as $point) {
            if ($point['meter_id'] == self::EV_METER_ID) {
                $datetime = $point['timestamp'];
                $measurements = $point['measurements'];
                foreach ($measurements as $measurement) {
                    $measurand = $measurement['measurand'];
                    $unit = $measurement['unit'];
                    if ($measurand == self::EV_POWER_ACTIVE_IMPORT &&
                        $unit == self::EV_POWER_ACTIVE_IMPORT_UNIT) {
                        $power_w = 1000.0 * (float)$measurement['value'];
                        if (!$stmt->execute()) {
                            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                            $this->logDb('MESSAGE', $message, 'ERROR');
                            throw new Exception($message);
                        }
                        break;
                    }
                }
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function batteryLevelkwh($db_slots): float
    {
        //
        // return effective battery level and capacity for input to optimiser
        //
        $initial_raw_capacity_kwh = $this->config['battery']['initial_raw_capacity_kwh'];
        $url = $this->api['base_url'] . '/inverter/' . $this->api['inverter_serial_number'] . '/system-data/latest';
        $headers = [
            'Authorization' => 'Bearer ' . $this->api['api_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $client = new Client();
        $response = $client->get($url, ['headers' => $headers]);
        $data = json_decode((string)$response->getBody(), true);
        $battery = $data['data']['battery'];
        $stored_now_kwh = (((float)$battery['percent']) / 100.0) * $initial_raw_capacity_kwh;
        $battery_power_now_w = ((float)$battery['power']);
        $timestamp_now = (new DateTime())->getTimestamp();        // extrapolate battery level to beginning of next slot
        $timestamp_start = (new DateTime($db_slots->getDbNextDaySlots($db_slots->tariff_combination)[0]['start']))->getTimestamp();
        $time_duration_s = $timestamp_start - $timestamp_now;
        if ($time_duration_s < 0 && !self::DEBUG_MINIMISER) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'time to start must be positive: ' . $time_duration_s);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        return $stored_now_kwh -($battery_power_now_w * ((float)$time_duration_s) / Energy::JOULES_PER_KWH);
    }

    /**
     * @throws GuzzleException
     */
    private function getInverterControlSettings(): void
    {
        $url = $this->api['base_url'] . '/inverter/' . $this->api['inverter_serial_number'] . '/settings/';
        $headers = ['Authorization' => 'Bearer ' . $this->api['api_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'];
        $client = new Client();
        $response = $client->get($url, ['headers' => $headers]);
        $settings = json_decode((string)$response->getBody(), true)['data'];
        foreach ($settings as $setting) {
            $this->inverterControlSettings[$setting['name']] = $setting['id'];
            ksort($this->inverterControlSettings);
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function control($slot_command): void {
        /*
         * all slots except 1 must be manually disabled in app or web portal
         *
         * clear preset charge/discharge blocks if set
         */
        if ($this->propertyRead('presetChargeDischargeBlocksSet', 'int')) {
            $this->clear_preset_charge_discharge_blocks();
        }
        $start_datetime         = $slot_command['start_datetime']          ?? null;
        $start                  = $slot_command['start']                   ?? null;
        $stop                   = $slot_command['stop']                    ?? null;
        $mode                   = $slot_command['mode']                    ?? null;
        $abs_charge_power_w     = $slot_command['abs_charge_power_w']      ?? null;
        $target_level_percent   = $slot_command['target_level_percent']    ?? null;
        $message                = $slot_command['message']                 ?? 'no context';
        if (USE_CRONTAB) {   // count down to exact slot start time when in crontab mode
            $countdown_seconds      = $this->countdown_to_start_seconds($start_datetime);
            (new Root())->logDb('BATTERY', $message . ": counting down $countdown_seconds seconds ...", 'NOTICE');
            sleep($countdown_seconds);
            (new Root())->logDb('BATTERY', 'sending commands ...', 'NOTICE');
        }
        switch ($mode) {
            case 'CHARGE':
            case 'DISCHARGE': {
                                if (!$start || !$stop || is_null($target_level_percent) || is_null($abs_charge_power_w)) {
                                    throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, $mode . ': time/power arguments must not be empty'));
                                }
                                /*
                                 * allow discharge to continue beyond reaching target to avoid satisfying load from grid at peak times
                                 */
                                $target_level_percent = ($mode == 'DISCHARGE') ? self::LOWER_SOC_LIMIT_PERCENT : $target_level_percent;
                                /*
                                 * stay inside operating range
                                 */
                                $target_level_percent = min(max(self::LOWER_SOC_LIMIT_PERCENT, $target_level_percent), self::UPPER_SOC_LIMIT_PERCENT);
                                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                                                                    $mode,
                                                                    [
                                                                    'start'                 => $start,
                                                                    'stop'                  => $stop,
                                                                    'abs_charge_power_w'    => $abs_charge_power_w,
                                                                    'target_level_percent'  => $target_level_percent
                                                                    ],
                                                               __FUNCTION__);
                                $this->clear_slot($mode == 'CHARGE' ? 'DC Discharge' : 'AC Charge');  // clear other slot direction
                                break;
                            }
            case 'ECO':     { // matches battery discharge power to net load: load - solar (i.e. zero export)
                                $this->command( 'write', 'Enable Eco Mode', null, 1, $message);
                                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                                    'CHARGE',
                                    [
                                        'start'                 => '00:00',
                                        'stop'                  => '00:00',
                                        'abs_charge_power_w'    => self::POST_DEFAULTS['Battery Charge Power'],
                                        'target_level_percent'  => self::POST_DEFAULTS['AC Charge Upper % Limit']
                                    ],
                                    __FUNCTION__);
                                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                                    'DISCHARGE',
                                    [
                                        'start'                 => '00:00',
                                        'stop'                  => '00:00',
                                        'abs_charge_power_w'    => self::POST_DEFAULTS['Battery Discharge Power'],
                                        'target_level_percent'  => self::POST_DEFAULTS['Battery Cutoff % Limit']
                                    ],
                                    __FUNCTION__);
                                $this->command('write', 'Battery Discharge Power', (int)(1000 * $this->battery['max_discharge_kw']), null, __FUNCTION__);
                                break;
                            }
            case 'IDLE':    {
                                $this->command( 'write', 'Enable Eco Mode', null, 0, $message);
                                $this->clear_slot('AC Charge');     // clear time slots
                                $this->clear_slot('DC Discharge');
                                break;
                            }
            default: {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Unknown control mode: ' . $mode));
            }
        }
    }

    /**
     * @throws DateMalformedStringException
     */
    private function countdown_to_start_seconds($start_datetime): int
    {
        if (!($now = new DateTime())) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'bad clock'));
        } elseif (($countdown_seconds = $now->diff(new DateTime($start_datetime))->s +
                60 * ($now->diff(new DateTime($start_datetime))->i +
                    60 * ($now->diff(new DateTime($start_datetime))->h +
                        24 * ($now->diff(new DateTime($start_datetime))->days)))
                - self::HOLD_TO_START_GUARD_PERIOD_SECONDS) < 0
        ) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, "too close to or after start $start_datetime"));
        } elseif ($countdown_seconds > self::MAX_HOLD_SECONDS) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, "countdown ($countdown_seconds) is too large"));
        }
        return $countdown_seconds;
    }

    /**
     * Write charge / discharge time block
     *
     * Charges/discharges at full maximum power
     * - may result in unwanted export in discharge case
     *
     * @throws GuzzleException
     * @throws Exception
     */
    public function set_charge_discharge_block($slot_number, $charge_direction, $settings, $function): void
    {
        $start                  = $settings['start'];
        $stop                   = $settings['stop'];
        $abs_charge_power_w     = $settings['abs_charge_power_w'] ?? 0;
        $target_level_percent   = $settings['target_level_percent'];
        switch ($charge_direction) {
            case 'CHARGE':
            {
                $type = 'AC Charge';
                $limit = 'Upper';
                $charge_power = 'Battery Charge Power';
                $this->command('write', "AC Charge Enable", 1, null, $function);
                break;
            }
            case 'DISCHARGE':
            {
                $type = 'DC Discharge';
                $limit = 'Lower';
                $charge_power = 'Battery Discharge Power';
                $this->command('write', "Enable DC Discharge", 1, null, $function);
                break;
            }
            default:
            {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, $charge_direction . ': bad direction: ' . $charge_direction));
            }
        }
        // set target battery level
        $this->command('write', "$type $slot_number $limit SOC % Limit", $target_level_percent, null, $function);
        $this->command('write', $charge_power, $abs_charge_power_w, null, $function);

        // set slot times
        $this->command('write', "$type $slot_number Start Time", null, $start, $function);
        $this->command('write', "$type $slot_number End Time", null, $stop, $function);

        $this->command('write', 'Pause Battery', self::CONTROL_VALUES['Pause Battery']['Not Paused'], null, $function);
    }

    /**
     * @throws GuzzleException
     */
    private function clear_slot($type): void
    {
        $block_number = self::CONTROL_CHARGE_DISCHARGE_SLOT;
        $command_prefix = $type . ' ' . $block_number . ' ';
        $this->command('write', $command_prefix . 'Start Time', null, '00:00', __FUNCTION__);
        $this->command('write', $command_prefix . 'End Time', null, '00:00', __FUNCTION__);
        $this->command('write', $command_prefix . ($type == 'AC Charge' ?  'Upper' : 'Lower') . ' SOC % Limit', ($type == 'AC Charge' ?  self::UPPER_SOC_LIMIT_PERCENT : self::LOWER_SOC_LIMIT_PERCENT), null, __FUNCTION__);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function command(string $action, string $setting, ?int $value_int, ?string $value_string, ?string $context): void
    {
        switch ($action = strtolower(trim($action))) {
            case 'read':                // read setting value into `settings` table
            case 'write':
            {             // write setting to device, then read to `settings` table
                break;
            }
            default:
            {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Invalid action: ' . $action));
            }
        }
        if (!isset($this->inverterControlSettings[$setting])) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'No command with name: ' . $setting));
        }
        $id = $this->inverterControlSettings[$setting];
        $url = $this->api['base_url'] . '/inverter/' . $this->api['inverter_serial_number'] . '/settings/' . $id . '/' . $action;
        $headers = ['Authorization' => 'Bearer ' . $this->api['api_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'];
        $client = new Client();
        switch ($action) {
            case 'write':
            {
                $value = null;
                if ((!is_null($value_int) && !is_null($value_string)) || (is_null($value_int) && is_null($value_string))) {
                    throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'No or more than one non-null value'));
                } elseif (!is_null($value_int)) {
                    $value = $value_int;
                } elseif (!is_null($value_string)) {
                    $value = $value_string;
                }
                $request_body = ['headers' => $headers, 'query' => ['value' => (string)$value, 'context' => $context]];
                break;
            }
            default:
            {
                $request_body = ['headers' => $headers, 'query' => ['context' => $context]];
            }
        }
        $response = $client->post($url, $request_body);
        if (intval(($response_code = $response->getStatusCode()) / 100) != self::RESPONSE_OK) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Bad response code: ' . $response_code));
        }
        switch ($action) {
            case 'read':
            {
                if (!($contents = $response->getBody()->getContents()) ||
                    !($contents_data = json_decode($contents, true)) ||
                    !isset($contents_data['data']['value'])) {
                    throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Bad response value'));
                } else {
                    $value = $contents_data['data']['value'];
                }
            }
            case 'write':
            {
                break;
            }
        }
        // log value to `settings` table
        $sql = 'INSERT INTO `settings` (`device`, `action`, `setting`, `value`, `context`) 
                                VALUES (?,        ?,        ?,         ?,       ?)';
        $device = 'BATTERY';
        $action = strtoupper($action);
        $value = (string)$value;
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssss', $device, $action, $setting, $value, $context) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
        unset($stmt);
    }
}