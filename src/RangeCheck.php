<?php

namespace Src;

use Exception;

class RangeCheck
{
    const MIN = 0,
          MAX = 1;

    protected function check($array, $name, $ranges) {
        $range = $ranges[$name] ?? [];
        if (!isset($array[$name])) {
            throw new Exception("$name is missing");
        }
        elseif (is_null($value = $array[$name])) {
            throw new Exception("$name is null");
        }
        if (isset($range[self::MIN])) {
            if ($value < $range[self::MIN]) {
                throw new Exception("$name cannot be less than " . $range[self::MIN]);
            }
        }
        if (isset($range[self::MAX])) {
            if ($value > $range[self::MAX]) {
                throw new Exception("$name cannot be more than " . $range[self::MAX]);
            }
        }
        return $value;
    }
}