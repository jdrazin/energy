<?php
namespace Src;

class HeatPump extends Component
{

    public float $heat, $cool, $max_output_j, $energy_background_step_j, $scop;
    public array $cops, $kwh;

    public function __construct($component, $time)
    {
        parent::__construct($component, $time);
        if ($this->include) {
            $this->cops = $component['cops'];
            ksort($this->cops);  // ensure cops data are in temperature order
            $power = $component['power'];
            $this->max_output_j = 1000 * ($power['output_kw'] ?? 0.0) * $this->step_s;
            $this->energy_background_step_j = ($power['background_w'] ?? 0.0) * $this->step_s;
            $this->heat = ($component['heat'] ?? true);
            $this->cool = ($component['cool'] ?? false);
            $this->kwh = $this->zeroKwh();
            $this->time_units = $time->units;
        }
    }

    public function transfer_consume_j($request_transfer_consume_j, $temp_delta_c, $time, $cop_factor): array
    {
        if (!$this->include || !$request_transfer_consume_j) {
            $transfer_consume_j = ['transfer' => 0.0,
                                   'consume'  => 0.0];
        } else {
            $cop = $this->cop($temp_delta_c)*$cop_factor;
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
                $divisor  = $element_temp - $temp_lo;
                return $cop_lo + ($dividend / $divisor);
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
        $kwh['transfer_kwh'] = $transfer_consume_j['transfer'] / Energy::JOULES_PER_KWH;
        $kwh['consume_kwh'] = $transfer_consume_j['consume'] / Energy::JOULES_PER_KWH;
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