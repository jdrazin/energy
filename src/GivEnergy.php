<?php

namespace Energy;

use Energy;
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
    private const int   RESPONSE_OK = 2,
        HOLD_TO_START_GUARD_PERIOD_SECONDS = 30,
        MAX_HOLD_SECONDS = 600,
        CHARGE_DISCHARGE_SLOT_START = 1,
        CHARGE_DISCHARGE_SLOT_STOP = 10,
        CONTROL_CHARGE_DISCHARGE_SLOT = 1;  // slot number used for control
    private const array ENTITIES_BATTERY_AIO = ['SOLAR_W' => 'solar',
        'GRID_W' => 'grid',
        'TOTAL_LOAD_W' => 'consumption'];

    private const       EV_POWER_ACTIVE_IMPORT = 13,  // Instantaneous active power imported by EV. (W or kW)
        EV_POWER_ACTIVE_IMPORT_UNIT = 5,   // kW
        EV_METER_ID = 0;   // meter id
    private const array CONTROL_VALUES = ['Pause Battery' => ['Not Paused' => 0,
        'Pause Charge' => 1,
        'Pause Discharge' => 2,
        'Pause Charge & Discharge' => 3],
    ];

    private const array CHARGE_DIRECTIONS = [
        'CHARGE',
        'DISCHARGE'
    ],
        PRE_DEFAULTS = [
        'Pause Battery' => 'Pause Charge & Discharge',
    ],
        DEFAULT_CHARGE_DISCHARGE_BLOCKS = [
        'CHARGE' => [2 => ['start' => '04:00',
            'stop' => '07:00',
            'target_level_percent' => 95],
            3 => ['start' => '13:00',
                'stop' => '16:00',
                'target_level_percent' => 95],
            4 => ['start' => '22:00',
                'stop' => '00:00',
                'target_level_percent' => 95]],
        'DISCHARGE' => []
    ],
        POST_DEFAULTS = [
        'Enable AC Charge Upper % Limit' => 1,
        'Enable Eco Mode' => 1,
        'AC Charge Enable' => 1,
        'Enable DC Discharge' => 1,
        'AC Charge Upper % Limit' => 95,
        'Battery Reserve % Limit' => 5,
        'Battery Cutoff % Limit' => 5,
        'Inverter Max Output Active Power Percent' => 100,
        'Export Power Priority' => 'Load First', // 'Battery First', 'Grid First'
        'Enable EPS' => 1,
        'Inverter Charge Power Percentage' => 100,
        'Inverter Discharge Power Percentage' => 100,
        'Pause Battery Start Time' => '00:00',
        'Pause Battery End Time' => '00:00',
        'Force Off Grid' => 0,            // Isolate from grid
        'Battery Charge Power' => 6000,
        'Battery Discharge Power' => 6000,
        'Pause Battery' => 'Not Paused',
    ],
        EV_METER_IDS = [
        0                  // single meter id=0
    ],
        EV_MEASURANDS = [
        13,                // instantaneous active power imported by EV. (W or kW)
    ],
        EV_UNITS = [
        0 => 'Wh',
        1 => 'kWh',
        2 => 'varh',
        3 => 'kvarh',
        4 => 'W',
        5 => 'kW',
        6 => 'VA',
        7 => 'kVA',
        8 => 'var',
        9 => 'kvar',
        10 => 'A',
        11 => 'V',
        12 => 'Celsius',
        13 => 'Fahrenheit',
        14 => 'K',
        15 => 'Percent'
    ];

    private array $api, $battery, $inverterControlSettings;

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    public function __construct()
    {
        parent::__construct();
        $this->battery = $this->config['battery'];
        $this->api = $this->apis[__CLASS__];
        $this->getInverterControlSettings();  // get settings
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function getData(): void
    {
        /*
        if ($this->skip_request(__CLASS__)) { // skip request if called recently
            return;
        }*/
        $this->getBattery();
        $this->getEVCharger();
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
            $date = $datetime->add(new DateInterval('P1D'))->format(Root::MYSQL_FORMAT_DATE);
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
        $headers = ['Authorization' => 'Bearer ' . $this->api['api_token'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'];
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
                $power_w = (float)$point['power'][$label]['power'];
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
        $type = 'MEASURED';
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
    public function battery($db_slots): array
    {
        //
        // return effective battery level and capacity for input to optimiser
        //
        $permitted_depth_of_discharge = (((float)$this->config['battery']['permitted_depth_of_discharge_percent']) / 100.0);
        $initial_raw_capacity_kwh = $this->config['battery']['initial_raw_capacity_kwh'];
        $effective_capacity_kwh = $permitted_depth_of_discharge * $initial_raw_capacity_kwh;
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
        $raw_stored_now_kwh = (((float)$battery['percent']) / 100.0) * $initial_raw_capacity_kwh;
        $battery_power_now_w = ((float)$battery['power']) / 1000.0;
        $timestamp_now = (new DateTime())->getTimestamp();
        $timestamp_start = (new DateTime($db_slots->getDbNextDaySlots($db_slots->tariff_combination)[0]['start']))->getTimestamp();
        $time_now_start_s = $timestamp_start - $timestamp_now;
        if ($time_now_start_s < 0 && !self::DEBUG) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'time to start must be positive: ' . $time_now_start_s);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $raw_stored_min_kwh = (1.0 - $permitted_depth_of_discharge) * $initial_raw_capacity_kwh / 2.0;
        $effective_stored_now_kwh = $raw_stored_now_kwh - $raw_stored_min_kwh;
        $energy_now_to_start_kwh = -($battery_power_now_w * ((float)$time_now_start_s) / Energy::JOULES_PER_KWH);
        $effective_stored_start_kwh = $effective_stored_now_kwh + $energy_now_to_start_kwh;
        $raw_stored_max_kwh = (1.0 + $permitted_depth_of_discharge) * $initial_raw_capacity_kwh / 2.0;
        if ($effective_stored_start_kwh > $raw_stored_max_kwh) {   // limit state of charge to within effective range
            $effective_stored_start_kwh = $raw_stored_max_kwh;
        } else if ($effective_stored_start_kwh < $raw_stored_min_kwh) {
            $effective_stored_start_kwh = $raw_stored_min_kwh;
        }
        return ['effective_stored_kwh' => $effective_stored_start_kwh,
            'effective_capacity_kwh' => $effective_capacity_kwh];
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
    public function initialise(): void
    {
        $this->defaults(self::PRE_DEFAULTS);
        $this->set_charge_discharge_blocks_to_default();
        $this->defaults(self::POST_DEFAULTS);
    }

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
     */
    public function set_charge_discharge_blocks_to_default(): void
    { // assume default settings
        foreach (self::CHARGE_DIRECTIONS as $charge_direction) {
            for ($block_number = self::CHARGE_DISCHARGE_SLOT_START; $block_number <= self::CHARGE_DISCHARGE_SLOT_STOP; $block_number++) {
                $settings = self::DEFAULT_CHARGE_DISCHARGE_BLOCKS[$charge_direction][$block_number] ?? ['start' => '00:00',
                    'stop' => '00:00',
                    'target_level_percent' => 90];
                $settings['direction'] = $charge_direction;
                $settings['message'] = __FUNCTION__;
                $this->set_charge_discharge_block($block_number, $settings);
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function slotCommands($next_slot): void
    {
        /*
         * all slots except 1 must be manually disabled in app or web portal
         */
        $start_datetime = $next_slot['start_datetime'] ?? null;
        $start = $next_slot['start'] ?? null;
        $stop = $next_slot['stop'] ?? null;
        $direction = $next_slot['direction'] ?? null;
        $abs_charge_power_w = $next_slot['abs_charge_power_w'] ?? null;
        $target_level_percent = $next_slot['target_level_percent'] ?? null;
        $context = $next_slot['message'] ?? 'no context';
        $countdown_seconds = $this->countdown_to_start_seconds($start_datetime);
        (new Root())->logDb('BATTERY', $context . ": counting down $countdown_seconds seconds ...", 'NOTICE');
        (new Root())->logDb('BATTERY', 'sending commands ...', 'NOTICE');
        sleep($countdown_seconds);
        switch ($direction) {
            case 'CHARGE':
            case 'DISCHARGE':
            {
                if (!$start || !$stop || is_null($target_level_percent) || is_null($abs_charge_power_w)) {
                    throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, $direction . ': time/power arguments must not be empty'));
                }
                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                    ['direction' => $direction,
                        'start' => $start,
                        'stop' => $stop,
                        'abs_charge_power_w' => $abs_charge_power_w,
                        'target_level_percent' => $target_level_percent,
                        'message' => $context]);
                $this->clear_slot($direction == 'CHARGE' ? 'DC Discharge' : 'AC Charge');  // clear other slot direction
                break;
            }
            case 'ECO':
            { // matches battery discharge power to net load: load - solar (i.e. zero export)
                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                    ['direction' => 'CHARGE',
                        'start' => '00:00',
                        'stop' => '00:00',
                        'abs_charge_power_w' => self::POST_DEFAULTS['Battery Charge Power'],
                        'target_level_percent' => self::POST_DEFAULTS['AC Charge Upper % Limit'],
                        'message' => __FUNCTION__]);
                $this->set_charge_discharge_block(self::CONTROL_CHARGE_DISCHARGE_SLOT,
                    ['direction' => 'DISCHARGE',
                        'start' => '00:00',
                        'stop' => '00:00',
                        'abs_charge_power_w' => self::POST_DEFAULTS['Battery Discharge Power'],
                        'target_level_percent' => self::POST_DEFAULTS['Battery Cutoff % Limit'],
                        'message' => __FUNCTION__]);
                $this->command('write', 'Battery Discharge Power', (int)(1000 * $this->battery['max_discharge_kw']), null, __FUNCTION__);
                break;
            }
            case 'IDLE':
            {
                $this->clear_slot('AC Charge');     // clear time slots
                $this->clear_slot('DC Discharge');
                //            $this->command( 'write', 'Pause Battery',           self::CONTROL_VALUES['Pause Battery']['Pause Charge & Discharge'], null, $context);
                break;
            }
            default:
            {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Unknown direction: ' . $direction));
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
    public function set_charge_discharge_block($slot_number, $settings): void
    {
        $direction = $settings['direction'];
        $start = $settings['start'];
        $stop = $settings['stop'];
        $abs_charge_power_w = $settings['abs_charge_power_w'] ?? 0;
        $target_level_percent = $settings['target_level_percent'];
        $message = $settings['message'];
        switch ($direction) {
            case 'CHARGE':
            {
                $type = 'AC Charge';
                $limit = 'Upper';
                $charge_power = 'Battery Charge Power';
                $this->command('write', "AC Charge Enable", 1, null, $message);
                break;
            }
            case 'DISCHARGE':
            {
                $type = 'DC Discharge';
                $limit = 'Lower';
                $charge_power = 'Battery Discharge Power';
                $this->command('write', "Enable DC Discharge", 1, null, $message);
                break;
            }
            default:
            {
                throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, $direction . ': bad direction: ' . $direction));
            }
        }
        // set target battery level
        $this->command('write', "$type $slot_number $limit SOC % Limit", $target_level_percent, null, $message);
        $this->command('write', $charge_power, $abs_charge_power_w, null, $message);

        // set slot times
        $this->command('write', "$type $slot_number Start Time", null, $start, $message);
        $this->command('write', "$type $slot_number End Time", null, $stop, $message);

        $this->command('write', 'Pause Battery', self::CONTROL_VALUES['Pause Battery']['Not Paused'], null, $message);
    }

    /**
     * @throws GuzzleException
     */
    private function clear_slot($type): void
    {
        $block_number = self::CONTROL_CHARGE_DISCHARGE_SLOT;
        $this->command('write', "$type $block_number Start Time", null, '00:00', __FUNCTION__);
        $this->command('write', "$type $block_number End Time", null, '00:00', __FUNCTION__);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    private function command(string $action, string $setting, ?int $value_int, ?string $value_string, ?string $context): mixed
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
        return $value;
    }
}