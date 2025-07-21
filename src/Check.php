<?php

namespace Src;

use Exception;

class Check
{
    const MIN = 0,
          MAX = 1;

    protected function checkValue($config, $component, $parameter, $ranges) {
        $range = $ranges[$parameter] ?? [];
        $string = '\'' . $component . '\' component ';
        if (!isset($config[$component])) {
            throw new Exception($string . 'is missing');
        }
        $string .= '\'' . $parameter . '\' ';
        if (!isset($config[$component][$parameter])) {
            throw new Exception($string . 'is missing');
        }
        if (is_null($value = $config[$component][$parameter])) {
            throw new Exception($string . 'is null');
        }
        $string .= 'cannot be ';
        if (isset($range[self::MIN])) {
            if ($value < $range[self::MIN]) {
                throw new Exception($string . 'less than ' . $range[self::MIN]);
            }
        }
        if (isset($range[self::MAX])) {
            if ($value > $range[self::MAX]) {
                throw new Exception( $string . 'more than ' . $range[self::MAX]);
            }
        }
        return $value;
    }
}