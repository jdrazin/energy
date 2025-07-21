<?php

namespace Src;

use Exception;

class Check
{
    const MIN = 0,
          MAX = 1;

    protected function checkValue($config, $array, $name, $ranges) {
        $range = $ranges[$name] ?? [];
        if (!isset($config[$name])) {
            throw new Exception('\'' . $name . '\' component is missing');
        }
        elseif (is_null($value = $config[$name])) {
            throw new Exception("$name is null");
        }
        if (isset($range[self::MIN])) {
            if ($value < $range[self::MIN]) {
                throw new Exception('\'' . $name . '\' cannot be less than ' . $range[self::MIN]);
            }
        }
        if (isset($range[self::MAX])) {
            if ($value > $range[self::MAX]) {
                throw new Exception( '\'' . $name . '\' cannot be more than ' . $range[self::MAX]);
            }
        }
        return $value;
    }
}