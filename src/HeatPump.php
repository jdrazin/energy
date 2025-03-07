<?php

namespace Src;

use Energy;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";
require_once __DIR__ . "/ThermalTank.php";

class HeatPump extends Component
{

    public float $power_background_w, $power_max_w, $heat, $cool, $max_output_j, $performance_factor, $energy_background_step_j;
    public array $cops, $kwh;

    public ThermalTank $thermal_sink;

    public function __construct($config, $time, $npv)
    {
        parent::__construct($config, $time, $npv);
        if ($this->active) {
            $this->cops = $config['cops'];
            ksort($this->cops);                                                             // ensure cops data are in temperature order
            $this->max_output_j = 1000 * ($config['output_kw'] ?? 0.0) * $this->step_s;
            $this->performance_factor = ($config['performance_factor'] ?? 1.0);
            $this->energy_background_step_j = ($config['power_background_w'] ?? 0.0) * $this->step_s;
            $this->heat = ($config['heat'] ?? true);
            $this->cool = ($config['cool'] ?? false);
            $this->kwh = $this->zeroKwh();
            $this->time_units = $time->units;
        }
    }

    public function transfer_consume_j($request_transfer_consume_j, $temp_delta_c, $time): array
    {
        if (!$this->active || !$request_transfer_consume_j) {
            $transfer_consume_j = ['transfer' => 0.0,
                'consume' => 0.0];
        } else {
            $cop = $this->cop($temp_delta_c);
            $transfer_cap_j = min($request_transfer_consume_j, $this->max_output_j);        // cap transfer at heatpump capacity
            $consumed_cap_j = $this->energy_background_step_j + ($transfer_cap_j / $cop);
            $transfer_consume_j = ['transfer' => $transfer_cap_j,
                'consume' => $consumed_cap_j];
        }
        $this->transferConsumeUpdate($transfer_consume_j, $time);
        return $transfer_consume_j;
    }

    public function cop($temperature_delta_c): float
    {
        $temperature_delta_c = abs($temperature_delta_c);
        $first = true;
        foreach ($this->cops as $temp_delta_c => $cop) {
            if ($first) {
                $temp_lo = (float)$temp_delta_c;
                $cop_lo = (float)$cop;
                $first = false;
            }
            $element_cop = (float)$cop;
            $element_temp = (float)$temp_delta_c;
            if ($element_temp > $temperature_delta_c) {
                $dividend = ($temperature_delta_c - $temp_lo) * ($element_cop - $cop_lo);
                $divisor = $element_temp - $temp_lo;
                return 1.0 + ($this->performance_factor * (($cop_lo + ($dividend / $divisor)) - 1.0));
            }
            $temp_lo = $element_temp;
            $cop_lo = $element_cop;
        }
        return $cop;
    }

    public function zeroKwh(): array
    {
        $array = [];
        foreach ($this->time_units as $time_unit => $number_unit_values) {
            $array[$time_unit] = [];
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $array[$time_unit][$time_unit_value] = ['transfer_kwh' => 0.0,
                    'consume_kwh' => 0.0];
            }
        }
        return $array;
    }

    public function transferConsumeUpdate($transfer_consume_j, $time): void
    {
        $time_values = $time->values();
        $kwh = [];
        $kwh['transfer_kwh'] = $transfer_consume_j['transfer'] / \Src\Energy::JOULES_PER_KWH;
        $kwh['consume_kwh'] = $transfer_consume_j['consume'] / \Src\Energy::JOULES_PER_KWH;
        foreach ($this->time_units as $time_unit => $number_unit_values) {
            $time_value = $time_values[$time_unit];
            $t = $this->kwh[$time_unit][$time_value];
            foreach ($t as $key => $value) {
                $t[$key] += $kwh[$key];
            }
            $this->kwh[$time_unit][$time_value] = $t;
        }
    }
}