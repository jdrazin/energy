<?php
namespace Src;

use Exception;

class HeatPump extends Component
{
    const string COMPONENT_NAME = 'heat_pump';
    const array CHECKS = [  'battery'             => ['array'             => null             ],
                            'include'             => ['boolean'           => null             ],
                            'scop'                => ['range'             => [1.0,      10.0] ],
                            'cops'                => ['temperature_cops'  => null             ],
                            'internal_temp_max_c' => ['range'             => [ 20.0,     25.0]],
                            'outside_temp_min_c'  => ['range'             => [-25.0,      5.0]],
                            'power'               => ['array'             => null             ],
                            'output_kw'           => ['range'             => [0.5,     100.0] ],
                            'background_w'        => ['range'             => [0.0,    1000.0] ],
                            'heat'                => ['boolean'           => null             ],
                            'cool'                => ['boolean'           => null             ],],
                DEFAULT_COPS = [   0 =>  5.1,
                                   5 =>  5.0,
                                  10 =>  4.9,
                                  20 =>  4.5,
                                  30 =>  4.0,
                                  40 =>  3.0,
                                  50 =>  2.0,
                                  60 =>  1.5,
                                  70 =>  1.2,
                                  80 =>  1.1,
                                  90 =>  1.0,
                                 100 =>  0.95];
    const float     DEFAULT_INSIDE_TEMP_MAX_C  =  21.0,
                    DEFAULT_OUTSIDE_TEMP_MIN_C = -5.0;

    public float $scop, $cop_factor, $heat, $cool, $max_output_w, $max_output_j, $energy_background_step_j, $internal_temp_max_c, $outside_temp_min_c, $temp_delta_max_c;
    public array $cops, $kwh;

    public function __construct($check, $config, $time)
    {
        if ($this->include = $check->checkValue($config, self::COMPONENT_NAME, [], 'include', self::CHECKS)) {
            parent::__construct($check, $config, self::COMPONENT_NAME, $time);
            $this->scop = $check->checkValue($config, self::COMPONENT_NAME, [], 'scop', self::CHECKS, 1.0);
            $this->sumCosts($check->checkValue($config, self::COMPONENT_NAME, [], 'cost', self::CHECKS));
            $this->cops = $check->checkValue($config, self::COMPONENT_NAME, ['design'], 'cops', self::CHECKS, self::DEFAULT_COPS);
            $this->internal_temp_max_c = $check->checkValue($config, self::COMPONENT_NAME, ['design'], 'internal_temp_max_c', self::CHECKS, self::DEFAULT_INSIDE_TEMP_MAX_C);
            $this->outside_temp_min_c  = $check->checkValue($config, self::COMPONENT_NAME, ['design'], 'outside_temp_min_c',  self::CHECKS, self::DEFAULT_OUTSIDE_TEMP_MIN_C);
            $this->temp_delta_max_c    = $this->internal_temp_max_c - $this->outside_temp_min_c;
            ksort($this->cops);  // ensure cops data are in temperature order
            $check->checkValue($config, self::COMPONENT_NAME, [], 'power', self::CHECKS);
            $this->max_output_w = 1000.0 * $check->checkValue($config, self::COMPONENT_NAME, ['power'], 'output_kw', self::CHECKS);
            $this->max_output_j = $this->max_output_w * $this->step_s;
            $this->energy_background_step_j = $check->checkValue($config, self::COMPONENT_NAME, ['power'], 'background_w', self::CHECKS, 0.0) * $this->step_s;
            $this->heat = $check->checkValue($config, self::COMPONENT_NAME, [], 'heat', self::CHECKS, true);
            $this->cool = $check->checkValue($config, self::COMPONENT_NAME, [], 'cool', self::CHECKS, false);
            $this->kwh = $this->zeroKwh();
            $this->time_units = $time->units;
        }
    }

    /**
     * @throws Exception
     */
    public function transferConsumeJ($request_transfer_consume_j, $temp_delta_c, $time): array {
        if (!$this->include || !$request_transfer_consume_j) {
            $transfer_consume_j = ['transfer' => 0.0,
                                   'consume'  => 0.0];
        } else {
            $cop = $this->cop($temp_delta_c)*$this->cop_factor;
            $transfer_cap_j = min($request_transfer_consume_j, $this->max_output_j);        // cap transfer at heatpump capacity
            $consumed_cap_j = $this->energy_background_step_j + ($transfer_cap_j / $cop);
            $transfer_consume_j = ['transfer' => $transfer_cap_j,
                                    'consume' => $consumed_cap_j];
        }
        $this->transferConsumeUpdate($transfer_consume_j, $time);
        return $transfer_consume_j;
    }

    /**
     * @throws Exception
     */
    public function cop($temperature_delta_c): float {
        $temp_lo             = $cop_lo = null;
        $temperature_delta_c = abs($temperature_delta_c);
        $first               = true;
        foreach ($this->cops as $temp_delta_c => $cop) {  // linearly interpolate cop between adjacent values
            if ($first) {
                $temp_lo = (float) $temp_delta_c;
                $cop_lo  = (float) $cop;
                $first   = false;
            }
            $element_cop  = (float) $cop;
            $element_temp = (float) $temp_delta_c;
            if ($element_temp > $temperature_delta_c) {
                if (is_null($temp_lo) || is_null($cop_lo)) {
                    break;
                }
                $dividend = ($temperature_delta_c - $temp_lo) * ($element_cop - $cop_lo);
                $divisor  = $element_temp - $temp_lo;
                return $cop_lo + ($dividend / $divisor);
            }
            $temp_lo = $element_temp;
            $cop_lo = $element_cop;
        }
        throw new Exception('cop: temperature difference ' . $temperature_delta_c . ' is unbounded');
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
        $kwh = [];
        $kwh['transfer_kwh'] = $transfer_consume_j['transfer'] / Energy::JOULES_PER_KWH;
        $kwh['consume_kwh']  = $transfer_consume_j['consume']  / Energy::JOULES_PER_KWH;
        foreach ($this->time_units as $time_unit => $number_unit_values) {
            $time_value = $time->values[$time_unit];
            $t = $this->kwh[$time_unit][$time_value];
            foreach ($t as $key => $value) {
                $t[$key] += $kwh[$key];
            }
            $this->kwh[$time_unit][$time_value] = $t;
        }
    }
}