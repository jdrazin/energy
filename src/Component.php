<?php
namespace Src;

class Component
{
    const array CHECKS =    [
                            'include' =>  ['boolean' => null]
                            ];
    public float $step_s, $value_install_gbp, $value_per_timestep_gbp;
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
        $this->value_per_timestep_gbp = 0.0;
        $this->sum_costs($component); // sun cost components
    }

    public function value($array, $name): float { // return value as sum of array or value
        if (isset($array[$name])) {
            return is_array($array[$name]) ? array_sum($array[$name]) : $array[$name];
        }
        else {
            return 0.0;
        }
    }

    public function sum_costs($array): void {
        if ($cost = $array['cost'] ?? []) {
            $this->value_install_gbp      -= $this->value($cost, 'gbp');
            $this->value_per_timestep_gbp -= ($this->value($cost, 'gbp_per_year') + Energy::DAYS_PER_YEAR * $this->value($cost, 'gbp_per_day')) * $this->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
        }
    }
}