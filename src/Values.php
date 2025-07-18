<?php

namespace Src;

use DateMalformedStringException;
use DateTime;
use Exception;

class Values extends Root
{

    const int   SLOT_DISTANCE_MAX               = 12,
                TEMPERATURE_DISTANCE_MAX        = 2,
                HISTORY_DAY_LIMIT               = 14,
                LATEST_AVERAGE_DURATION_MINUTES = 15;

    const float MAX_POWER_W                     = 7500.0,
                MIN_LIMIT_TEMPERATURE           = 2.0,
                MIN_POWER_W                     = 100.0,
                MAX_LIMIT_TEMPERATURE           = 21.0;

    private array $power_w, $tariff_combination;

    private mixed $db_slots;

    private float $solar_pv_inverter_power_threshold_kw;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        parent::__construct();
        $this->use_local_config();
        $this->solar_pv_inverter_power_threshold_kw = $this->config['solar_pv']['inverter']['power_threshold_kw'];
    }

    /**
     * @throws Exception
     */
    public function makeHeatingPowerLookupDaySlotExtTemp(): void { // writes heating powers to 2D array:  power_w[day_slot][temperature]
        $sql = "SELECT `day_slot_temp_c_heating_electric_w`.`day_slot`,
                       `day_slot_temp_c_heating_electric_w`.`temp_c`,
                       `day_slot_temp_c_heating_electric_w`.`heating_thermal_w`
                  FROM (SELECT    `th`.`temp_c`,
                                  `th`.`day_slot`,
                                  ROUND(AVG(`th`.`heating_thermal_w`)) AS `heating_thermal_w`,
                                  COUNT(`th`.`day_slot`) AS `count`
                           FROM (SELECT  `temp_c`.`day_slot` AS `day_slot`,
                                          `temp_c`.`value` AS `temp_c`,
                                          `heating_thermal_w`.`value` AS `heating_thermal_w`
                                    FROM (SELECT  DATE(`datetime`) AS `date`,
                                            2*HOUR(`datetime`)+FLOOR(MINUTE(`datetime`) / 30) AS `day_slot`,
                                            ROUND(AVG(`value`)) AS `value`
                                            FROM `values`
                                            WHERE `entity`   = 'TEMPERATURE_EXTERNAL_C' AND
                                                  `type`     = 'MEASURED' AND 
                                                  `datetime` > NOW() - INTERVAL 90 DAY AND
                                                  `not_setback`(`datetime`)
                                            GROUP BY `date`, `day_slot`
                                            ORDER BY `date`, `day_slot`) `temp_c`
                                    INNER JOIN (SELECT  DATE(`datetime`) AS `date`,
                                                        2*HOUR(`datetime`)+FLOOR(MINUTE(`datetime`) / 30) AS `day_slot`,
                                                        AVG(`value`) AS `value`
                                                  FROM `values`
                                                  WHERE `entity` = 'LOAD_HEATING_ELECTRIC_W' AND
                                                        `type`   = 'MEASURED'
                                                  GROUP BY `date`, `day_slot`
                                                  ORDER BY `date`, `day_slot`) `heating_thermal_w` ON `heating_thermal_w`.`date`     = `temp_c`.`date`     AND 
                                                                                                      `heating_thermal_w`.`day_slot` = `temp_c`.`day_slot`) `th`
                                      GROUP BY `th`.`temp_c`, `th`.`day_slot`
                                      ORDER BY `th`.`temp_c`, `th`.`day_slot`) `day_slot_temp_c_heating_electric_w`
                  WHERE `day_slot_temp_c_heating_electric_w`.`count` > 0
                  ORDER BY `day_slot`, `temp_c`";
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_result($day_slot, $temp_c, $power_w) ||
            !$stmt->execute()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        while ($stmt->fetch()) {
            $this->power_w[$day_slot][$temp_c] = $power_w;
        }
    }

    /**
     * @throws Exception
     */
    public function estimatePowers($db_slots, $tariff_combination): void
    {
        $this->db_slots           = $db_slots;
        $this->tariff_combination = $tariff_combination;
        $this->solarForecast();                                                                                                 // solar forecast
        $this->heatingEstimate();                                                                                               // estimate heating power for each slot based on historic performance
        $this->estimateLoadNonHeating();                                                                                        // calculate non_heating historic slots
        $this->totalLoad();                                                                                                     // total load
    }

    /**
     * @throws Exception
     */
    private function solarForecast(): void {                  // get average solar forecast for slots
        $powers_kw = [];
        foreach ($this->db_slots->slots as $slot => $v) {
            $start = $v['start'];
            $stop  = $v['stop'];
            if ($slot == 0) {
                $power_w = $this->average_latest('SOLAR_W', 'MEASURED', self::LATEST_AVERAGE_DURATION_MINUTES); // use average over past 15 mins for first slot
            }
            else {
                $power_w = $this->forecast_average_latest('SOLAR_W', $start, $stop);
            }
            $powers_kw[$slot] = round(min($power_w/1000.0, $this->solar_pv_inverter_power_threshold_kw), 3);       // clip solar generation to maximum power
        }
        $this->updateSlotPowerskW($powers_kw, 'solar_gross_kw');
    }

    public function average($entity, $type, $start, $stop, $offset_minutes): float
    {
        $sql = 'SELECT   IFNULL(AVG(`value`), 0.0)
                  FROM   `values`
                  WHERE  `entity` = ? AND
                         `type`   = ? AND
                         `datetime` BETWEEN (? + INTERVAL ? MINUTE) AND (? + INTERVAL ? MINUTE)';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('sssisi', $entity, $type, $start, $offset_minutes, $stop, $offset_minutes) ||
            !$stmt->bind_result($average) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        return $average;
    }

    public function average_latest($entity, $type, $minutes): ?float
    {
        $sql = 'SELECT   AVG(`value`)
                  FROM   `values`
                  WHERE  `entity` = ? AND
                         `type`   = ? AND
                         `datetime` BETWEEN (NOW() - INTERVAL ? MINUTE) AND NOW()';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ssi', $entity, $type, $minutes) ||
            !$stmt->bind_result($average) ||
            !$stmt->execute() ||
            !$stmt->fetch()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        return $average;
    }

    /**
     * @throws Exception
     */
    private function heatingEstimate(): void
    {  // estimate heating power for each slot based on historic performances at forecast temperatures
        $forecast_slots_start = new DateTime($this->db_slots->slots[0]['start']);
        $startOfDay = clone $forecast_slots_start;
        $startOfDay->setTime(0, 0);
        $interval = $startOfDay->diff($forecast_slots_start);
        $slots_offset = (2 * $interval->h) + ($interval->i / 30);
        $powers_kw = [];
        foreach ($this->db_slots->slots as $slot => $v) {
            $day_slot = ($slot + $slots_offset) % Slot::SLOTS_PER_DAY; // convert forecast slot number to absolute day slot
            $temperature_forecast_c = $this->forecast_latest('TEMPERATURE_EXTERNAL_C', $v['mid']);
            $power_kw = $this->electricLoadHeatingW($day_slot, $temperature_forecast_c) / 1000.0; // get average powers for this day slot at the forecast temperature
            $powers_kw[$slot] = $power_kw;
        }
        $this->updateSlotPowerskW($powers_kw, 'load_heating_kw');
    }

    private function electricLoadHeatingW(int $day_slot, float $temperature_c): ?float {
        $temperature_c = (int)round($temperature_c);
        if (isset($this->power_w[$day_slot][$temperature_c])) { // return exact if exists
            return $this->power_w[$day_slot][$temperature_c];
        } else {
            $interpolate_slots = $this->interpolate_slots($day_slot, $temperature_c);
            $interpolate_temperature = $this->interpolate_temperature($day_slot, $temperature_c);
            if (!is_null($interpolate_slots) && !is_null($interpolate_temperature)) {  // return average of both interpolations
                return ($interpolate_slots + $interpolate_temperature) / 2.0;
            } elseif (!is_null($interpolate_slots) && is_null($interpolate_temperature)) {
                return $interpolate_slots;
            } elseif (is_null($interpolate_slots) && !is_null($interpolate_temperature)) {
                return $interpolate_temperature;
            } else {   // cannot find past history, so use limit cases
                if ($temperature_c <= self::MIN_LIMIT_TEMPERATURE) {
                    return self::MAX_POWER_W;
                } elseif ($temperature_c >= self::MAX_LIMIT_TEMPERATURE) {
                    return self::MIN_POWER_W;
                } else {
                    return self::MAX_POWER_W + ((self::MIN_POWER_W - self::MAX_POWER_W) * ($temperature_c - self::MIN_LIMIT_TEMPERATURE) / (self::MAX_LIMIT_TEMPERATURE - self::MIN_LIMIT_TEMPERATURE));
                }
            }
        }
    }

    private function interpolate_slots(int $day_slot, float $temperature_c): ?float {
        $day_slot_nearest_lower = $day_slot;        // find nearest lower slot
        $day_slot_distance_nearest_lower = 0;
        do {
            $day_slot_distance_nearest_lower++;
            $day_slot_nearest_lower = $this->slot_decrement($day_slot_nearest_lower);
            if ($day_slot_distance_nearest_lower > self::SLOT_DISTANCE_MAX) {
                return null;
            }
        } while (!isset($this->power_w[$day_slot_nearest_lower][$temperature_c]));
        $power_nearest_lower = $this->power_w[$day_slot_nearest_lower][$temperature_c];
        $slot_nearest_higher = $day_slot;        // find nearest higher slot
        $slot_distance_nearest_higher = 0;
        do {
            $slot_nearest_higher = $this->slot_increment($slot_nearest_higher);
            if ($slot_distance_nearest_higher++ > self::SLOT_DISTANCE_MAX) {
                return null;
            }
        } while (!isset($this->power_w[$slot_nearest_higher][$temperature_c]));
        $power_nearest_higher = $this->power_w[$slot_nearest_higher][$temperature_c];
        return $power_nearest_lower + ($power_nearest_higher - $power_nearest_lower) * (((float)$day_slot_distance_nearest_lower) / ((float)($day_slot_distance_nearest_lower + $slot_distance_nearest_higher)));
    }

    private function interpolate_temperature(int $slot, $temperature): ?float {
        $temperature_nearest_lower = $temperature;        // find nearest lower temperature
        $temperature_distance_nearest_lower = 0;
        do {
            $temperature_distance_nearest_lower++;
            $temperature_nearest_lower--;
            if ($temperature_distance_nearest_lower > self::TEMPERATURE_DISTANCE_MAX) {
                return null;
            }
        } while (!isset($this->power_w[$slot][$temperature]));
        $power_nearest_lower = $this->power_w[$slot][$temperature];
        $temperature_distance_nearest_higher = 0;
        do {
            if ($temperature_distance_nearest_higher++ > self::TEMPERATURE_DISTANCE_MAX) {
                return null;
            }
        } while (!isset($this->power_w[$slot][$temperature]));
        $power_nearest_higher = $this->power_w[$slot][$temperature];
        return $power_nearest_lower + ($power_nearest_higher - $power_nearest_lower) * (((float)$temperature_nearest_lower) / ((float)($temperature_distance_nearest_lower + $temperature_distance_nearest_higher)));
    }

    private function slot_decrement(int $slot): int
    {
        $slot--;
        if ($slot < 0) {
            return Slot::SLOTS_PER_DAY - 1;
        } else {
            return $slot;
        }
    }

    private function slot_increment(int $slot): int
    {
        $slot++;
        return $slot % Slot::SLOTS_PER_DAY;
    }

    private function updateSlotPowerskW($powers_kw, $column): void
    {
        $sql = 'UPDATE   `slots` 
                   SET   ' . $column . ' = ?
                   WHERE `slot` = ? AND
                         `tariff_combination` = ? AND
                         NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('dii', $power_kw, $slot, $this->tariff_combination['id'])) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        foreach ($powers_kw as $slot => $power_kw) {
            if (!$stmt->execute()) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
        }
        $this->mysqli->commit();
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     */
    private function powersKwSlotAverage($entity, $type, $history_day_limit): array
    {
        $sql = 'SELECT      AVG(`value`)/1000.0
                  FROM      `values`
                  WHERE     `entity` = ? AND
                            `type`   = ? AND
                            (60*HOUR(`datetime`) + MINUTE(`datetime`)) BETWEEN ? AND ? AND
                            `datetime` > NOW() - INTERVAL ? DAY AND
                            `not_setback`(`datetime`)';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('ssiii', $entity, $type, $start_minutes, $stop_minutes, $history_day_limit) ||
            !$stmt->bind_result($power_kw)) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
        $powers_kw = [];
        foreach ($this->db_slots->slots as $slot => $v) {
            $datetime_start = new DateTime($v['start']);
            $start_hour = (int)$datetime_start->format('H');
            $start_minute = (int)$datetime_start->format('i');
            $start_minutes = $start_minute + (60 * $start_hour);
            $stop_minutes = $start_minutes + Slot::DURATION_MINUTES;
            if (!$stmt->execute() ||
                !$stmt->fetch() ||
                is_null($power_kw)) {
                $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                $message =  'entity:'            . $entity             . ', ' .
                            'type:'              . $type               . ', ' .
                            'start_minutes:'     . $start_minutes      . ', ' .
                            'stop_minutes:'      . $stop_minutes       . ', ' .
                            'history_day_limit:' . $history_day_limit;
                $this->logDb('MESSAGE', $message, null, 'ERROR');
                throw new Exception($message);
            }
            $powers_kw[$slot] = $power_kw;
        }
        return $powers_kw;
    }

    /**
     * @throws Exception
     */
    private function estimateLoadNonHeating(): void
    {
        /*
         *  estimate non-heating load from average difference between total and heating load in recent past
         */
        $powers_average_load_house_kw               = $this->powersKwSlotAverage('LOAD_HOUSE_W',            'MEASURED', self::HISTORY_DAY_LIMIT);
        $powers_average_load_heating_electric_kw    = $this->powersKwSlotAverage('LOAD_HEATING_ELECTRIC_W', 'MEASURED', self::HISTORY_DAY_LIMIT);
        $average_powers_load_non_heating_kw = [];
        foreach ($this->db_slots->slots as $slot => $v) {
            $average_load_house_kw = $powers_average_load_house_kw[$slot];
            $average_load_heating_electric_kw = $powers_average_load_heating_electric_kw[$slot];
            $average_powers_load_non_heating_kw[$slot] = $average_load_house_kw - $average_load_heating_electric_kw; // - $average_load_ev_kw;
        }
        $this->updateSlotPowerskW($average_powers_load_non_heating_kw, 'load_non_heating_kw');
    }

    /**
     * @throws Exception
     */
    private function totalLoad(): void
    {
        $sql = 'UPDATE      `slots`
                  SET       `load_house_kw` = `load_non_heating_kw` + `load_heating_kw`
                  WHERE     `tariff_combination` = ? AND
                            NOT `final`';
        if (!($stmt = $this->mysqli->prepare($sql)) ||
            !$stmt->bind_param('i', $this->tariff_combination['id']) ||
            !$stmt->execute() ||
            !$this->mysqli->commit()) {
            $message = $this->sqlErrMsg(__CLASS__, __FUNCTION__, __LINE__, $this->mysqli, $sql);
            $this->logDb('MESSAGE', $message, null, 'ERROR');
            throw new Exception($message);
        }
    }
}