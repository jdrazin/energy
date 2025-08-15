<?php
namespace Src;
require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class ThermalTank extends Component
{
    public float  $temperature_c, $decay_rate_per_s, $thermal_compliance_c_per_j, $discharge_c_per_j, $temperature_max_operating_celsius;

    public function __construct($check, $config, $component_name, $time)
    {
        parent::__construct($check, $config, $component_name, $time);
    }

    public function transferConsumeJ($request_consumed_j, $temperature_external_c): array {         // adds to (+ve) / subtracts from (-ve) tank
        if ($request_consumed_j > 0.0) {                                                            // add energy to tank
            if ($this->temperature_c < ($this->temperature_max_operating_celsius ?? 1E6)) {         // heat up if within operating temperature
              $this->temperature_c += $request_consumed_j * $this->thermal_compliance_c_per_j;
              return ['transfer' => $request_consumed_j,                                             // thermal energy transferred to tank
                      'consume'  => $request_consumed_j];
            }
            else {
              return ['transfer' => 0.0,
                      'consume' => 0.0];                                                             // otherwise no operation
            }
        }
        else {                                                                                       // draw energy from tank
            if ($this->temperature_c > $temperature_external_c) {
                $this->temperature_c += $request_consumed_j * $this->discharge_c_per_j;
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
        $this->thermal_compliance_c_per_j = $this->discharge_c_per_j = $c_per_joule;
    }

    public function decay($temperature_ambient_c): void
    {                                                // cool down
        $this->temperature_c = $temperature_ambient_c + ($this->temperature_c - $temperature_ambient_c) * exp(-$this->decay_rate_per_s * $this->step_s);
    }
}