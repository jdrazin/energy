<?php
namespace Src;

class Insulation extends Component
{
    public  $space_heating_demand_factor = 1.0;

    public function __construct($component, $time, $npv)
    {
        parent::__construct($component, $time, $npv);
        if ($this->active) {
            $this->space_heating_demand_factor = (100.0 - ($component['space_heating_demand_reduction_percent'] ?? 0.0))/100.0;
        }
    }
}