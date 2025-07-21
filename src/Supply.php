<?php


namespace Src;

use Energy;

require_once __DIR__ . "/Energy.php";

class Supply extends Component
{
    const array DIRECTIONS = ['import' => +1.0,
        'export' => +1.0];
    public string $type;
    public float $inflation_real_pa, $export_limit_kw;
    public array $tariff, $current_bands, $tariff_bands, $kwh, $value_gbp;

    function __construct($component, $time)
    {
        parent::__construct($component, $time);
        $this->type = $component['type'] ?? '- no type -';
        $this->inflation_real_pa = $component['inflation_real_pa'] ?? 0.0;
        $this->export_limit_kw = $component['export']['limit_kw'] ?? 0.0;
        $this->make_tariff($component);
        $this->kwh = $this->zero_time_direction_band_array($time);
        $this->value_gbp = $this->zero_time_direction_band_array($time);
    }

    private function make_tariff($config): void
    {
        foreach (self::DIRECTIONS as $direction => $factor) {                                     // run through import-export tariffs
            $bands = [];
            if ($tariff_direction = $config[$direction] ?? []) {
                $band_hours = $this->band_hours($tariff_direction);
                $bands_gbp_per_kwh = $config[$direction]['bands_gbp_per_kwh'];
                foreach ($bands_gbp_per_kwh as $band => $rate) {
                    $bands[] = $band;
                }
                for ($hour = 0; $hour < \Src\Energy::HOURS_PER_DAY; $hour++) {
                    $band = $band_hours[$hour];
                    $this->tariff[$direction][$hour] = ['band' => $band,
                        'gbp_per_kwh' => $factor * $bands_gbp_per_kwh[$band]];
                }
            }
            $this->tariff_bands[$direction] = $bands;
        }
    }

    private function band_hours($tariff): array
    {
        $bands = $tariff['bands_gbp_per_kwh'];
        $hour_bands = $tariff['hours'];
        $band = key($bands);
        $band_hours = [];
        for ($hour = 0; $hour < \Src\Energy::HOURS_PER_DAY; $hour++) {
            if (isset($hour_bands[$hour])) {
                $band = $hour_bands[$hour];
            }
            $band_hours[$hour] = $band;
        }
        return $band_hours;
    }

    public function update_bands($time): void
    { // return band for direction
        $hour = (int)(\Src\Energy::HOURS_PER_DAY * $time->fraction_day);
        foreach (self::DIRECTIONS as $direction => $factor) {
            if (isset($this->tariff[$direction])) {
                $this->current_bands[$direction] = $this->tariff[$direction][$hour]['band'];
            }
        }
    }

    function transfer_consume_j($time, $direction, $energy_j): void
    {
        $time_values = $time->values();
        $hour_of_day = $time_values['HOUR_OF_DAY'];
        $supply_kwh = (float)($energy_j / \Src\Energy::JOULES_PER_KWH);
        $inflation = (1.0 + $this->inflation_real_pa) ** (((float)$time->year) + $time->fraction_year);
        $rate_gbp_per_kwh = $this->tariff[$direction][$hour_of_day]['gbp_per_kwh'];
        $value_gbp = $supply_kwh * $inflation * $rate_gbp_per_kwh;
        $this->npv->value_gbp($time, $value_gbp);
        $current_band = $this->current_bands[$direction];
        foreach ($time_values as $time_unit => $value) {
            $this->kwh[$time_unit][$value][$direction][$current_band] += $supply_kwh;
            $this->value_gbp [$time_unit][$value][$direction][$current_band] += $value_gbp;
        }
    }

    public function zero_time_direction_band_array($time): array
    { //  $array[TIME_UNIT][TIME][DIRECTION][BAND]
        $array = [];
        foreach ($time->units as $time_unit => $number_unit_values) {
            $array[$time_unit] = [];
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $array[$time_unit][$time_unit_value] = [];
                foreach (self::DIRECTIONS as $direction => $factor) {
                    foreach ($this->tariff_bands[$direction] as $band) {
                        $array[$time_unit][$time_unit_value][$direction][$band] = 0.0;
                    }
                }
            }
        }
        return $array;
    }

    public function sum_time_direction_band_array($array, $time): array
    { //  $array[TIME_UNIT][TIME][DIRECTION][BAND]
        foreach ($time->units as $time_unit => $number_unit_values) {
            $sum_time_unit = 0.0;
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $sum_direction = 0.0;
                foreach (self::DIRECTIONS as $direction => $factor) {
                    $sum_band = 0.0;
                    foreach ($this->tariff_bands[$direction] as $band) {
                        $sum_band += $array[$time_unit][$time_unit_value][$direction][$band];
                    }
                    $array[$time_unit][$time_unit_value][$direction]['sum'] = $sum_band;
                    $sum_direction += $sum_band;
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
        $this->kwh = $this->sum_time_direction_band_array($this->kwh, $time);
        $this->value_gbp = $this->sum_time_direction_band_array($this->value_gbp, $time);
    }
}