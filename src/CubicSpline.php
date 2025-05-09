<?php
namespace Src;
use Exception;

/*
 * wrapper to Python scipy cubic spline library
 *
 * see https://pythonnumericalmethods.studentorg.berkeley.edu/notebooks/chapter17.03-Cubic-Spline-Interpolation.html
 *
 * *** NOTE: samples must be evenly and contiguously spaced along x-axis ***
 */

class CubicSpline extends Root
{
    const string  PYTHON_SCRIPT_COMMAND   = 'python3 /var/www/html/energy/src/cubicSpline.py';

    public int $multiple;

    public function __construct($multiple) {
        parent::__construct();
        $this->multiple = $multiple;
    }

    /**
     * @param $y_array
     * @return array
     * @throws Exception
     */
    public function cubic_spline_y($y_array): array {
        $y_array = $this->interpolate($this->exterpolate($y_array));
        $command = self::PYTHON_SCRIPT_COMMAND . ' ';
        $command .= 'multiple= '  . $this->multiple;
        $command .= ' size= '     . count($y_array);
        $command .= ' elements: ';
        foreach ($y_array as $v) {
             $command .= $v . ' ';
        }
        $output_json = shell_exec($command);               // execute Python command and capture output
        if (($y_array = json_decode($output_json, true)['y'] ?? false) === false) {
           $message = $this->errMsg(__CLASS__, __FUNCTION__, __LINE__, 'Bad cubic spline');
           $this->logDb('MESSAGE', $message, null, 'ERROR');
           throw new Exception($message);
        }
        return $y_array;
    }

    private function exterpolate($array): array {
        foreach ($array as $key => $value) {
            if (!is_numeric($value)) {
                $array[$key] = null;
            }
        }
        $last_value_forward = null;
        $last_value_reverse = null;
        $count = count($array);
        for ($i = 0; $i < $count; $i++) {
            $value_forward = $array[$i];
            if (is_numeric($value_forward)) {
                $last_value_forward = $value_forward;
            }
            if (($i == $count-1) && !is_numeric($value_forward)) {
                $array[$i] = $last_value_forward;
            }
            $value_reverse = $array[$count - $i -1];
            if (is_numeric($value_reverse)) {
                $last_value_reverse = $value_reverse;
            }
            $t = ($i == $count-1);
            $u = !is_numeric($value_reverse);
            $v = ($value_reverse != 0);
            if (($i == $count-1) && !is_numeric($value_reverse)) {
                $array[0] = $last_value_reverse;
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

