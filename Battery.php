<?php

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Battery extends Component
{

    const  BATTERY_DEAD_KWH = 1.0;

    public float $max_charge_w, $store_j, $efficiency, $initial_raw_capacity_kwh, $cycles_to_reduced_capacity,
        $reduced_capacity, $capacity_kwh, $store_j_max, $max_discharge_w, $cycles, $charge_state;
    public Inverter $inverter;

    public function __construct($config, $time, $npv)
    {
        parent::__construct($config, $time, $npv);
        if ($this->active) {
            $this->efficiency = $config['inverter']['one_way_storage_efficiency'] ?? 1.0;
            $this->initial_raw_capacity_kwh = $config['initial_raw_capacity_kwh'] ?? 0.0;
            $this->cycles_to_reduced_capacity = $config['cycles_to_reduced_capacity'] ?? 1E9;
            $this->reduced_capacity = $config['reduced_capacity'] ?? 1.0;
            $this->max_charge_w = 1000.0 * $config['max_charge_kw'];
            $this->max_discharge_w = 1000.0 * $config['max_discharge_kw'];
            $this->store_j = 0.0;
            $this->store_j_max = $this->initial_raw_capacity_kwh * Energy::JOULES_PER_KWH;
            $this->charge_state = 0.0;
            $this->inverter = new Inverter($config['inverter'] ?? null, $time, $npv);
            $this->cycles = 0.0;
        }
    }

    public function transfer_consume_j($request_consumed_j): array
    {   // energy_j: charge +ve, discharge -ve
        if (!$this->active) {
            return ['transfer' => 0.0,
                'consume' => 0.0];
        } else {
            if ($request_consumed_j > 0.0) {  // charge
                $request_consumed_j = min($request_consumed_j, $this->max_charge_w * $this->step_s);
                if ($this->store_j < $this->store_j_max) {                // only charge when battery not full
                    $transfer_inverter = $this->inverter->transfer_consume_j($request_consumed_j * $this->efficiency, false);
                    $transferred = $transfer_inverter['transfer'];
                    $this->store_j += $transferred;
                    $this->age($request_consumed_j);
                    return ['transfer' => $transferred,
                        'consume' => $request_consumed_j];
                } else {
                    return ['transfer' => 0.0,                            // no charge, battery is full
                        'consume' => 0.0];
                }
            } else {                      // discharge
                $request_consumed_j = max($request_consumed_j, -$this->max_discharge_w * $this->step_s);
                if ($this->store_j > 0.0) {
                    $transfer_inverter = $this->inverter->transfer_consume_j(-$request_consumed_j, true);
                    $transferred = $transfer_inverter['consume'];
                    $this->store_j -= ($transferred / $this->efficiency);
                    $this->age($request_consumed_j);
                    return ['transfer' => $request_consumed_j,
                        'consume' => 0.0];
                } else {
                    return ['transfer' => 0.0,                            // no discharge, battery is empty
                        'consume' => 0.0];
                }
            }
        }
    }

    public function age($request_consumed_j): void
    {                       // age battery
        $this->cycles += 0.5 * abs($request_consumed_j) / $this->store_j_max;
        $this->capacity_kwh = max($this->initial_raw_capacity_kwh * (1.0 + (($this->reduced_capacity - 1.0) * ($this->cycles / $this->cycles_to_reduced_capacity))), 0.0);
        if ($this->capacity_kwh >= self::BATTERY_DEAD_KWH) {
            $this->store_j_max = $this->capacity_kwh * Energy::JOULES_PER_KWH;
            $this->charge_state = $this->store_j / $this->store_j_max;
        } else {
            $this->active = false;                                          // disable battery when capacity falls below minimum threshold
        }
    }
}