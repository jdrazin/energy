<?php


namespace Src;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Boiler extends Component
{
    const string COMPONENT_NAME = 'boiler';
    const array CHECKS = [
        'cost'       => ['array' => null        ],
        'output_kw'  => ['range' => [0.0, 100.0]],
        'efficiency' => ['range' => [0.0, 1.0]]];

    public float $efficiency, $max_output_j;

    function __construct($check, $config, $time) {
        if ($this->include = $check->checkValue($config, self::COMPONENT_NAME, [], 'include',            self::CHECKS, true)) {
            parent::__construct($check, $config, self::COMPONENT_NAME, $time);
            $output_kw  = $check->checkValue($config, self::COMPONENT_NAME, [], 'output_kw',          self::CHECKS);
            $efficiency = $check->checkValue($config, self::COMPONENT_NAME, [], 'efficiency_percent', self::CHECKS, 100.0)/100.0;
            $this->sum_costs($check->checkValue($config, self::COMPONENT_NAME, [], 'cost',               self::CHECKS));
            $this->max_output_j = $output_kw * 1000 * $this->step_s;
            $this->efficiency   = $efficiency;
        }
    }

    public function transfer_consume_j($request_consumed_j): array
    {
        if (!$this->include) {
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