<?php

namespace Src;

require_once __DIR__ . "/Climate.php";
require_once __DIR__ . "/Energy.php";

class Demand extends Check
{
    const string COMPONENT_NAME = 'demands';
    const array CHECKS = [
        'type'                          => [
                                            'values' => ['climate_heating', 'fixed']
                                           ],
        'total_annual_kwh'              => [
                                            'range' => [1, 1000000]
                                           ],
        'target_circadian_phase_lag_hours'              => [
                                            'range' => [0, 12]
                                           ],
        'hourly_consumption_weightings' => [
                                            'hourly' => null,
                                           ]
                           ];

    public string $type;
    public float $consumption_rates_sum;
    public array $hour_demands_j;

    /**
     * @throws \Exception
     */
    public function __construct($config, $demand, $internal_room_c)
    {
        $this->type                    = $this->checkValue($config, $demand, self::COMPONENT_NAME, 'type',                          self::CHECKS);
        $hourly_consumption_weightings = $this->checkValue($config, $demand, self::COMPONENT_NAME, 'hourly_consumption_weightings', self::CHECKS);
        $total_annual_kwh              = $this->checkValue($config, $demand, self::COMPONENT_NAME, 'total_annual_kwh',              self::CHECKS);
        $total_daily_kwh               = $total_annual_kwh / Energy::DAYS_PER_YEAR;
        $this->hour_demands_j = [];
        switch ($this->type) {
            case 'fixed':
            {
                $this->consumption_rates_sum = 0.0;
                $consumption_rate = 0.0;
                for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
                    $this->consumption_rates_sum += ($consumption_rate = $hourly_consumption_weightings[$hour] ?? $consumption_rate);
                }
                $consumption_rate = 0.0;
                for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
                    $this->hour_demands_j[$hour] = Energy::JOULES_PER_KWH * $total_daily_kwh * ($consumption_rate = $hourly_consumption_weightings[$hour] ?? $consumption_rate) / $this->consumption_rates_sum;
                }
                break;
            }
            case 'climate_heating': { // heating linearly proportional to positive difference between target temperature and phase adjusted climate temperature
                $target_space_temperature_c = $internal_room_c;
                $target_circadian_phase_lag_hours = $this->checkValue($config, $demand, self::COMPONENT_NAME, 'target_circadian_phase_lag_hours', self::CHECKS);
                $energy_j_cumulative = 0.0;
                for ($day = 0; $day < Energy::DAYS_PER_YEAR; $day++) {
                    $this->hour_demands_j[$day] = [];
                    $fraction_year = $day / Energy::DAYS_PER_YEAR;
                    $heating = false;
                    for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
                        if (isset($hourly_consumption_weightings[$hour])) {                        // target temperature required?
                            $heating = (bool)$hourly_consumption_weightings[$hour];
                        }
                        if ($heating) {
                            $fraction_day = ((float)$hour - (float)$target_circadian_phase_lag_hours) / Energy::HOURS_PER_DAY;
                            $climate_temperature_c = (new Climate)->temperature_fraction($fraction_year, $fraction_day);
                            $hour_demand = $target_space_temperature_c > $climate_temperature_c ? $target_space_temperature_c - $climate_temperature_c : 0.0;
                        } else {
                            $hour_demand = 0.0;
                        }
                        $this->hour_demands_j[$day][$hour] = $hour_demand;
                        $energy_j_cumulative += $hour_demand;
                    }
                }
                // normalise
                // $annual_j = 0.0;
                $normalising_coefficient = Energy::JOULES_PER_KWH * $total_annual_kwh / $energy_j_cumulative;
                for ($day = 0; $day < Energy::DAYS_PER_YEAR; $day++) {
                    for ($hour = 0; $hour < Energy::HOURS_PER_DAY; $hour++) {
                        $this->hour_demands_j[$day][$hour] *= $normalising_coefficient;
                        //		$annual_j += $this->hour_demands_j[$day][$hour];
                    }
                }
                // $annual_kwh = $annual_j / Energy::JOULES_PER_KWH;
                break;
            }
            default:
            {
            }
        }
    }

    public function demand_j($time): float
    {
        $hour = (int)(Energy::HOURS_PER_DAY * $time->fraction_day);
        switch ($this->type) {
            case 'fixed':
            {
                $hour_demand_j = $this->hour_demands_j[$hour];
                break;
            }
            case 'climate_heating':
            {
                $day = (int)(Energy::DAYS_PER_YEAR * $time->fraction_year);
                $hour_demand_j = $this->hour_demands_j[$day][$hour];
                break;
            }
            default:
            {
                $hour_demand_j = 0.0;
            }
        }
        return $hour_demand_j * $time->step_s / ((float)Energy::SECONDS_PER_HOUR);
    }
}