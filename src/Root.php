<?php
namespace Src;
use DateTime;
use Exception;
use mysqli;

class Root
{
    public const string     EARLIEST_DATE = '2024-08-01 00:00:00';
    const bool              DEBUG_MINIMISER = false;
    const string            APIS_PATH = '/home/jdrazin/.energy/apis.json',
                            CONFIG_PATH = '/var/www/html/energy/config.json',
                            MYSQL_FORMAT_DATETIME = 'Y-m-d H:i:s',
                            MYSQL_FORMAT_DATE = 'Y-m-d';
    protected const int     SECONDS_PER_HOUR = 3600,
                            SECONDS_PER_MINUTE = 60,
                            JSON_MAX_DEPTH = 10,
                            LOG_MAX_CHARS = 255,
                            CUBIC_SPLINE_MULTIPLE = 8;
    private const array     INEQUALITIES = ['>' => 'ASC', '<' => 'DESC'],
                            ENTITIES = [
                                            'GRID_W'                => ['grid_kw',                  1000.0],
                                            'SOLAR_W'               => ['solar_kw',                 1000.0],
                                            'LOAD_HOUSE_W'          => ['load_house_kw',            1000.0],
                                            'BATTERY_LEVEL_PERCENT' => ['battery_level_percent',    1.0]
                                        ];
    protected array         $apis = [], $config = [], $tariff_combinations = [];
    protected               mysqli $mysqli;
    private const           int FORECAST_STALE_HOURS = 2;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        /*
         *  change file’s group to group user belongs to:
         *      sudo chown :www-data <pathname>
         *      sudo chmod g+r <pathname>
         *
         *  use group that includes www-data
         *      usermod -a -G jdrazin www-data
         *
         *  add group x permission to all parent folders leading to <pathname>
         *      chmod +x /<parent folder>
         */
        if (!(($api_text = file_get_contents($path = self::APIS_PATH)) &&
            ($this->apis = json_decode($api_text, true, self::JSON_MAX_DEPTH)))) {
            throw new Exception('bad or missing config json: ' . $path);
        }
        else {
            $mysql = $this->apis['MySQL'];
            if (!(($this->mysqli = new mysqli($mysql['host'],
                    $mysql['user'],
                    $mysql['password'],
                    $mysql['database'])) &&
                $this->mysqli->autocommit(false))) {
                throw new Exception('bad mysql database');
            }
            // load config if not override
            if (!($this->config ?? false) && (!(($config_text = file_get_contents($path = self::CONFIG_PATH)) &&
                ($this->config = json_decode($config_text, true, self::JSON_MAX_DEPTH))))) {
                throw new Exception('bad or missing config json: ' . $path);
            }
            $this->tariffCombinationsActiveFirst();                                 // get tariff combinations of interest, starting with active combination
        }
    }

    /**
     * @throws Exception
     */
    public function propertyWrite($name, $type, $value): void {  // attempts to authenticate against BasicAuth username:password or token
        $value_float = $value_string = $value_int = null;
        if (is_float($value)) {
            $value_float = $value;
        }
        elseif (is_string($value)) {
            $value_string = $value;
        }
        elseif (is_int($value)) {
            $value_int = $value;
        }
        else {
            throw new Exception('Bad type for property: ' . $type);
        }
        $sql = 'INSERT INTO `properties`   (`name`,     `value_float`,     `value_string`,     `value_int`)
                                    VALUES (?,          ?,                 ?,                  ?)
                    ON DUPLICATE KEY UPDATE             `value_float` = ?, `value_string` = ?, `value_int` = ?';
        if (!($stmt = $this->mysqli->prepare($sql))        ||
            !$stmt->bind_param('sdsidsi', $name, $value_float, $value_string, $value_int, $value_float, $value_string, $value_int)  ||
            !$stmt->execute()                                                                                                                      ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
    }

    public function propertyRead($name, $type): null|float|string|int {  // attempts to authenticate against BasicAuth username:password or token
        $sql = 'SELECT  `value_float`,
                        `value_string`,
                        `value_int`
                  FROM  `properties`
                  WHERE `name` = ?';
        if (!($stmt = $this->mysqli->prepare($sql))                                ||
            !$stmt->bind_param('s', $name)                              ||
            !$stmt->bind_result($value_float, $value_string, $value_int) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $stmt->fetch();
        switch ($type) {
            case 'float': {
                return is_null($value_float) ? null : (float)   $value_float;
            }
            case 'string': {
                return is_null($value_string) ? null : (string) $value_string;
            }
            case 'int': {
                return is_null($value_int)    ? null : (int)    $value_int;
            }
            default: {
                return null;
            }
        }
    }

    protected function authenticate(): bool {  // attempts to authenticate against BasicAuth username:password or token
        $username = $_SERVER['PHP_AUTH_USER']   ?? '';
        $password = $_SERVER['PHP_AUTH_PW']     ?? '';
        $token    = $_GET['token']              ?? '';
        $sql = 'SELECT EXISTS (SELECT  `username`
                                 FROM  `users`
                                 WHERE (CRC32(`username`) = CRC32(?) AND CRC32(`password`) = CRC32(?)) OR 
                                       CRC32(`token`) = CRC32(?))';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sss', $username, $password, $token) ||
            !$stmt->bind_result($exists) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $basic_auth = $stmt->fetch() && $exists;
        return $basic_auth;
    }

    protected function strip_namespace($namespace, $class): string {
        $prefix = $namespace . '\\';
        if (str_starts_with($class, $prefix)) {
            return substr($class, strlen($prefix));
        }
        else {
            return $class;
        }
    }

    protected function sqlErrMsg($class, $function, $line, $mysqli, $sql): string
    {
        $message = $class . ', ';
        $message .= $function . ($line ? ', line=' . $line : '');
        $message .= ': mysql error=' . (is_null($mysqli->error) ? 'NULL' : $mysqli->error);
        $message .= ' (' . (is_null($mysqli->errno) ? 'NULL' : $mysqli->errno) . ')';
        $message .= ', sql=\'' . ($sql ?: 'EMPTY') . '\'';
        return $this->ellipsis(trim($message), self::LOG_MAX_CHARS);
    }

    protected function errMsg($class, $function, $line, $msg): string
    {
        $message = $class . ', ';
        $message .= $function . ($line ? ', line=' . $line : '');
        $message .= ': ' . $msg;
        return $this->ellipsis(trim($message), self::LOG_MAX_CHARS);
    }

    public function ellipsis($string, $max_length): string
    {
        if (strlen($string) > $max_length) {
            $string = substr($string, 0, $max_length - 3) . '...';
        }
        return $string;
    }

    public function logDb($event, $message, $urgency): void
    {
        $mysql = $this->apis['MySQL'];
        $mysqli = $this->mysqli = new mysqli($mysql['host'],
            $mysql['user'],
            $mysql['password'],
            $mysql['database']);
        $message = is_null($message) ? null : $this->ellipsis($message, self::LOG_MAX_CHARS);
        $mysqli->autocommit(true);
        $sql = 'INSERT INTO `log` (`event`, `message`, `urgency`) VALUES (?, ?, ?)';
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param('sss', $event, $message, $urgency);
        $stmt->execute();
        $mysqli->close();
        unset($mysqli);
    }

    /**
     * @throws Exception
     */
    public function interpolateAveragePowerW($entity, $type, $start, $stop): float
    { // returns average of points
        $points = [];
        if (!($points_in_range = $this->pointsInRange($entity, $type, $start, $stop))) {  // get points between start and stop) {
            $message = $this->errMsg(__CLASS__, __FUNCTION__ . ", $entity, $type, $start, $stop", __LINE__, 'no data');
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $datetime_start = new DateTime($start);
        $datetime_start_timestamp = $datetime_start->getTimestamp();
        $datetime_first_point = new DateTime($points_in_range[0]['datetime']);
        $time_after_start_s = $datetime_first_point->getTimestamp() - $datetime_start_timestamp;
        if ($time_after_start_s > 0) {                                                             // if first point not limit, get latest point if any before start of range
            $point_below_range = $this->pointNearest($entity, $type, '<', $start); // get latest point if any before start of range
            if ($datetime_below_range = $point_below_range['datetime']) {                           // add initial point to first points range
                $time_before_start_s = $datetime_start_timestamp - (new DateTime($datetime_below_range))->getTimestamp();
                $fraction = $time_before_start_s / ($time_before_start_s + $time_after_start_s);
                $power_w = (1.0 - $fraction) * $point_below_range['power_w'] + $fraction * $points_in_range[0]['power_w'];
                $points[] = ['datetime' => $start,
                    'power_w' => $power_w];
                $points = array_merge($points, $points_in_range);
            } else {
                $points_in_range[0]['datetime'] = $start;
                $points = $points_in_range;
            }
        } else {                                                                                     // if no points before, adjust 1st point to start at limit
            $points_in_range[0]['datetime'] = $start;
            $points = $points_in_range;
        }
        $datetime_stop = new DateTime($stop);
        $datetime_stop_unix_timestamp = $datetime_stop->getTimestamp();
        $point_last = end($points);
        $last = $point_last['datetime'];
        $datetime_last_point = new DateTime($last);
        $time_after_last_s = $datetime_stop->getTimestamp() - $datetime_last_point->getTimestamp();
        if ($time_after_last_s > 0) {                                                              // if last point not on limit, get first point if any after end of range
            $point_above_range = $this->pointNearest($entity, $type, '>', $stop); // get first point if any after end of range
            if ($datetime_above_range = $point_above_range['datetime']) {                          // add initial point to last points range
                $time_after_stop_s = (new DateTime($datetime_above_range))->getTimestamp() - $datetime_stop_unix_timestamp;
                $fraction = $time_after_stop_s / ($time_after_stop_s + $time_after_last_s);
                $power_w = $fraction * $point_above_range['power_w'] + (1.0 - $fraction) * $point_last['power_w'];
                $points = $points_in_range;
                $points[] = ['datetime' => $stop,
                    'power_w' => $power_w];                            // append point to end of range
            } else {
                $points_in_range[count($points_in_range) - 1]['datetime'] = $stop;
                $points = $points_in_range;
            }
        } else {
            $points_in_range[count($points_in_range) - 1]['datetime'] = $stop;
            $points = $points_in_range;
        }

        if (($count = count($points)) > 1) {   //  if more than one point, calculate time weighted average across array
            $average_acc = 0.0;
            $time_acc = 0.0;
            $timestamp_0 = (float)(new DateTime($points[0]['datetime']))->getTimestamp();
            $value_0 = $points[0]['power_w'];
            reset($points);
            while ($point = next($points)) {
                $timestamp_1 = (float)(new DateTime($point['datetime']))->getTimestamp();
                $value_1 = $point['power_w'];
                $time_width = $timestamp_1 - $timestamp_0;
                $average_acc += $time_width * ($value_0 + $value_1) / 2.0;
                $time_acc += $time_width;
                $timestamp_0 = $timestamp_1;
                $value_0 = $value_1;
            }
            return $average_acc / $time_acc;
        } else if ($count == 1) {
            return $points[0]['power_w'];
        } else {
            $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'no points');
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
    }

    /**
     * @throws Exception
     */
    private function pointsInRange($entity, $type, $start, $stop): array
    {
        $sql = 'SELECT   `datetime`,
                         `value`
                    FROM `values`
                    WHERE `datetime` BETWEEN ? AND ? AND
                          `entity` = ? AND
                          `type`   = ?
                    ORDER BY `datetime`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ssss', $start, $stop, $entity, $type) ||
            !$stmt->bind_result($datetime, $power_w) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $points_in_range = [];
        while ($stmt->fetch()) {
            $points_in_range[] = ['datetime' => $datetime,
                'power_w' => $power_w];
        }
        return $points_in_range;
    }

    /**
     * @throws Exception
     */
    private function pointNearest($entity, $type, $inequality, $datetime): array
    {
        $order = self::INEQUALITIES[$inequality];
        $sql = "SELECT `a`.`datetime`,
                       `a`.`value`
                  FROM `values` `a`
                  INNER JOIN (SELECT        `id`,
                                            `value`,
                                            `datetime`
                                  FROM      `values`
                                  WHERE     `entity` = '$entity' AND
                                            `type`   = '$type'   AND
                                            `datetime` $inequality ?
                                  ORDER BY  `datetime` $order
                                  LIMIT     1) `b` ON  `b`.`id` = `a`.`id`";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $datetime) ||
            !$stmt->bind_result($datetime_nearest, $power_w) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $stmt->fetch();
        return ['datetime' => $datetime_nearest,
            'power_w' => $power_w];
    }

    /**
     * @throws Exception
     */
    public function skip_request($namespace, $class): bool
    { // returns whether to skip request to prevent throttling
        $class = $this->strip_namespace($namespace, $class);
        $sql = 'SELECT NOW() < `last_successful_request` + INTERVAL `min_minutes_since_last` MINUTE
                    FROM `requests`
                    WHERE `host` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $class) ||
            !$stmt->bind_result($skip_request) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $stmt->fetch();
        unset($stmt);
        $this->request_result($class, $skip_request = $skip_request ?? false);
        return $skip_request;
    }


    /**
     * @throws Exception
     */
    public function request_result($class, $skip_request): void
    {
        $sql = 'UPDATE `requests`
                   SET `last_request`            = NOW(),
                       `last_successful_request` = IF(?, `last_successful_request`, NOW())
                   WHERE `host` = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('is', $skip_request, $class) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    public function forecast_latest($entity, $datetime): float
    { // returns latest fresh forecast for datetime
        $sql = 'SELECT    `w1`.`value`
                  FROM    `values` `w1`
                  JOIN    (SELECT    `id`,  ABS(TIMESTAMPDIFF(SECOND, `datetime`, ?)) AS `diff`
                              FROM    `values`
                              WHERE   `entity` = ? AND
                                      `type`   = \'FORECAST\' AND
                                      `timestamp` > NOW() - INTERVAL ' . self::FORECAST_STALE_HOURS . ' HOUR
                              ORDER BY `diff`, `timestamp` DESC
                              LIMIT   0, 1) `w2` ON `w2`.`id` = `w1`.`id`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ss', $datetime, $entity) ||
            !$stmt->bind_result($value) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if (is_null($value)) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, "no $entity latest forecast found for datetime=$datetime"));
        } else {
            return $value;
        }
    }

    public function forecast_average_latest($entity, $start, $stop): float
    { // returns average of forecast points in slot since from
        $sql = 'SELECT    AVG(`value`) AS `value`
                   FROM   `values`
                   WHERE  `entity` = ? AND
                          `type`   = \'FORECAST\' AND
                          `datetime` BETWEEN ? AND ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sss', $entity, $start, $stop) ||
            !$stmt->bind_result($value) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if (is_null($value)) {
            throw new Exception($this->errMsg(__CLASS__, __FUNCTION__, __LINE__, "no $entity average latest forecast: start=$start, stop=$stop"));
        } else {
            return $value;
        }
    }

    protected function latestValueDatetime($entity, $type, $earliest_datetime): string
    {
        /*
         * returns latest datetime of value of entity, type - or earliest date if none
         */
        $sql = 'SELECT    MAX(`datetime`)
                    FROM  `values` 
                    WHERE `entity` = ? AND
                          `type`   = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ss', $entity, $type) ||
            !$stmt->bind_result($latest_value_datetime) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $stmt->fetch();
        return $latest_value_datetime ?? $earliest_datetime;
    }

    /**
     * @throws Exception
     */
    public function slots_make_cubic_splines(): void {
        $sql = 'SELECT      `n`.`slot`,
                            UNIX_TIMESTAMP(`n`.`start`)             AS `unix_timestamp`,
                            ROUND(`n`.`load_house_kw`, 3)           AS `load_house_kw`,
                            ROUND(`p`.`load_house_kw`, 3)           AS `previous_load_house_kw`,
                            ROUND(`n`.`grid_kw`, 3)                 AS `grid_kw`,
                            ROUND(`p`.`grid_kw`, 3)                 AS `previous_grid_kw`,
                            ROUND(`n`.`solar_kw`, 3)                AS `solar_kw`,
                            ROUND(`p`.`solar_kw`, 3)                AS `previous_solar_kw`,
                            ROUND(`n`.`battery_level_kwh`, 3)       AS `battery_level_kwh`,
                            ROUND(`p`.`battery_level_kwh`, 3)       AS `previous_battery_kwh`
                  FROM      `slots` `n`
                  LEFT JOIN (SELECT     `slot`,
                                        `start`,
                                        `load_house_kw`,
                                        `grid_kw`,
                                        `solar_kw`,
                                        `battery_level_kwh`
                                FROM    `slots`
                                WHERE   `final`) `p` ON `p`.`slot`+48 = `n`.`slot`
                  WHERE     `n`.`slot` >= 0 AND `n`.`final`
                  ORDER BY  `n`.`slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$slot] = [$unix_timestamp,  $load_house_kw,  $previous_load_house_kw, $grid_kw, $previous_grid_kw,   $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh];
        }
        $number_slots = count($slots);
        $number_slots_cubic_spline = $number_slots*self::CUBIC_SPLINE_MULTIPLE;
        $cubic_spline = new CubicSpline($number_slots_cubic_spline);
        $columns = ['unix_timestamp', 'load_house_kw', 'previous_load_kw', 'grid_kw', 'previous_grid_kw', 'solar_kw', 'previous_solar_kw', 'battery_level_kwh', 'previous_battery_level_kwh'];
        foreach ($columns as $index => $column) {
            $vector_cubic_splines = [];
            foreach ($slots as $slot => $column_values) {
                $vector_cubic_splines[$slot] = $column_values[$index];
            }
            if (!$index) { // generate time array
                $t_min = $slots[0][0];
                $t_max = $slots[$number_slots-1][0];
                $t_duration = $t_max - $t_min;
                for ($k=0; $k < $number_slots_cubic_spline; $k++) {
                    $slots_cubic_splines[$k][$index] = (int) round($t_min + $t_duration * ($k / ($number_slots_cubic_spline-1)));
                }
            }
            else {
                $vector_cubic_splines = $cubic_spline->cubic_spline_y($vector_cubic_splines);
                foreach ($vector_cubic_splines as $k => $v) {
                    $slots_cubic_splines[$k][$index] = round($vector_cubic_splines[$k], 3);
                }
            }
        }
        $sql = 'INSERT INTO `slots_cubic_splines` (`slot`,  `unix_timestamp`,   `load_house_kw`,     `previous_load_house_kw`,    `grid_kw`, `previous_grid_kw`, `solar_kw`, `previous_solar_kw`, `battery_level_kwh`, `previous_battery_level_kwh`) 
                                           VALUES (?,       ?,                  ?,                  ?,                          ?,          ?,                 ?,          ?,                      ?,                       ?                              )
                               ON DUPLICATE KEY UPDATE `unix_timestamp`                 = ?,
                                                       `load_house_kw`                  = ?,
                                                       `previous_load_house_kw`         = ?,
                                                       `grid_kw`                        = ?,
                                                       `previous_grid_kw`               = ?,
                                                       `solar_kw`                       = ?,
                                                       `previous_solar_kw`              = ?,
                                                       `battery_level_kwh`              = ?,
                                                       `previous_battery_level_kwh`     = ?';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('iiddddddddidddddddd', $slot, $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh,
                $unix_timestamp, $load_house_kw, $previous_load_house_kw, $grid_kw, $previous_grid_kw, $solar_kw, $previous_solar_kw, $battery_level_kwh, $previous_battery_level_kwh)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        foreach ($slots_cubic_splines as $slot => $slots_cubic_spline) {
            $unix_timestamp              = $slots_cubic_spline[0];
            $load_house_kw               = $slots_cubic_spline[1];
            $previous_load_house_kw      = $slots_cubic_spline[2];
            $grid_kw                     = $slots_cubic_spline[3];
            $previous_grid_kw            = $slots_cubic_spline[4];
            $solar_kw                    = $slots_cubic_spline[5];
            $previous_solar_kw           = $slots_cubic_spline[6];
            $battery_level_kwh           = $slots_cubic_spline[7];
            $previous_battery_level_kwh  = $slots_cubic_spline[8];
            $stmt->execute();
        }
        $this->mysqli->commit();
    }

    /**
     * @throws Exception
     */
    private function tariffCombinationsActiveFirst(): void
    {
        // select tariff combinations, active first
        $sql = 'SELECT     `tc`.`id`,
                            CONCAT(`ti`.`code`, \', \', `te`.`code`, IF(`tc`.`active` IS NULL, \'\', \' *ACTIVE*\')),
                           `tc`.`import`,
                           `tc`.`export`,
                           `tc`.`active`
                  FROM     `tariff_combinations` `tc`
                  JOIN     `tariff_imports` `ti` ON `tc`.`import` = `ti`.`id`
                  JOIN     `tariff_exports` `te` ON `tc`.`export` = `te`.`id`
                  WHERE    `tc`.`status` = \'CURRENT\' AND 
                            NOT `tc`.`ignore`
                  ORDER BY `tc`.`active` DESC';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($id, $name, $import, $export, $active) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $this->tariff_combinations = [];
        while ($stmt->fetch()) {
            $this->tariff_combinations[] = ['id' => $id,
                'name' => $name,
                'active' => $active,
                'import' => $import,
                'export' => $export];
        }
    }

    /**
     * @throws Exception
     */
    public function makeActiveTariffCombinationDbSlotsLast24hrs(): void {
        $tariff_combination_id = $this->tariff_combinations[0]['id'];
        $sql = 'SELECT `slot`  - 48,
                       `start` - INTERVAL 24 HOUR,
                       `stop`  - INTERVAL 24 HOUR
                  FROM `slots`
                  WHERE `slot` >= 1 AND
                         NOT `final`
                  ORDER BY `slot`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($slot, $start, $stop) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $slots = [];
        while ($stmt->fetch()) {
            $slots[$slot] = [
                'start' => $start,
                'stop' => $stop
            ];
        }
        $sql = 'INSERT IGNORE INTO `slots` (`slot`, `start`, `stop`)
                    SELECT `slot`  - 48,
                           `start` - INTERVAL 24 HOUR,
                           `stop`  - INTERVAL 24 HOUR
                      FROM `slots`
                      WHERE `slot` >= 1 AND
                            `tariff_combination` = ? AND
                             NOT `final`
                      ORDER BY `slot`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('s', $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        $values = new Values();
        foreach (self::ENTITIES as $entity => $properties) {
            foreach ($slots as $slot => $v) {       // returns averages for graphing purposes, about START times (i.e. slot_start_time - 15mins TO slot_start_time + 15mins
                $slots[$slot][$properties[0]] = $values->average($entity, 'MEASURED', $v['start'], $v['stop'], -DbSlots::SLOT_DURATION_MIN / 2) / $properties[1];
            }
            $sql = 'UPDATE  `slots` 
                      SET   `' . $properties[0] . '` = ?
                      WHERE `slot`            = ? AND
                            NOT `final`';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('di', $value, $slot)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
            foreach ($slots as $slot => $v) {
                if (!is_null($value = $v[$properties[0]])) {
                    $stmt->execute();
                }
            }
        }
        $this->mysqli->commit();
        $sql = 'INSERT INTO `slots` (`final`, `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_kw`, `mode`, `target_level_percent`, `abs_charge_power_w`, `battery_charge_kw`, `battery_level_kwh`, `battery_level_percent`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`) 
                       (SELECT      TRUE,     `tariff_combination`, `slot`, `start`, `stop`, `load_house_kw`, `grid_kw`, `solar_kw`, `mode`, `target_level_percent`, `abs_charge_power_w`, `battery_charge_kw`, `battery_level_kwh`, `battery_level_percent`, `import_gbp_per_kwh`, `export_gbp_per_kwh`, `import_gbp_per_day`, `export_gbp_per_day`, `load_non_heating_kw`, `load_heating_kw`
                                    FROM `slots` `s`
                                    WHERE NOT `final` AND
                                              `tariff_combination` IN (0, ?))  
                    ON DUPLICATE KEY UPDATE   `start`                     = `s`.`start`,
                                              `stop`                      = `s`.`stop`,
                                              `load_house_kw`             = `s`.`load_house_kw`, 
                                              `grid_kw`                   = `s`.`grid_kw`,
                                              `solar_kw`                  = `s`.`solar_kw`,
                                              `mode`                      = `s`.`mode`,
                                              `target_level_percent`      = `s`.`target_level_percent`,
                                              `abs_charge_power_w`        = `s`.`abs_charge_power_w`, 
                                              `battery_charge_kw`         = `s`.`battery_charge_kw`, 
                                              `battery_level_kwh`         = `s`.`battery_level_kwh`,
                                              `battery_level_percent`     = `s`.`battery_level_percent`,
                                              `import_gbp_per_kwh`        = `s`.`import_gbp_per_kwh`, 
                                              `export_gbp_per_kwh`        = `s`.`export_gbp_per_kwh`, 
                                              `import_gbp_per_day`        = `s`.`import_gbp_per_day`, 
                                              `export_gbp_per_day`        = `s`.`export_gbp_per_day`, 
                                              `load_non_heating_kw`       = `s`.`load_non_heating_kw`, 
                                              `load_heating_kw`           = `s`.`load_heating_kw`';
        unset($stmt);
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $tariff_combination_id) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, 'ERROR');
            throw new Exception($message);
        }
        if ($battery_capacity_kwh = $this->config['battery']['initial_raw_capacity_kwh']) {
            $sql = 'UPDATE  `slots`
                      SET   `battery_level_kwh` = ROUND(IFNULL(`battery_level_kwh`, ? * `battery_level_percent` /100.0),  1)
                      WHERE `final` AND 
                            `battery_level_percent` IS NOT NULL';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('d', $battery_capacity_kwh) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
            $sql = 'UPDATE  `slots`
                      SET   `battery_level_percent` = ROUND(IFNULL(`battery_level_percent`, 100.0 * `battery_level_kwh` / ?), 1)
                      WHERE `final` AND
                            `battery_level_kwh` IS NOT NULL';
            unset($stmt);
            if (!($stmt = $this->mysqli->prepare($sql)) ||
                !$stmt->bind_param('d', $battery_capacity_kwh) ||
                !$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, 'ERROR');
                throw new Exception($message);
            }
        }
        $this->mysqli->commit();
    }
}