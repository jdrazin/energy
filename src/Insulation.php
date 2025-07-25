<?php
namespace Src;

class Insulation extends Component
{
    const string COMPONENT_NAME = 'insulation';
    const array CHECKS = [
        'include'                                   => ['boolean' => null],
        'space_heating_demand_reduction_percent'    => ['range'   => 0.0, 100.0]
    ];
    public float $space_heating_demand_factor = 1.0;

    public function __construct($check, $config, $time)
    {
        parent::__construct($check, $config, self::COMPONENT_NAME, $time);
        if ($this->include = $check->checkValue($config, self::COMPONENT_NAME, [], 'include', self::CHECKS, true)) {
            $space_heating_demand_reduction_percent = $check->checkValue($config, self::COMPONENT_NAME, [], 'space_heating_demand_reduction_percent', self::CHECKS);
            $this->space_heating_demand_factor = (100.0 - $space_heating_demand_reduction_percent)/100.0;
        }
    }
}