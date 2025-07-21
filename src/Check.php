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
    protected function checkValue($config, $component, $parameter, $parameters)
    {
        $checks = $parameters[$parameter] ?? [];
        foreach ($checks as $check_type => $check_parameters) {
            switch ($check_type) {
                case 'range': {
                    $this->range($config, $component, $parameter, $check_parameters);
                    break;
                }
                default: {
                }
            }
        }
    }

    private function range($config, $component, $parameter, $check_parameters): void {
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
}