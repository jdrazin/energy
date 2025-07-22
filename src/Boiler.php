<?php


namespace Src;

require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class Boiler extends Component
{
    const string COMPONENT_NAME = 'boiler';
    const array CHECKS = [
        'include'       => [
                                'boolean' => null
                           ],
        'output_kw'     =>  [
                                'range' => [0.0, 100.0]
                            ],
        'efficiency'     =>  [
                                'range' => [0.0, 1.0]
                            ]
                        ];

    public float $efficiency, $max_output_j;

    function __construct($config, $time)
    {
        parent::__construct($config, $time);
        $suffixes = [];
        $this->include = $this->checkValue($config, $suffixes, self::COMPONENT_NAME, 'include', self::CHECKS);;
        if ($this->include) {
            $this->max_output_j = ($component['output_kw'] * 1000 ?? 0.0) * $this->step_s;
            $this->efficiency = $component['efficiency'] ?? 1.0;
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