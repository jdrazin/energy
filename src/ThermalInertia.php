<?php

namespace Energy;
class ThermalInertia
{
    public float $temperature_c, $step_s, $thermal_inertial_seconds_per_c;

    public function __construct($temperature_initial_c, $thermal_inertial_seconds_per_c, $time)
    {
        $this->temperature_c = $temperature_initial_c;
        $this->step_s = $time->step_s;
        $this->thermal_inertial_seconds_per_c = $thermal_inertial_seconds_per_c;
    }

    public function time_update($temperature_target): void
    {
        $this->temperature_c = $this->temperature_c + $this->step_s * ($temperature_target - $this->temperature_c) / $this->thermal_inertial_seconds_per_c;
    }
}