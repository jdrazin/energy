<?php
namespace Src;

class Component
{
    const array CHECKS =    [
                            'include' => ['boolean' => null],
                            'cost'    => ['array'    => null]
                            ];
    public float $step_s, $cost_value_gbp, $cost_value_per_timestep_gbp;
    public string $name;
    public bool $include;
    public array $time_units;
    public Npv $npv;

    public function __construct($check, $config, $component_name, $time) {
        $this->name = $component_name;

        $this->time_units = $time->units;
        $this->npv        = new Npv($time);
        $this->step_s     = $time->step_s;

        $this->cost_value_gbp              = 0.0;  // cost initial value
        $this->cost_value_per_timestep_gbp = 0.0;  // cost per timestep
    }

    public function value($array, $name): float { // return value as sum of array or value
        if (isset($array[$name])) {
            return is_array($array[$name]) ? array_sum($array[$name]) : $array[$name];
        }
        else {
            return 0.0;
        }
    }

    public function value_time_step($time): void
    {
        $this->npv->value_gbp($time, $this->cost_value_per_timestep_gbp);
    }

    public function sum_costs($array, $units = 1.0): void {
        if ($cost = $array ?? []) {
            $this->cost_value_gbp              -= $units * $this->value($cost, 'gbp');
            $this->cost_value_per_timestep_gbp -= $units * ($this->value($cost, 'gbp_per_year') + Energy::DAYS_PER_YEAR * $this->value($cost, 'gbp_per_day')) * $this->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
        }
    }
}