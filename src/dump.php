<?php
declare(strict_types=1);
namespace Src;
use DateTime;
use DateInterval;

/*
   dumps house data to table "dump"
*/

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * "slither" is single battery charging control command, sent multiple times during each time slot
 */
error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

const   DATETIME_START              = '2025-10-20 00:00:00',
        DATETIME_STOP               = '2026-10-20 00:00:00',
        INTERVAL_SECONDS            = 1800,
        DESCRIPTORS = [
                        [
                            'field'     => 'grid_w',
                            'entity'    => 'GRID_W',
                            'type'      => 'ABSOLUTE'
                        ],
                        [
                            'field'     => 'battery_charge_w',
                            'entity'    => 'BATTERY_LEVEL_KWH',
                            'type'      => 'RATE',
                            'factor'    =>  3.6E6
                        ],
                        [
                            'field'     => 'ev_charge_w',
                            'entity'    => 'LOAD_EV_W',
                            'type'      => 'ABSOLUTE'
                        ],
                        [
                            'field'     => 'load_w',
                            'entity'    => 'LOAD_HOUSE_W',
                            'type'      => 'ABSOLUTE'
                        ],
                        [
                            'field'     => 'solar_w',
                            'entity'    => 'SOLAR_W',
                            'type'      => 'ABSOLUTE'
                        ],
                    ];

truncate( $mysqli = (new Root())->mysqli());
$datetime       = new DateTime(DATETIME_START);
$datetime_stop  = new DateTime(DATETIME_STOP);
$increment      = new DateInterval('PT' . INTERVAL_SECONDS . 'S');
while ($datetime < $datetime_stop) {
    $datetime->add($increment);
    make_row($mysqli, $datetime->format('Y-m-d H:i:s'));
}
exit;

function truncate($mysqli): void {
    $mysqli->query('TRUNCATE `dump`');
}

function make_row($mysqli, $mysql_datetime): void
{
    $values = [];
    foreach (DESCRIPTORS as $descriptor) {
        $field  = $descriptor['field'];
        $entity = $descriptor['entity'];
        switch($descriptor['type']) {
            case 'ABSOLUTE': {
                $values[$field] = value_absolute($mysqli, $mysql_datetime, $entity);
                break;
            }
            case 'RATE': {
                $values[$field] = $descriptor['factor'] * value_rate($mysqli, $mysql_datetime, $entity);
                break;
            }
            default: {

            }
        }
    }
}

function value_absolute($mysqli, $mysql_datetime, $entity):float {
    $bounds = [
                'LOWER' =>  [
                                'function'      => 'MAX',
                                'inequality'    => '<='
                            ],
                'UPPER' =>  [
                                'function'      => 'MIN',
                                'inequality'    => '>='
                            ]
               ];
    $upper_lower_datetime = [];
    foreach ($bounds as $bound => $properties) {
        $function   = $properties['function'];
        $inequality = $properties['inequality'];
        $sql = "SELECT   $function(`datetime`) AS `datetime`,
                        TIMESTAMPDIFF(SECOND, '$mysql_datetime', $function(`datetime`)) AS `difference_seconds`
                   FROM  `values`
                   WHERE `entity` = '$entity' AND 
                         `datetime` $inequality '$mysql_datetime' AND
                         `type` = 'MEASURED'";
        $result = $mysqli->query($sql);
        $fetch_assoc = $result->fetch_assoc();
        $datetime           = $fetch_assoc['datetime'];
        $difference_seconds = intval($fetch_assoc['difference_seconds']);
        $value              = value($mysqli, $datetime, $entity);
        if ($difference_seconds == 0) {     // no need to interpolate if reading found for exact time
            return (int) round($value);
        }
        $upper_lower_datetime[$bound] = [
                                        'datetime'           => $datetime,
                                        'value'              => $value,
                                        'difference_seconds' => $difference_seconds
                                    ];
    }
    // interpolate
    $a   = $upper_lower_datetime['LOWER']['difference_seconds'];
    $b   = $upper_lower_datetime['UPPER']['difference_seconds'];
    $va  = $upper_lower_datetime['LOWER']['value'];
    $vb  = $upper_lower_datetime['UPPER']['value'];
    $value_interpolated = $va + $a * (($vb-$va)/($a - $b));
    return round($value_interpolated);
}

function value_rate($mysqli, $mysql_datetime, $entity):float {
    $signs = ['-', '+'];
    $interval_half = INTERVAL_SECONDS/2;
    $upper_lower_datetime = [];
    foreach ($signs as $sign) {
        $sql = "SELECT   `datetime`
                   FROM  `values`
                   WHERE `entity` = '$entity' AND 
                         `type` = 'MEASURED'
                   ORDER BY ABS(TIMESTAMPDIFF(SECOND, '$mysql_datetime' $sign INTERVAL $interval_half SECOND, `datetime`))
                   LIMIT 1";
        $result = $mysqli->query($sql);
        $fetch_assoc        = $result->fetch_assoc();
        $datetime           = $fetch_assoc['datetime'];
        $value              = value($mysqli, $datetime, $entity);
        $upper_lower_datetime[$sign] = [
            'datetime'           => $datetime,
            'value'              => $value
        ];
    }
    // calculate rate
    $diff_seconds = strtotime($upper_lower_datetime['+']['datetime']) - strtotime($upper_lower_datetime['-']['datetime']);
    $va   = $upper_lower_datetime['-']['value'];
    $vb   = $upper_lower_datetime['+']['value'];
    if (($rate = $vb - $va) && $diff_seconds) {
        $rate /= $diff_seconds;
    }
    return $rate;
}

function value($mysqli, $mysql_datetime, $entity): float {
    $sql = "SELECT   `value`
               FROM  `values`
               WHERE `entity` = '$entity' AND 
                     `datetime` = '$mysql_datetime'";
    $result = $mysqli->query($sql);
    return floatval($result->fetch_assoc()['value']);
}




