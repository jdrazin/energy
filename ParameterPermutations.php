<?php

class ParameterPermutations
{
    const PERMUTATION_ELEMENTS = ['battery', 'heat_pump', 'boiler', 'solar_pv', 'solar_thermal'];

    public int $elements_count, $msb;
    public array $permutations, $fixed, $variables;

    public function __construct($config)
    {
        $this->elements_count = count(self::PERMUTATION_ELEMENTS);
        $this->variables = [];
        $permutations_count = 1;
        foreach (self::PERMUTATION_ELEMENTS as $element_name) {            // separate fixed and variable config parameters
            if (in_array($element_name, $config['permute'] ?? [])) {
                $this->variables[] = $element_name;
                $permutations_count *= 2;
            } else {
                $this->fixed[$element_name] = $config[$element_name]['active'];
            }
        }
        $this->msb = count($this->variables) - 1;
        for ($count = 0; $count < $permutations_count; $count++) {
            $this->permutations[] = array_merge($this->permutation($count), $this->fixed);
        }
    }

    public function permutation($count): array
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