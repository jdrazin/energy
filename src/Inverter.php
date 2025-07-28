<?php
namespace Src;
require_once __DIR__ . '/Component.php';

class Inverter extends Component
{
    public float $power_efficiency, $power_threshold_w;

    public function __construct($check, $component, $component_name, $time)
    {
            parent::__construct($check, $component, $component_name, $time);
            $this->power_efficiency = $component['power_efficiency'] ?? 1.0;
            $this->power_threshold_w = $component['power_threshold_w'] ?? 0.0;
    }

    public function transfer_consume_j($request_consumed_j, $net_request): array { // energy for transfer through inverter
        $energy_threshold_j = $this->power_threshold_w * $this->step_s;
        if ($net_request) {
            $transferred = $request_consumed_j;
            $consumed = ($request_consumed_j / $this->power_efficiency);
        } else {
            $transferred = max(($request_consumed_j * $this->power_efficiency) - $energy_threshold_j, 0.0);
            $consumed = max($transferred, 0.0);
        }
        return ['transfer' => $transferred,
                'consume' => $consumed];
    }
}