<?php

namespace Src;

use Exception;

class Check
{
    const MIN = 0,
          MAX = 1;

    /**
     * @throws Exception
     */
    protected function checkValue($config, $suffix, $component, $parameter, $parameters)
    {
        $checks = $parameters[$parameter] ?? [];
        $string = '\'' . $component . '\' component ';
        if (!isset($config[$component])) {
            throw new Exception($string . 'is missing');
        }
        if (!$suffix) {
            $string .= '\'' . $parameter . '\' ';
            if (!isset($config[$component][$parameter])) {
                throw new Exception($string . 'is missing');
            }
        }
        else {
            $string .= '\'' . $suffix . '\' suffix ';
            if (!isset($config[$component][$suffix])) {
                throw new Exception($string . 'is missing');
            }
            $string .= '\'' . $parameter . '\' ';
            if (!isset($config[$component][$suffix][$parameter])) {
                throw new Exception($string . 'is missing');
            }
        }
        $value = $suffix ? $config[$component][$suffix][$parameter] : $config[$component][$parameter];
        if (is_null($value)) {
            throw new Exception($string . 'is null');
        }
        foreach ($checks as $check_type => $check_parameters) {
            switch ($check_type) {
                case 'range': {
                    return $this->range($check_parameters, $string, $value);
                }
                case 'values': {
                    return $this->values($check_parameters, $string, $value);
                }
                case 'hourly': {
                    return $this->hourly($check_parameters, $string, $value);
                }
                default: {
                }
            }
        }
    }

    private function range($check_parameters, $string, $value) {
        $string .= 'cannot be ';
        if (isset($check_parameters[self::MIN])) {
            if ($value < $check_parameters[self::MIN]) {
                throw new Exception($string . 'less than ' . $check_parameters[self::MIN]);
            }
        }
        if (isset($check_parameters[self::MAX])) {
            if ($value > $check_parameters[self::MAX]) {
                throw new Exception( $string . 'more than ' . $check_parameters[self::MAX]);
            }
        }
        return $value;
    }

    private function values(array $values, $string, $value)
    {
        $string .= 'cannot be ';
        $values = array_flip($values);
        if (!isset($values[$value])) {
            throw new Exception($string . '\'' . $value . '\'');
        }
        return $value;
    }

    private function hourly($values, $string, $hours)
    {
        if (!is_array($hours)) {
            throw new Exception($string . '\'' . $hours . '\'' . ' must be an array');
        }
        $count = 0;
        $last_hour = null;
        foreach ($hours as $hour => $weighting) {
            if ($hour < 0 || $hour > 24) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer between 0 and 24');
            }
            if (!is_int($hour)) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer');
            }
            if (!is_null($last_hour) && $hour <= $last_hour) {
                throw new Exception($string . 'hours must be in numerical order');
            }
            $last_hour = $hour;
        }

        if (!isset($values[$hours])) {
            throw new Exception($string . '\'' . $hours . '\'');
        }
        return $hours;
    }
}