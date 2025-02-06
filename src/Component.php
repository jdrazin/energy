<?php
namespace Src;

class Component extends Root
{
    public float $step_s, $value_install_gbp, $value_maintenance_per_timestep_gbp;
    public string $name;
    public bool $active;
    public array $time_units;
    public Npv $npv;

    public function __construct($config, $time, $npv) {
        parent::__construct();
        $this->name = $config['name'] ?? $this->strip_namespace(__NAMESPACE__,__CLASS__);
        if ($this->active = $config['active'] ?? true) {
            $this->time_units = $time->units;
            $this->npv = new Npv($npv);
            $this->step_s = $time->step_s;
            $this->value_install_gbp = -$this->value($config, 'cost_install_gbp');
            $value_maintenance_pa_gbp = -$this->value($config, 'cost_maintenance_pa_gbp');
            $value_maintenance_pa_gbp -= $this->value($config, 'standing_gbp_per_day') * Energy::DAYS_PER_YEAR;
            $this->value_maintenance_per_timestep_gbp = $value_maintenance_pa_gbp *
                $this->step_s / (Energy::DAYS_PER_YEAR * Energy::HOURS_PER_DAY * Energy::SECONDS_PER_HOUR);
        }
    }

    public function value_maintenance($time): void
    {
        if ($this->active) {
            $this->npv->value_gbp($time, $this->value_maintenance_per_timestep_gbp);
        }
    }

    public function value($array, $name, $default = 0.0): float
    {   // return value as element or sum of array
        if (isset($array[$name])) {
            if (is_array($array[$name])) {
                $value = array_sum($array[$name]);
            } else {
                $value = $array[$name];
            }
        } else {
            $value = $default ?: 0.0;
        }
        return $value;
    }

    public function overlay($array_base, $array_over): array
    {
        foreach ($array_over as $key => $value) {
            $array_base[$key] = $value;
        }
        return $array_base;
    }
}