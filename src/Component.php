<?php
namespace Src;

class Component extends Root
{
    const array CHECKS = [
        'include'   =>  [
                            'boolean' => null
                        ]
        ];
    public float $step_s, $value_install_gbp, $value_maintenance_per_timestep_gbp;
    public string $name;
    public bool $include;
    public array $cost, $time_units;
    public Npv $npv;

    public function __construct($check, $config, $component_name, $time) {
        $this->name = $config['name'] ?? $this->strip_namespace(__NAMESPACE__,__CLASS__);
        if ($this->include = $config['include'] ?? true) {
            $this->cost = [
                            'install_gbp'          => 0.0,
                            'maintenance_pa_gbp'   => 0.0,
                            'standing_gbp_per_day' => 0.0
                          ];
            $this->sum_value($this->cost, $config, 'cost'); // sun cost components
            $this->time_units = $time->units;
            $this->npv = new Npv($time->discount_rate_pa);
            $this->step_s = $time->step_s;
            $this->value_install_gbp                    = -$this->value($config, 'cost_install_gbp');
            $value_maintenance_pa_gbp                   = -$this->value($config, 'cost_maintenance_pa_gbp');
            $value_maintenance_pa_gbp                   -= $this->value($config, 'standing_gbp_per_day') * Energy::DAYS_PER_YEAR;
            $this->value_maintenance_per_timestep_gbp   = $value_maintenance_pa_gbp * $this->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
            $this->value_install_gbp                    = -$this->cost['install_gbp'];
            $this->value_maintenance_per_timestep_gbp   = -($this->cost['maintenance_pa_gbp'] + Energy::DAYS_PER_YEAR * $this->cost['standing_gbp_per_day']) * $time->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
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

    public function sum_value(&$parameter, $array, $name): void {
        if (isset($array[$name])) {
            $element = $array[$name];
            foreach ($parameter as $k => $v) {
                $value = $this->value($element, $k);
                $parameter[$k] += $value;
            }
        }
    }
}