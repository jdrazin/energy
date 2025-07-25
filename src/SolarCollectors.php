<?php
namespace Src;

use Exception;

class SolarCollectors extends Component
{
    const array CHECKS = [
            'include'                                   => ['boolean'  => null          ],
            'panels'                                    => ['array'    => null          ],
            'inverter'                                  => ['array'    => null          ],
            'panel'                                     => ['string'   => null          ],
            'cost_per_unit_gbp'                         => ['range'    => [0.0,  10000.0]],
            'width_m'                                   => ['range'    => [0.0,  100.0  ]],
            'height_m'                                  => ['range'    => [0.0,  100.0  ]],
            'power_max_w'                               => ['range'    => [0.0,  10000  ]],
            'lifetime_years'                            => ['range'    => [0.0,  100    ]],
            'thermal_inertia_m2_second_per_w_celsius'   => ['range'    => [1,    100000 ]],
            'panels_number'                             => ['integer'  => null,
                                                            'range'    => [0,    1000   ]],
            'shading_factor'                            => ['range'    => [0.0,  1.0    ]],
            'border_m'                                  => ['range'    => [0.0,  1.0    ]],
            'tilt_m'                                    => ['range'    => [0.0,  100.0  ]],
            'other_m'                                   => ['range'    => [0.0,  100.0  ]],
            'type'                                      => ['values'   => ['tilted', '1-axis tracker', '2-axis tracker']],
            'tilt_degrees'                              => ['range'    => [0,    90.0  ]],
            'azimuth_degrees'                           => ['range'    => [0,   360.0  ]]
    ];

    const   DEFAULT_THERMAL_INERTIA_M2_SECOND_PER_W_CELSIUS = 1000.0,
            TEMPERATURE_TARGET_INCREMENT_CELSIUS_M2_PER_W = 50 / 900;

    public array    $orientation_type, $azimuth_degrees, $panels_number, $power_max_w,
                    $tilt_degrees, $shading_factor, $efficiency, $efficiency_temperature_reference_c, $efficiency_per_c, $efficiency_pa, $solar, $thermal,
                    $inverter, $output_kwh, $lifetime_years, $power_w, $collectors, $collectors_value_install_gbp, $collectors_value_maintenance_per_timestep_gbp;
    private array $panels, $panels_area_m2;

    public function __construct($check, $config, $solar_collector, $location, $initial_temperature, $time)
    {
        $component = $config[$solar_collector];
        parent::__construct($check, $component, $solar_collector, $time);
        if ($check->checkValue($config, $solar_collector, [], 'include', self::CHECKS, true)) {
            $panels = $check->checkValue($config, $solar_collector, [], 'panels', self::CHECKS);
            foreach ($panels as $key => $panel) {
                $this->panels[$key] = [
                    'panel'                                   => $check->checkValue($config, $solar_collector, ['panels', $key], 'panel',                                   self::CHECKS),
                    'cost_per_unit_gbp'                       => $check->checkValue($config, $solar_collector, ['panels', $key], 'cost_per_unit_gbp',                       self::CHECKS, 0.0),
                    'width_m'                                 => $check->checkValue($config, $solar_collector, ['panels', $key], 'width_m',                                 self::CHECKS),
                    'height_m'                                => $check->checkValue($config, $solar_collector, ['panels', $key], 'height_m',                                self::CHECKS),
                    'power_max_w'                             => $check->checkValue($config, $solar_collector, ['panels', $key], 'power_max_w',                             self::CHECKS, 10000),
                    'lifetime_years'                          => $check->checkValue($config, $solar_collector, ['panels', $key], 'lifetime_years',                          self::CHECKS, 100),
                    'thermal_inertia_m2_second_per_w_celsius' => $check->checkValue($config, $solar_collector, ['panels', $key], 'thermal_inertia_m2_second_per_w_celsius', self::CHECKS, self::DEFAULT_THERMAL_INERTIA_M2_SECOND_PER_W_CELSIUS),
                    'efficiency'                              => [
                        'percent'                       => $check->checkValue($config, $solar_collector, ['panels', $key,'efficiency'], 'percent',                       self::CHECKS),
                        'loss_percent_pa'               => $check->checkValue($config, $solar_collector, ['panels', $key,'efficiency'], 'loss_percent_pa',               self::CHECKS,  0.0),
                        'loss_percent_per_celsius'      => $check->checkValue($config, $solar_collector, ['panels', $key,'efficiency'], 'loss_percent_per_celsius',      self::CHECKS,  0.0),
                        'temperature_reference_celsius' => $check->checkValue($config, $solar_collector, ['panels', $key,'efficiency'], 'temperature_reference_celsius', self::CHECKS, 25.0)
                    ]
                ];
            }
            if (!$this->panels) {
                throw new Exception('\'panels\' is missing');
            }
            $this->collectors                                    = [];
            $this->collectors_value_install_gbp                  = [];
            $this->collectors_value_maintenance_per_timestep_gbp = [];
            $collectors = $component['collectors'] ?? [];
            foreach ($collectors as $key => $collector) {
                if ($check->checkValue($config, $solar_collector, ['collectors', $key], 'include', self::CHECKS, true)) {
                    if ($solar_collector == 'solar_pv') {
                        $check->checkValue($config, $solar_collector, ['collectors', $key], 'inverter', self::CHECKS, null)
                            ? [
                                'power_threshold_kw' => $check->checkValue($config, $solar_collector, ['collectors', $key, 'inverter'], 'power_threshold_kw', self::CHECKS),
                                'power_efficiency'   => $check->checkValue($config, $solar_collector, ['collectors', $key, 'inverter'], 'efficiency_percent', self::CHECKS, 100.0) / 100.0,
                              ]
                            : [
                                'power_threshold_kw' => 1E6,
                                'power_efficiency'   => 1.0,
                              ];
                        $this->inverter[$key] = new Inverter($check, $config, (string) $key, $time);
                    }
                    $orientation = [
                        'type'              => $check->checkValue($config, $solar_collector, ['collectors', $key, 'area', 'orientation'], 'type', self::CHECKS),
                        'tilt_degrees'      => $check->checkValue($config, $solar_collector, ['collectors', $key, 'area', 'orientation'], 'tilt_degrees', self::CHECKS),
                        'azimuth_degrees'   => $check->checkValue($config, $solar_collector, ['collectors', $key, 'area', 'orientation'], 'azimuth_degrees', self::CHECKS)
                    ];
                    $this->shading_factor[$key] = $check->checkValue($config, $solar_collector, ['collectors', $key], 'shading_factor', self::CHECKS, 1.0);
                    if (isset($collector['panels_number'])) {
                        $panels_number = $check->checkValue($config, $solar_collector, ['collectors', $key], 'panels_number', self::CHECKS);
                    }
                    elseif (isset($collector['area'])) {
                        $dimension_footprint_axis_tilt_m  = $check->checkValue($config, $solar_collector, ['collectors', $key, 'area', 'dimensions_footprint_axis'], 'tilt_m', self::CHECKS);;
                        $dimension_footprint_axis_other_m = $check->checkValue($config, $solar_collector, ['collectors', $key, 'area', 'dimensions_footprint_axis'], 'other_m', self::CHECKS);
                        $border_m                         = $check->checkValue($config, $solar_collector, ['collectors', $key, 'area'], 'border_m', self::CHECKS, 0.0);
                        $this->tilt_degrees[$key]         = $orientation['tilt_degrees'];
                        switch ($this->orientation_type[$key] = $orientation['type']) {
                            case 'tilted': {
                                $this->azimuth_degrees[$key] = $orientation['azimuth_degrees'];
                                break;
                            }
                            case '1-axis tracker':
                            case '2-axis tracker': {
                                break;
                            }
                            default: {

                            }
                        }
                        $dim_a_m       = ($dimension_footprint_axis_other_m / cos(deg2rad($this->tilt_degrees[$key]))) - 2 * $border_m;
                        $dim_b_m       = $dimension_footprint_axis_tilt_m - 2 * $border_m;
                        $panels_number = $this->max_panel($dim_a_m, $dim_b_m, $panel['width_m'], $panel['height_m']);
                    }
                    else {
                        $panels_number = 0;
                    }
                    $panel_name                                     = $check->checkValue($config, $solar_collector, ['collectors', $key], 'panel', self::CHECKS);
                    $panel                                          = $this->panel($panel_name);
                    $this->panels_area_m2[$key]                     = $panels_number * $panel['width_m'] * $panel['height_m'];
                    $this->power_max_w[$key]                        = $panel['power_max_w'] ?? null;
                    $this->lifetime_years[$key]                     = $panel['lifetime_years'] ?? 100.0;
                    $this->panels_number[$key]                      = $panels_number;
                    $efficiency                                     = $panel['efficiency'];
                    $this->efficiency[$key]                         = ($efficiency['percent'] ?? 1.0) / 100.0;
                    $this->efficiency_per_c[$key]                   = -($efficiency['loss_percent_per_celsius'] ?? 0.0) / 100.0;
                    $this->efficiency_pa[$key]                      = -($efficiency['loss_percent_pa'] ?? 0.0) / 100.0;
                    $this->efficiency_temperature_reference_c[$key] = $efficiency['temperature_reference_celsius'] ?? 20.0;

                    // ThermalInertia used to estimate panel temperature as function of solar power and time (required to estimate solar_pv thermally induced efficiency losses)
                    $this->thermal[$key]  = new ThermalInertia($initial_temperature, $panel['thermal_inertia_m2_second_per_w_celsius'], $time);
                    $this->solar[$key]    = new Solar($location, $orientation);

                    // accumulate costs
                    $this->value_install_gbp += -$panels_number * $panel['cost_per_unit_gbp'];
                }
            }
            $this->output_kwh = $this->zero_output();
        }
    }

    /**
     * @throws Exception
     */
    private function panel($name): array {  // return panel
        foreach ($this->panels as $panel) {
            if ($panel['panel'] == $name) {
                return $panel;
            }
        }
        throw new Exception('Panel not found: ' . $name);
    }

    public function zero_output(): array
    {
        $array = [];
        foreach ($this->time_units as $time_unit => $number_unit_values) {
            $array[$time_unit] = [];
            for ($time_unit_value = 0; $time_unit_value < $number_unit_values; $time_unit_value++) {
                $array[$time_unit][$time_unit_value] = ['output_kwh' => 0.0];
            }
        }
        return $array;
    }

    public function transfer_consume_j($temperature_climate_c, $time): array
    {
        if (!$this->include) {
            $transfer_consume_j = ['transfer' => 0.0,
                                   'consume'  => 0.0];
        } else {
            $transfer_j = 0.0;
            $collector_j = [];
            $inverter_j = [];
            foreach ($this->collectors as $key => $collector_name) { // sum energies for each collector
                $this->solar[$key]->time_update($time);
                $this->thermal[$key]->time_update($this->temperature_target($temperature_climate_c, $key));

                // incident shaded/clouded corrected power per panel square meter
                $power_w_per_panel_area_m2 = $this->power_solar_insolation_w_per_panel_m2($key);

                // power: ideal converted
                $power_w_per_panel_area_m2 *= $this->efficiency[$key];

                // power: temperature corrected
                $power_w_per_panel_area_m2 *= (1.0 + $this->efficiency_per_c[$key] * ($this->thermal[$key]->temperature_c - $this->efficiency_temperature_reference_c[$key]));

                // power: age corrected
                $power_w_per_panel_area_m2 *= (1.0 + ((float)$time->year + $time->fraction_year) * $this->efficiency_pa[$key]);

                $power_w = $power_w_per_panel_area_m2 * $this->panels_area_m2[$key];

                // clip to max power per panel
                if ($power_max_w = $this->power_max_w[$key]) {
                    $power_max_w *= $this->panels_number[$key];
                    if ($power_w > $power_max_w) {
                        $power_w = $power_max_w;
                    }
                }
                $this->power_w[$key] = $power_w;
                $collector_j[$key] = $power_w * $this->step_s;
                $inverter_j[$key] = $this->inverter[$key]->transfer_consume_j($collector_j[$key], false);
                $transfer_j += $inverter_j[$key]['transfer'];
            }
            $transfer_consume_j = ['transfer' => $transfer_j,
                                   'consume'  => 0.0];
        }
        return $transfer_consume_j;
    }

    private function max_panel($d_a, $d_b, $p_a, $p_b) // calculate msx number of panels from area
    {
        // a orientation
        $panels_a_x = intval($d_a / $p_a);
        $panels_a_y = intval($d_b / $p_b);
        $oanels_a = $panels_a_x * $panels_a_y;

        // b orientation
        $panels_b_x = intval($d_a / $p_b);
        $panels_b_y = intval($d_b / $p_a);
        $oanels_b = $panels_b_x * $panels_b_y;

        return max($oanels_a, $oanels_b);
    }

    public function temperature_target($temperature_climate, $key): float    // returns elevated panel surface temperature over climate temperature due to total solar radiation
    {
        return $temperature_climate + self::TEMPERATURE_TARGET_INCREMENT_CELSIUS_M2_PER_W * $this->solar[$key]->total_insolation_cloud_time_w_per_m2;
    }

    public function power_solar_insolation_w_per_panel_m2($key): float|int
    {
        return $this->solar[$key]->total_insolation_cloud_time_w_per_m2 * $this->shading_factor[$key];
    }
}