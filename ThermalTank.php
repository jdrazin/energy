<?php


require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class ThermalTank extends Component
{
    const HEAT_CAPACITY_WATER_J_PER_M3_K = 4200000.0,
        TEMPERATURE_MAX_OPERATING_CELSIUS = 65.0;

    public float $temperature_c, $capacity_c_per_joule, $one_way_storage_efficiency, $decay_rate_per_s, $charge_c_per_joule, $discharge_c_per_joule, $target_temperature_c, $immersion_w;
    public bool $heat_pump;

    public function __construct($config, $heat_pump, $time, $npv)
    {
        parent::__construct($config, $time, $npv);
        if ($this->active) {
            $this->immersion_w = 1000.0 * ($config['immersion_w'] ?? 0.0);
            $this->target_temperature_c = $config['target_temperature_c'] ?? 50.0;
            $this->capacity_c_per_joule = 1.0 / ($config['volume_m3'] * self::HEAT_CAPACITY_WATER_J_PER_M3_K);
            $this->decay_rate_per_s = isset($config['half_life_days']) ? log(2) / ($config['half_life_days'] * 24 * 3600) : 0.0;
            $this->temperature_c = $config['initial_temp_celsius'] ?? (new Climate)->temperature_time($time);
            $this->one_way_storage_efficiency = $config['one_way_storage_efficiency'] ?? 1.0;
            $this->charge_c_per_joule = $this->capacity_c_per_joule * $this->one_way_storage_efficiency;
            $this->discharge_c_per_joule = $this->capacity_c_per_joule;
            $this->heat_pump = $heat_pump;
        }
    }

    public function transfer_consume_j($request_consumed_j, $temperature_external_c): array
    {  // adds to (+ve) / subtracts from (-ve) tank
        if (!$this->active) {
            return ['transfer' => 0.0,
                'consume' => 0.0];
        } else {
            if ($request_consumed_j > 0.0) {                                                             // add energy to tank
                $thermal_energy_j = $request_consumed_j * $this->charge_c_per_joule;
                if ($this->heat_pump) {
                    $this->temperature_c += $thermal_energy_j;                                           // if heat pump:  transfer heat energy to (+ve) /from (-ve) thermal reservoir
                } elseif ($this->temperature_c < self::TEMPERATURE_MAX_OPERATING_CELSIUS) {
                    $this->temperature_c += $thermal_energy_j;                                           // heat up water provided within max operating temperature
                } else {
                    return ['transfer' => 0.0,
                        'consume' => 0.0];                                                          // other no operation
                }
                return ['transfer' => $request_consumed_j,                                               // thermal energy transferred to tank
                    'consume' => $request_consumed_j];                                                              // does not consume energy
            } else {                                                                                       // draw energy from tank
                if ($this->temperature_c > $temperature_external_c) {
                    $this->temperature_c += $request_consumed_j * $this->discharge_c_per_joule;
                    return ['transfer' => $request_consumed_j,                                           // thermal energy transferred to tank
                        'consume' => 0.0];                                                          // does not consume energy
                } else {
                    return ['transfer' => 0.0,
                        'consume' => 0.0];                                                          // other no operation
                }
            }
        }

    }

    public function decay($temperature_ambient_c): void
    {                                                // cool down
        $this->temperature_c = $temperature_ambient_c + ($this->temperature_c - $temperature_ambient_c) * exp(-$this->decay_rate_per_s * $this->step_s);
    }
}