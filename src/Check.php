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
    protected function checkValue($config, $suffixes, $component, $parameter, $parameters)
    {
        $checks = $parameters[$parameter] ?? [];
        $string = '\'' . $component . '\' component ';
        if (!isset($config[$component])) {
            throw new Exception($string . 'is missing');
        }
        $element = $config[$component];
        foreach ($suffixes as $suffix) {
            $string .= '{' . $suffix . '}';
            if (!isset($element[$suffix])) {
                throw new Exception($string . 'is missing');
            }
            $element = $element[$suffix];
        }
        $string .= '\'' . $parameter . '\'';
        if (!isset($element[$parameter])) {
            throw new Exception($string . 'is missing');
        }
        if (is_null($value = $element[$parameter])) {
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
                case 'hour_weightings': {
                    return $this->hour_weightings($string, $value);
                }
                case 'hour_tags': {
                    return $this->hour_tags($check_parameters, $string, $value);
                }
                case 'tag_numbers': {
                    return $this->tag_numbers($string, $value);
                }
                default: {
                }
            }
        }
    }

    private function range($check_parameters, $string, $value): float|int|string
    {
        if (!is_numeric($value)) {
            throw new Exception($string . '\'' . $value . '\' must be numeric');
        }
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

    private function hour_weightings($string, $hourly_weightings): array {
        if (!is_array($hourly_weightings)) {
            throw new Exception($string . '\'' . $hourly_weightings . '\'' . ' must be an array');
        }
        $count = 0;
        $last_hour = null;
        foreach ($hourly_weightings as $hour => $weighting) {
            if ($hour < 0 || $hour > 24) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer between 0 and 24');
            }
            if (!is_int($hour)) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer');
            }
            if (!is_numeric($weighting) || $weighting < 0) {
                throw new Exception($string . 'illegal \'' . $weighting . '\'' . ': must be a positive number');
            }
            if (!is_null($last_hour) && $hour <= $last_hour) {
                throw new Exception($string . 'hours must be in numerical order');
            }
            $last_hour = $hour;
        }
        return $hourly_weightings;
    }

    private function tag_numbers($string, $tag_numbers): array {
        if (!is_array($tag_numbers)) {
            throw new Exception($string . '\'' . $tag_numbers . '\'' . ' must be an array');
        }
        foreach ($tag_numbers as $tag => $number) {
            if (!is_string($tag) || is_numeric($tag)) {
                throw new Exception($string . 'tag \'' . $tag . '\'' . ' must be a non numeric name');
            }
            if (!is_numeric($number)) {
                throw new Exception($string . 'number \'' . $tag . '\'' . ' must be numeric');
            }
        }
        return $tag_numbers;
    }

    private function hour_tags($values, $string, $hourly_tags): array {
        if (!is_array($hourly_tags)) {
            throw new Exception($string . '\'' . $hourly_tags . '\'' . ' must be an array');
        }
        $count = 0;
        $last_hour = null;
        foreach ($hourly_tags as $hour => $tag) {
            if ($hour < 0 || $hour > 24) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer between 0 and 24');
            }
            if (!is_int($hour)) {
                throw new Exception($string . 'hour \'' . $hour . '\'' . ' must be an integer');
            }
            if (!is_string($tag) || $tag < 0) {
                throw new Exception($string . 'illegal \'' . $tag . '\'' . ': must be a string');
            }
            else {
                $permitted = array_flip($values);
                if (!isset($permitted[$tag])) {
                    $string .= ' \'' . $tag . '\'';
                    throw new Exception($string . 'is illegal name');
                }
            }
            if (!is_null($last_hour) && $hour <= $last_hour) {
                throw new Exception($string . 'hours must be in numerical order');
            }
            $last_hour = $hour;
        }
        return $hourly_tags;
    }
}