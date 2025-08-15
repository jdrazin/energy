<?php
namespace Src;
require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class ThermalTank extends Component
{
    public float  $temperature_c, $thermal_compliance_c_per_j, $one_way_storage_efficiency, $decay_rate_per_s, $charge_c_per_joule,
                  $discharge_c_per_joule, $temperature_max_operating_celsius;
    public bool $heat_pump;

    public function __construct($check, $config, $component_name, $time)
    {
        parent::__construct($check, $config, $component_name, $time);
    }

    public function transferConsumeJ($request_consumed_j, $temperature_external_c): array
    {  // adds to (+ve) / subtracts from (-ve) tank

        if ($request_consumed_j > 0.0) {                                                             // add energy to tank
            $thermal_energy_j = $request_consumed_j * $this->charge_c_per_joule;
            if ($this->heat_pump) {
                $this->temperature_c += $thermal_energy_j;                                           // if heat pump:  transfer heat energy to (+ve) /from (-ve) thermal reservoir
            } elseif ($this->temperature_c < $this->temperature_max_operating_celsius) {
                $this->temperature_c += $thermal_energy_j;                                           // heat up water provided within max operating temperature
            } else {
                return ['transfer' => 0.0,
                        'consume' => 0.0];                                                           // other no operation
            }
            return ['transfer' => $request_consumed_j,                                               // thermal energy transferred to tank
                    'consume' => $request_consumed_j];                                               // does not consume energy
        }
        else {                                                                                     // draw energy from tank
            if ($this->temperature_c > $temperature_external_c) {
                $this->temperature_c += $request_consumed_j * $this->discharge_c_per_joule;
                return ['transfer' => $request_consumed_j,                                           // thermal energy transferred to tank
                        'consume'  => 0.0];                                                          // does not consume energy
            } else {
                return ['transfer' => 0.0,
                        'consume' => 0.0];                                                           // other no operation
            }
        }
    }

    public function setTemperature($temperature_c): void { // set temperature
        $this->temperature_c = $temperature_c;
    }

    public function cPerJoule($c_per_joule): void { // set thermal inertia
        $this->charge_c_per_joule = $this->discharge_c_per_joule = $c_per_joule;
    }

    public function decay($temperature_ambient_c): void
    {                                                // cool down
        $this->temperature_c = $temperature_ambient_c + ($this->temperature_c - $temperature_ambient_c) * exp(-$this->decay_rate_per_s * $this->step_s);
    }
}