<?php

namespace Src;
require_once __DIR__ . "/Energy.php";

class Npv
{ // net present cost

    public float $value_gbp, $discount_factor_pa;
    public string $name;

    function __construct($time)
    {
        $this->name = $config['name'] ?? '';
        $this->value_gbp = 0.0;
        $this->discount_factor_pa = 1.0 / (1.0 + $time->discount_rate_pa);
    }

    public function value_gbp($time, $value_gbp): void
    {
        $year = ((float)$time->year) + $time->fraction_year;
        $this->value_gbp += ($value_gbp * ($this->discount_factor_pa ** $year));
    }
}