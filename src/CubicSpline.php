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
    public array $x, $y;
    public function __construct($multiple) {
        $this->multiple = $multiple;
    }
    public function x($array) {
        $this->x = $array;
    }

    /**
     * @param $y
     * @return array
     */
    public function cubic_spline_y($y): array {
        $input = [
            0 => null,
            1 => 30,
            2 => 0,
            3 => '',
            4 => '',
            5 => '',
            6 => 40,
            7 => '',
            8 => '',
            9 => '10',
            10 => 5,
            11 => null,
            12 => null,
            13 => '',
            14 => '5',
            15 => '0',
            16 => '',
            17 => '',
            18 => '100',
            19 => '',
            20 => 'baz'];
        $result = $this->exterpolate($input);
        $result = $this->interpolate($input);
        $this->y = $y;
        return $this->x;
    }

    private function remove_nulls(): array {
    }

    private function exterpolate($array): array {
        $last_value = null;
        $count = count($array);
        for ($i = 0; $i < $count-1; $i++) {
            $value = $array[$i];
            if (is_numeric($value)) {
                $last_value = $value;
            }
            if (($i == $count-1) && !is_numeric($value) && $value != 0) {
                $array[$i] = $last_value;
            }
        }
        return $array;
    }

    /*
     * interpolates missing numbers
     */
    private function interpolate($row = [] ) {
        if (!is_array( $row ) || empty( $row )) {
            return $row;
        }

        $items = [];
        $item  = array(
            'start'         => null,
            'end'           => null,
            'empty_indexes' => [],
        );
        foreach ( $row as $index => $current_val ) {
            $prev_val = $row[ $index - 1 ] ?? null;
            $next_val = $row[ $index + 1 ] ?? null;
            if ( empty( $current_val ) && $current_val != '0' ) {

                // Look behind
                if ( ( ! empty( $prev_val ) || $prev_val == '0' ) && is_numeric( $prev_val ) ) {
                    $item['start'] = $prev_val + 0; // + 0 so PHP can do smart numeric type casting
                }

                // Look ahead
                if ( ( ! empty( $next_val ) || $next_val == '0' ) && is_numeric( $next_val ) ) {
                    $item['end'] = $next_val + 0; // + 0 so PHP can do smart numeric type casting
                }

                $item['empty_indexes'][] = $index;

                // If we have a start and end value we can reset for a new item
                if (
                    ( ! empty( $item['start'] ) || $item['start'] === 0 ) &&
                    ( ! empty( $item['end'] ) || $item['end'] === 0 )
                ) {
                    $items[] = $item;
                    $item    = array(
                        'start'         => null,
                        'end'           => null,
                        'empty_indexes' => [],
                    );
                }

                // Bad data to interpolate, reset for a new item
                if (
                    ( empty( $item['start'] ) && $item['start'] !== 0 ) &&
                    ( ! empty( $item['end'] ) || $item['end'] === 0 )
                ) {
                    $item = array(
                        'start'         => null,
                        'end'           => null,
                        'empty_indexes' => [],
                    );
                }
            }
        }
        foreach ( $items as $item ) {
            if ( $item['start'] === $item['end'] ) {
                foreach ( $item['empty_indexes'] as $row_index ) {
                    $row[ $row_index ] = strval( $item['start'] );
                }
            } else {
                $numerator = $item['end'] - $item['start'];
                $divisor   = count( $item['empty_indexes'] ) + 1;
                $step      = $numerator / $divisor;
                foreach ( $item['empty_indexes'] as $index => $row_index ) {
                    $incremental_value = $step * ( $index + 1 );
                    $row[ $row_index ] = $item['start'] + $incremental_value;
                    $row[ $row_index ] = strval( $row[ $row_index ] );
                }
            }
        }
        return $row;
    }
}

