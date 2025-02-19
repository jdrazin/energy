<?php
namespace Src;
use Exception;

/*
 * wrapper to Python scipy cubic spline library
 *
 * see https://pythonnumericalmethods.studentorg.berkeley.edu/notebooks/chapter17.03-Cubic-Spline-Interpolation.html
 */

class CubicSpline
{
    public array $x = [];
    public function __construct($config) {
        if (!is_null($config)) {

        }
        parent::__construct();
    }

    //
    public function x($array) {
        $this->x = $array;

    }
}

