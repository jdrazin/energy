<?php

namespace Src;
class ParameterCombinations
{
    const COMBINATION_ELEMENTS = ['battery', 'heat_pump', 'boiler', 'solar_pv', 'solar_thermal', 'insulation'];

    public int $elements_count, $msb;
    public array $combinations, $fixed, $variables;

    public function __construct($config)
    {
        $this->elements_count = count(self::COMBINATION_ELEMENTS);
        $this->variables = [];
        $combinations_count = 1;
        foreach (self::COMBINATION_ELEMENTS as $element_name) {            // separate fixed and variable config parameters
            if (in_array($element_name, $config['combine'] ?? [])) {
                $this->variables[] = $element_name;
                $combinations_count *= 2;
            } else {
                $this->fixed[$element_name] = $config[$element_name]['include'] ?? false;
            }
        }
        $this->msb = count($this->variables) - 1;
        for ($count = 0; $count < $combinations_count; $count++) {
            $this->combinations[] = array_merge($this->combination($count), $this->fixed);
        }
    }

    public function combination($count): array
    {
        $parameters_variable = [];
        $bit_value = 1;
        for ($bit = 0; $bit <= $this->msb; $bit++) {
            $parameters_variable[$this->variables[$bit]] = (bool)($count & $bit_value);
            $bit_value *= 2;
        }
        return $parameters_variable;
    }
}