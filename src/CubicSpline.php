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
    public int $multiple;
    public array $x = [];
    public function __construct($multiple) {
        $this->multiple = $multiple;
    }
    public function x($array) {
        $this->x = $array;
    }

    /**
     * @return array
     */
    public function cubic_spline_y(): array {
        return $this->x;
    }

    private function remove_nulls(): array {

    }
}

