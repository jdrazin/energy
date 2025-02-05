<?php


namespace Energy;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Boiler extends Component
{

    public float $tariff_per_joule, $standing_gbp_per_day, $inflation_real_pa, $efficiency, $max_output_j,
        $request_consumer_max_j;

    function __construct($config, $time, $npv)
    {
        parent::__construct($config, $time, $npv);
        if ($this->active) {
            $this->max_output_j = ($config['output_kw'] * 1000 ?? 0.0) * $this->step_s;
            $this->efficiency = $config['efficiency'] ?? 1.0;
        }
    }

    public function transfer_consume_j($request_consumed_j): array
    {
        if (!$this->active) {
            return ['transfer' => 0.0,
                'consume' => 0.0];
        } elseif ($request_consumed_j > 0.0) {
            $request_consumed_j = min($this->max_output_j, $request_consumed_j);
            return ['transfer' => $request_consumed_j * $this->efficiency,
                'consume' => $request_consumed_j];
        } else {
            return ['transfer' => 0.0,
                'consume' => 0.0];
        }
    }
}