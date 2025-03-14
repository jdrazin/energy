<?php

namespace Src;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Battery extends Component
{
    public float    $max_charge_w, $store_j, $one_way_efficiency, $initial_raw_capacity_kwh, $cycles_to_reduced_capacity,
                    $reduced_capacity, $capacity_kwh, $store_j_max, $max_discharge_w, $cycles;

    public function __construct($component, $time, $npv)
    {
        parent::__construct($component, $time, $npv);
        if ($this->active) {
            $this->one_way_efficiency           = sqrt(($component['round_trip_efficiency_percent'] ?? 100.0) / 100.0);
            $this->initial_raw_capacity_kwh     = $component['initial_raw_capacity_kwh'] ?? 0.0;
            $this->cycles_to_reduced_capacity   = $component['projection']['cycles_to_reduced_capacity'] ?? 1E9;
            $this->reduced_capacity             = 1.0 - (($component['projection']['reduced_capacity_percent'] ?? 0)/100.0);
            $this->max_charge_w                 = 1000.0 * $component['max_charge_kw'];
            $this->max_discharge_w              = 1000.0 * $component['max_discharge_kw'];
            $this->store_j                      = 0.0;
            $this->store_j_max                  = $this->initial_raw_capacity_kwh * Energy::JOULES_PER_KWH;
            $this->cycles                       = 0.0;
        }
    }

    public function transfer_consume_j($request_consumed_j): array
    {   // energy_j: charge +ve, discharge -ve
        if ($request_consumed_j > 0.0) {  // charge
            $request_consumed_j = min($request_consumed_j, $this->max_charge_w * $this->step_s);
            if ($this->store_j < $this->store_j_max) {                // only charge when battery not full
                $this->store_j += ($request_consumed_j * $this->one_way_efficiency);
                $this->age($request_consumed_j);
                return ['transfer' => $request_consumed_j,
                        'consume'  => $request_consumed_j];
            } else {
                return ['transfer' => 0.0,                            // no charge, battery is full
                        'consume'  => 0.0];
            }
        } else {                      // discharge
            if ($this->store_j > 0.0) {
                $request_consumed_j = max($request_consumed_j, -$this->max_discharge_w * $this->step_s);
                $this->store_j += ($request_consumed_j / $this->one_way_efficiency);
                $this->age($request_consumed_j);
                return ['transfer' => $request_consumed_j,
                        'consume'  => 0.0];
            } else {
                return ['transfer' => 0.0,                            // no discharge, battery is empty
                        'consume'  => 0.0];
            }
        }
    }

    public function age($request_consumed_j): void                    // age battery
    {
        if ($this->store_j_max > 0.0) {
            $this->cycles       += 0.5 * abs($request_consumed_j) / $this->store_j_max;
            $this->capacity_kwh  = max($this->initial_raw_capacity_kwh * (1.0 + (($this->reduced_capacity - 1.0) * ($this->cycles / $this->cycles_to_reduced_capacity))), 0.0);
            $this->store_j_max   = $this->capacity_kwh * Energy::JOULES_PER_KWH;
        }
    }
}