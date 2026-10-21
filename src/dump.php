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
            'grid_w'        => [
                                    'entity'    => 'GRID_W',
                                    'type'      => 'ABSOLUTE'
                               ],
            'battery_w'     => [
                                    'entity'    => 'GRID_W',
                                    'type'      => 'RATE',
                                    'factor'    =>  1.0
                                ],
            'ev_charge_w'   => [
                                    'entity'    => 'GRID_W',
                                    'type'      => 'ABSOLUTE'
                               ],
            'solar_w'       => [
                                    'entity'    => 'GRID_W',
                                    'type'      => 'ABSOLUTE'
                               ],
            'load_w'        => [
                                    'entity'    => 'GRID_W',
                                    'type'      => 'ABSOLUTE'
                                ]
            ];

truncate( $mysqli = (new Root())->mysqli());
$datetime       = new DateTime(DATETIME_START);
$datetime_stop  = new DateTime(DATETIME_STOP);
$increment       = new DateInterval('PT' . INTERVAL_SECONDS . 'S');
while ($datetime < $datetime_stop) {
    $datetime->add($increment);
    make_row($mysqli, $datetime->format('Y-m-d H:i:s'));
}
exit;

function truncate($mysqli): void {
    $mysqli->query('TRUNCATE `dump`');
}

function make_row($mysqli, $mysql_datetime) {
    foreach (DESCRIPTORS as $field => $properties) {
        $entity = $properties['entity'];
        $min_max_datetime = min_max_datetime($mysqli, $mysql_datetime, $entity);
        $type   = $properties['type'];
    }
}

function min_max_datetime($mysqli, $mysql_datetime, $entity) {
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
    $min_max_datetime = [];
    foreach ($bounds as $function => $inequality) {
        $sql = "SELECT   $function(`datetime`) AS `datetime`,
                        TIMESTAMPDIFF(SECOND, MAX(`datetime`), '$mysql_datetime') AS `difference_seconds`
                   FROM  `values`
                   WHERE `entity` = '$entity' AND 
                         `datetime` $inequality '$mysql_datetime'";
        $result = $mysqli->query($sql);
        $fetch_assoc = $result->fetch_assoc();
        $min_max_datetime[$function] = $fetch_assoc['datetime'];
    }

    return $result->fetch_assoc();
}





