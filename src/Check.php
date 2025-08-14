<?php

namespace Src;

use Exception;

class Check
{
    const MIN = 0,
          MAX = 1;

    public array $config_applied;

    public function __construct() {
        $this->config_applied = [];
    }

    /**
     * @throws Exception
     */
    public function checkValue($config, $component, $suffixes, $parameter, $parameter_checks, $default = null): mixed
    {
        $checks = $parameter_checks[$parameter] ?? [];
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
        if (!isset($element[$parameter]) && is_null($default)) {
            throw new Exception($string . 'is missing');
        }
        $value = $element[$parameter] ?? $default;
        if (is_null($value)) {
            throw new Exception($string . 'is null');
        }
        // apply value
        $path = $component;
        foreach ($suffixes as $suffix) {
            $path .= '[' . $suffix . ']';
        }
        $path .= '[' . $parameter . ']';
        $this->setValueByStringPath($this->config_applied, $path, $value);

        // check value
        foreach ($checks as $check_type => $check_parameters) {
            switch ($check_type) {
                case 'array': {
                    $this->array($check_parameters, $string, $value);
                    break;
                }
                case 'range': {
                    $this->range($check_parameters, $string, $value);
                    break;
                }
                case 'values': {
                    $this->values($check_parameters, $string, $value);
                    break;
                }
                case 'hour_values': {
                    $this->hour_values($string, $value);
                    break;
                }
                case 'temperature_cops': {
                    $this->temperature_cops($string, $value);
                    break;
                }
                case 'bands_key': {
                    $this->bands_key($check_parameters, $string, $value);
                    break;
                }
                case 'hour_bands': {
                    $this->hour_bands($check_parameters, $string, $value);
                    break;
                }
                case 'tag_numbers': {
                    $this->tag_numbers($string, $value);
                    break;
                }
                case 'boolean': {
                    $this->boolean($string, $value);
                    break;
                }
                case 'string': {
                    $this->string($string, $value);
                    break;
                }
                case 'integer': {
                    $this->integer($string, $value);
                    break;
                }
                default:
                {
                }
            }
        }
        return $value;
    }

    /**
     * @throws Exception
     */
    private function array($check_parameters, $string, $value): void
    {
        if (!is_array($value)) {
            throw new Exception($string . '\'' . $value . '\' must be array');
        }
        if (is_array($check_parameters) && (count($check_parameters) == 2) && is_numeric($lo = $check_parameters[0]) && is_numeric($hi = $check_parameters[1])) {
            foreach ($value as $v) {
                if (!is_numeric($v)) {
                    throw new Exception($string . ' array value ' . $v . ' must be numeric)');
                }
                if ($v < $lo) {
                    throw new Exception($string . ' array value ' . $v . ' is too low (must exceed ' . $lo . ')');
                }
                if ($v > $hi) {
                    throw new Exception($string . ' array value ' . $v . ' is too high (must not exceed ' . $hi . ')');
                }
            }
            if (count($value) != $check_parameters) {
                throw new Exception($string . ' array must have ' . $check_parameters . ' elements');
            }
        }
    }

    /**
     * @throws Exception
     */
    private function range($check_parameters, $string, $value): void
    {
        if (!is_numeric($value)) {
            throw new Exception($string . '\'' . $value . '\' must be numeric');
        }
        $string .= ' cannot be ';
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
    }

    /**
     * @throws Exception
     */
    private function values(array $values, $string, $value): void
    {
        $string .= 'cannot be ';
        $values = array_flip($values);
        if (!isset($values[$value])) {
            throw new Exception($string . '\'' . $value . '\'');
        }
    }

    /**
     * @throws Exception
     */
    private function hour_values($string, $hourly_values):void {
        if (!is_array($hourly_values)) {
            throw new Exception($string . '\'' . $hourly_values . '\'' . ' must be an array');
        }
        $last_hour = null;
        foreach ($hourly_values as $hour => $weighting) {
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
    }

    /**
     * @throws Exception
     */
    private function temperature_cops($string, $temperature_cops):void {
        if (!is_array($temperature_cops)) {
            throw new Exception($string . '\'' . $temperature_cops . '\'' . ' must be an array');
        }
        foreach ($temperature_cops as $temperature_c => $cop) {
            if ($temperature_c < -30.0 || $temperature_c > 100.0) {
                throw new Exception($string . 'temperature \'' . $temperature_c . '\'' . ' must be between -30 and +100 degrees C');
            }
            if (!is_numeric($temperature_c)) {
                throw new Exception($string . 'temperature \'' . $temperature_c . '\'' . ' must be numeric');
            }
            if (!is_numeric($cop) || $cop < 0.9 || $cop > 10.0) {
                throw new Exception($string . 'illegal \'' . $cop . '\'' . ': must be between 0.9 and 10');
            }
        }
    }

    /**
     * @throws Exception
     */
    private function tag_numbers($string, $tag_numbers): void {
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
    }

    /**
     * @throws Exception
     */
    private function bands_key($values, $string, $band_keys): void {
        if (!is_array($band_keys)) {
            throw new Exception($string . '\'' . $band_keys . '\'' . ' must be an array');
        }
        $last_hour = null;
        foreach ($band_keys as $band_key => $value) {
            if (!$band_key || !in_array($band_key, Supply::CHECKS['bands_gbp_per_kwh']['bands_key'])) {
                throw new Exception($string . 'hour \'' . $band_key . '\'' . ' missing or illegal band');
            }
        }
    }

    /**
     * @throws Exception
     */
    private function hour_bands($values, $string, $band_values): void {
        if (!is_array($band_values)) {
            throw new Exception($string . '\'' . $band_values . '\'' . ' must be an array');
        }
        $last_hour = null;
        foreach ($band_values as $key => $band_value) {
            if ($key < 0 || $key > 24) {
                throw new Exception($string . 'hour \'' . $key . '\'' . ' must be an integer between 0 and 24');
            }
            if (!is_int($key)) {
                throw new Exception($string . 'hour \'' . $key . '\'' . ' must be an integer');
            }
            if (!is_string($band_value) || $band_value < 0) {
                throw new Exception($string . 'illegal \'' . $band_value . '\'' . ': must be a string');
            }
            else {
                $permitted = array_flip($values);
                if (!isset($permitted[$band_value])) {
                    $string .= ' \'' . $band_value . '\'';
                    throw new Exception($string . 'is illegal name');
                }
            }
            if (!is_null($last_hour) && $key <= $last_hour) {
                throw new Exception($string . 'hours must be in numerical order');
            }
            $last_hour = $key;
        }
    }

    /**
     * @throws Exception
     */
    private function boolean($string, $value): void
    {
        if (!is_bool($value)) {
            throw new Exception($string . '\'' . $value . '\' must be boolean');
        }
    }

    /**
     * @throws Exception
     */
    private function integer($string, $value): void
    {
        if (!is_int($value)) {
            throw new Exception($string . '\'' . $value . '\' must be integer');
        }
    }

    /**
     * @throws Exception
     */
    private function string($string, $value): void
    {
        if (!is_string($value)) {
            throw new Exception($string . '\'' . $value . '\' must be a character string');
        }
    }


    function setValueByStringPath(array &$array, string $path, $value): void {
        // Convert 'foo[bar][baz]' to ['foo', 'bar', 'baz']
        preg_match_all('/\[?([^\[\]]+)\]?/', $path, $matches);
        $keys = $matches[1];

        $ref = &$array;
        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
    }
}