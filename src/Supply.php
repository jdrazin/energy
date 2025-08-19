<?php

namespace Src;

use Exception;

require_once __DIR__ . "/Energy.php";

class Supply extends Component
{
    const string COMPONENT_NAME = 'energy';
    const array CHECKS = [
        'tariffs'               =>  [
                                    'array' => null
                                    ],
        'months'               =>   [
                                    'array' => null
                                    ],
        'inflation_real_pa'     =>  [
                                    'range' => [0.0, 1.0]
                                    ],
        'limit_kw'              =>  [
                                    'range' => [0.0, 100.0]
                                    ],
        'gbp_per_day'  => [
                                   'range' => [0.0, 100.0]
                                   ],
        'hours'                 => [
                                    'hour_bands' => ['off_peak', 'standard', 'peak']
                                    ],
        'bands_gbp_per_kwh'     => [
                                    'tag_numbers' => [0.0, 100.0],
                                    'bands_key'   => ['off_peak', 'standard', 'peak']
                                    ]
    ];
    const array DIRECTIONS = ['import' => +1.0,
                              'export' => +1.0];
    public string $type;
    public float $inflation_real_pa;
    public array $directions, $tariffs, $tariff, $month_tariff_keys, $current_bands, $kwh, $value_gbp;

    /**
     * @throws Exception
     */
    public function __construct($check, $config, $supply_name, $time) {
        parent::__construct($check, $config, self::COMPONENT_NAME, $time);
        $this->include = true;
        $this->directions = self::DIRECTIONS;
        $this->inflation_real_pa = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name], 'inflation_real_pa', self::CHECKS);
        $tariffs = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name], 'tariffs', self::CHECKS);
        $this->month_tariff_keys = [];
        foreach ($tariffs as $key => $tariff) {
            $tariff = [];
            $months = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key], 'months', self::CHECKS);
            // allocate tariff keys to months
            foreach ($months as $month) {
                if (!is_int($month) || $month < 1 || $month > Energy::TIME_UNITS['MONTH_OF_YEAR']) {
                    throw new Exception(self::COMPONENT_NAME . ' ' . $supply_name . 'month ' . $month . ' must be a month number between 1 and 12');
                }
                elseif (isset($this->month_tariff_keys[$month])) {
                    throw new Exception(self::COMPONENT_NAME . ' \'' . $supply_name . '\' month ' . $month . ' is duplicated');
                }
                else {
                    $this->month_tariff_keys[$month] = $key;
                }
            }
            $hours =             $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'import'], 'hours',             self::CHECKS);
            $bands_gbp_per_kwh = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'import'], 'bands_gbp_per_kwh', self::CHECKS);
            $tariff['import'] = [
                                'hours'             => $hours,
                                'bands_gbp_per_kwh' => $bands_gbp_per_kwh
                                ];
            if ($supply_name == 'grid') {
                $gbp_per_day = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key], 'gbp_per_day', self::CHECKS);
                $tariff['import']['limit_kw'] = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'import'], 'limit_kw', self::CHECKS);
                $hours =             $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'export'], 'hours',             self::CHECKS);
                $bands_gbp_per_kwh = $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'export'], 'bands_gbp_per_kwh', self::CHECKS);
                $tariff['export'] = [
                    'hours'             => $hours,
                    'bands_gbp_per_kwh' => $bands_gbp_per_kwh,
                    'limit_kw'          => $check->checkValue($config, self::COMPONENT_NAME, [$supply_name, 'tariffs', $key, 'export'], 'limit_kw',          self::CHECKS)
                ];
            }
            elseif ($supply_name == 'boiler') {
                $gbp_per_day = 0.0;
                unset($this->directions['export']); // import only
            }
            else {
                throw new Exception(self::COMPONENT_NAME . ' ' . $supply_name . 'month ' . $month . ' is duplicated');
            }
            $tariff['gbp_per_day'] = $gbp_per_day;
            $tariff['value_per_timestep_gbp'] = $gbp_per_day * $this->step_s / (Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
            $component = $config['energy'];
            foreach ($this->directions as $direction => $factor) {                                     // run through import-export tariffs
                $bands = [];
                $band_hours = $this->bandHours($tariff[$direction]);
                $bands_gbp_per_kwh = $component[$supply_name]['tariffs'][$key][$direction]['bands_gbp_per_kwh'];
                foreach ($bands_gbp_per_kwh as $band => $rate) {
                    $bands[] = $band;
                }
                for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
                    $band = $band_hours[$hour];
                    $tariff[$direction][$hour] = ['band'          => $band,
                                                  'gbp_per_kwh'   => $factor * $bands_gbp_per_kwh[$band]];
                }
                $tariff['tariff_bands'][$direction] = $bands; // [ 0 => 'tag0', ... n => 'tagN''
            }
            $this->kwh              = $this->zeroTimeDirectionBandArray($time);
            $this->value_gbp        = $this->zeroTimeDirectionBandArray($time);
            $this->tariffs[$key]    = $tariff;
        }
        // check tariff key allocated to each month
        for ($month = 1; $month <= Energy::TIME_UNITS['MONTH_OF_YEAR']; $month++) {
            if (!isset($this->month_tariff_keys[$month])) {
                throw new Exception(self::COMPONENT_NAME . ' \'' . $supply_name . '\' month ' . $month . ' has no tariff');
            }
        }
    }

    private function bandHours($tariff): array {
        $bands = $tariff['bands_gbp_per_kwh'];
        $hour_bands = $tariff['hours'];
        $band = key($bands);
        $band_hours = [];
        for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
            if (isset($hour_bands[$hour])) {
                $band = $hour_bands[$hour];
            }
            $band_hours[$hour] = $band;
        }
        return $band_hours;
    }

    public function updateTariff($time): void {  // get tariff for this time
        $key  = $this->month_tariff_keys[$time->month()]; // get tariff key for month
        $hour = (int)(Energy::HOURS_PER_DAY * $time->fraction_day);
        $this->tariff = $this->tariffs[$key];
        foreach ($this->directions as $direction => $factor) { // return bands for each direction
            $this->current_bands[$direction] = $this->tariff[$direction][$hour]['band'];
        }
    }

    function transferTimestepConsumeJ($time, $energy_j): void {
        $direction  = $energy_j <= 0.0 ? 'import' : 'export';
        $supply_kwh = (float)($energy_j / Energy::JOULES_PER_KWH);
        $inflation  = (1.0 + $this->inflation_real_pa) ** (((float)$time->year) + $time->fraction_year);
        $value_gbp  = $inflation * (($supply_kwh * ($tariff = $this->tariff[$direction][$time->values['HOUR_OF_DAY']]['gbp_per_kwh'])) + $this->tariff['value_per_timestep_gbp']);
        $this->npv->valueGbp($time, $value_gbp);
        foreach ($time->values as $time_unit => $value) {
            $this->kwh[$time_unit][$value][$direction] += $supply_kwh;
            $this->value_gbp [$time_unit][$value][$direction] += $value_gbp;
        }
    }

    public function zeroTimeDirectionBandArray($time): array { //  $array[TIME_UNIT][TIME][DIRECTION]
        $array = [];
        foreach ($time->units as $time_unit => $number_unit_values) {
            $array[$time_unit] = [];
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $array[$time_unit][$time_unit_value] = [];
                foreach (self::DIRECTIONS as $direction => $factor) {
                    $array[$time_unit][$time_unit_value][$direction] = 0.0;
                }
            }
        }
        return $array;
    }

    public function sumTimeDirectionBandArray($array, $time): array { //  $array[TIME_UNIT][TIME][DIRECTION]
        foreach ($time->units as $time_unit => $number_unit_values) {
            $sum_time_unit = 0.0;
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $sum_direction = 0.0;
                foreach (self::DIRECTIONS as $direction => $factor) {
                }
                $array[$time_unit][$time_unit_value]['sum'] = $sum_direction;
                $sum_time_unit += $sum_direction;
            }
            $array[$time_unit]['sum'] = $sum_time_unit;
        }
        return $array;
    }

    public function sum($time): void
    {
        $this->kwh = $this->sumTimeDirectionBandArray($this->kwh, $time);
        $this->value_gbp = $this->sumTimeDirectionBandArray($this->value_gbp, $time);
    }
}