<?php

namespace Energy;
require_once __DIR__ . "/Energy.php";

class Npv
{ // net present cost

    public float $value_gbp, $discount_factor_pa;
    public string $name;

    function __construct($config)
    {
        $this->name = $config['name'];
        $this->discount_factor_pa = 1.0 + $config['discount_rate_pa'];
        $this->value_gbp = 0.0;
    }

    public function value_gbp($time, $value_gbp): void
    {
        $year = ((float)$time->year) + $time->fraction_year;
        $discount_factor = $this->discount_factor_pa ** $year;
        $this->value_gbp += $value_gbp / $discount_factor;
    }
}