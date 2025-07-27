<?php
namespace Src;

class Component
{
    const array CHECKS = [
        'include'   =>  [
                            'boolean' => null
                        ]
        ];
    public float $step_s, $value_install_gbp, $value_maintenance_per_timestep_gbp;
    public string $name;
    public bool $include, $active;
    public array $cost, $time_units;
    public Npv $npv;

    public function __construct($check, $config, $component_name, $time) {
        $this->name = $component_name;
        $component  = $config[$component_name];

        $this->time_units = $time->units;
        $this->npv = new Npv($time->discount_rate_pa);
        $this->step_s = $time->step_s;

        // sum install and ongoing costs
        $this->value_install_gbp                  = 0.0;
        $this->value_maintenance_per_timestep_gbp = 0.0;
        if (isset($component['cost'])) { // sum elements
            $this->accumulate_cost($this->cost, $component, 'cost'); // sun cost components
        }
    }

    public function value_maintenance($time): void
    {
        if ($this->include) {
            $this->npv->value_gbp($time, $this->value_maintenance_per_timestep_gbp);
        }
    }

    public function value($array_value, $name): float { // return value as sum of array or value
        if (isset($array_value[$name])) {
            return is_array($array_value[$name]) ? array_sum($array_value[$name]) : $array_value[$name];
        }
        else {
            return 0.0;
        }
    }

    public function accumulate_cost(&$parameter, $array, $name): void {
        if (isset($array[$name])) {
            $element = $array[$name];
            foreach ($parameter as $k => $v) {
                $value = $this->value($element, $k);
                $parameter[$k] += $value;
            }
        }
        $this->value_install_gbp                  -= $parameter['cost_install_gbp'];
        $this->value_maintenance_per_timestep_gbp -= ($parameter['maintenance_pa_gbp'] + $parameter['standing_gbp_per_day']) * $this->step_s / (Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
    }
}