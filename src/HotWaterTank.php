<?php
namespace Src;
require_once __DIR__ . '/Component.php';
require_once __DIR__ . "/Energy.php";

class HotWaterTank extends ThermalTank
{
    const string COMPONENT_NAME = 'storage_hot_water';
    const array CHECKS = [
                            'storage_hot_water'                         => ['array'    => null           ],
                            'include'                                   => ['boolean'  => null           ],
                            'volume_m3'                                 => ['range'    => [0.0,      10.0]],
                            'immersion_kw'                              => ['range'    => [0.0,      10.0]],
                            'target_temperature_c'                      => ['range'    => [40.0,     95.0]],
                            'half_life_days'                            => ['range'    => [0.5,       7.0]],
                            'one_way_storage_efficiency_percent'        => ['range'    => [50.0,    100.0]],
    ];
    const  float    HEAT_CAPACITY_WATER_J_PER_M3_K = 4200000.0,
                    TEMPERATURE_MAX_OPERATING_CELSIUS = 65.0;

    public float $target_temperature_c, $immersion_w;

    public function __construct($check, $config, $component_name, $time)
    {
        if ($this->include = $check->checkValue($config, self::COMPONENT_NAME, [], 'include', self::CHECKS, true)) {
            $volume_m3 = $check->checkValue($config, self::COMPONENT_NAME, [], 'volume_m3', self::CHECKS);
            $this->capacity_c_per_joule = 1.0 / ($volume_m3 * self::HEAT_CAPACITY_WATER_J_PER_M3_K);
            parent::__construct($check, $config, self::COMPONENT_NAME, $time);
            $this->temperature_max_operating_celsius = self::TEMPERATURE_MAX_OPERATING_CELSIUS;
            $this->immersion_w = 1000.0 * $check->checkValue($config, self::COMPONENT_NAME, [], 'immersion_kw', self::CHECKS, 3.0);
            $this->target_temperature_c = $check->checkValue($config, self::COMPONENT_NAME, [], 'target_temperature_c', self::CHECKS, 65.0);
            $this->heat_pump = $component_name;
        }
    }
}