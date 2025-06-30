<?php
namespace Src;

class SolarCollectors extends Component
{

    const   DEFAULT_THERMAL_INERTIA_M2_SECOND_PER_W_CELSIUS = 1000.0,
            TEMPERATURE_TARGET_INCREMENT_CELSIUS_M2_PER_W = 50 / 900,
            DEFAULTS = ['type'            => 'tilted',
                        'azimuth_degrees' => 180.0,
                        'tilt_degrees'    => 35.0,
                        'border_m'        => 0.2];

    public array    $cost, $area, $orientation_type, $azimuth_degrees, $panels_number, $power_max_w,
                    $tilt_degrees, $shading_factor, $efficiency, $efficiency_temperature_reference_c, $efficiency_per_c, $efficiency_pa, $solar, $thermal,
                    $inverter, $output_kwh, $lifetime_years, $power_w, $collectors, $collectors_value_install_gbp, $collectors_value_maintenance_per_timestep_gbp;
    private array $panels, $panels_area_m2;

    public function __construct($component, $location, $initial_temperature, $time, $npv)
    {
        parent::__construct($component, $time, $npv);
        if ($component['active']) {
            $this->cost = ['install_gbp'        => 0.0,
                           'maintenance_pa_gbp' => 0.0];
            $this->sum_value($this->cost, $component, 'cost'); // sun cost components
            $this->area = $component['area'] ?? [];
            $panels     = $component['panels'] ?? [];
            $shading_factor = $this->area['shading_factor'] ?? 1.0;
            $this->panels = [];
            foreach ($panels as $panel) {
                if ($k = $panel['panel'] ?? '') {
                    $this->panels[$k] = $panel;
                }
            }
            $this->collectors = [];
            $this->collectors_value_install_gbp = [];
            $this->collectors_value_maintenance_per_timestep_gbp = [];
            $key = 0;
            foreach ($component['collectors'] as $collector_name => $parameters) {
                if ($parameters && $parameters['active'] ?? true) {
                    $this->collectors[$key]     = $collector_name;
                    $this->panels_number[$key]  = $parameters['panels_number'] ?? null;
                    $this->shading_factor[$key] = $parameters['shading_factor'] ?? ($this->area['shading_factor'] ?? $shading_factor);
                    if ($this->panels) {
                        if (!($panel_name = $parameters['panel'] ?? false) ||
                            !($panel = $this->panels[$panel_name] ?? false)) {
                            echo 'Panel not found: ' . $panel_name . PHP_EOL;
                            exit(1);
                        }
                    } else {
                        $panel = $component['panel'];
                    }
                    $this->make_collector_parameters($key, $parameters, $panel);
                    $this->sum_value($this->cost, $parameters, 'cost'); // sun cost components

                    // ThermalInertia used to estimate panel temperature as function of solar power and time (required to estimate solar_pv thermally induced efficiency losses)
                    $thermal_inertia_m2_second_per_w_celsius = $panel['thermal_inertia_m2_second_per_w_celsius'] ?? self::DEFAULT_THERMAL_INERTIA_M2_SECOND_PER_W_CELSIUS;
                    $this->thermal[$key]  = new ThermalInertia($initial_temperature, $thermal_inertia_m2_second_per_w_celsius, $time);
                    $this->inverter[$key] = new Inverter($parameters['inverter'] ?? null, $time, $npv);
                    $this->solar[$key]    = new Solar($location, $parameters['area']['orientation']);
                }
                $key++;
            }
            $this->value_install_gbp                  = -$this->cost['install_gbp'];
            $this->value_maintenance_per_timestep_gbp = -$this->cost['maintenance_pa_gbp'] * $time->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
            $this->output_kwh = $this->zero_output();
        }
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

    public function update_output($transfer_j, $time): void
    {
        $time_values = $time->values();
        $transfer_kwh = $transfer_j / Energy::JOULES_PER_KWH;
        foreach ($time->units as $time_unit => $number_unit_values) {
            $this->output_kwh[$time_unit][$time_values[$time_unit]]['output_kwh'] += $transfer_kwh;
        }
    }

    public function transfer_consume_j($temperature_climate_c, $time): array
    {
        if (!$this->active) {
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
                'consume' => 0.0];
            $this->update_output($transfer_j, $time);
        }
        return $transfer_consume_j;
    } // $time->fraction_year > 0.475 && $time->fraction_day > 0.5

    public function make_collector_parameters($key, $parameters, $panel): void {   // find max panels that fit footprint
        $panel_width_m  = $panel['width_m'];
        $panel_height_m = $panel['height_m'];
        if ($parameters['panels_number'] ?? 0) {
            $panels_number = $parameters['panels_number'];
        }
        elseif (isset($parameters['area'])) {
            $area = $parameters['area'];
            $dimension_footprint_axis_tilt_m  = $area['dimensions_footprint_axis']['tilt_m'];
            $dimension_footprint_axis_other_m = $area['dimensions_footprint_axis']['other_m'];
            $border_m                         = $area['border_m'] ?? self::DEFAULTS['border_m'];
            $orientation                      = $area['orientation'] ?? [];
            $this->orientation_type[$key]     = $orientation['type'] ?? self::DEFAULTS['type'];
            $this->azimuth_degrees[$key]      = $orientation['azimuth_degrees'] ?? self::DEFAULTS['azimuth_degrees'];
            $this->tilt_degrees[$key]         = $orientation['tilt_degrees'] ?? self::DEFAULTS['tilt_degrees'];

            $dim_a_m                          = ($dimension_footprint_axis_other_m / cos(deg2rad($this->tilt_degrees[$key]))) - 2 * $border_m;
            $dim_b_m                          = $dimension_footprint_axis_tilt_m - 2 * $border_m;
            $panels_number                    = $this->max_panel($dim_a_m, $dim_b_m, $panel_width_m, $panel_height_m);
        }
        $this->panels_area_m2[$key]                     = $panels_number * $panel_width_m * $panel_height_m;
        $this->power_max_w[$key]                        = $panel['power_max_w'] ?? null;
        $this->lifetime_years[$key]                     = $panel['lifetime_years'] ?? 100.0;
        $this->panels_number[$key]                      = $panels_number;
        $efficiency                                     = $panel['efficiency'] ?? 1.0;
        $this->efficiency[$key]                         =  ($efficiency['percent'] ?? 1.0) / 100.0;
        $this->efficiency_per_c[$key]                   = -($efficiency['loss_percent_per_celsius'] ?? 0.0) / 100.0;
        $this->efficiency_pa[$key]                      = -($efficiency['loss_percent_pa'] ?? 0.0) / 100.0;
        $this->efficiency_temperature_reference_c[$key] =   $efficiency['temperature_reference_celsius'] ?? 20.0;
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