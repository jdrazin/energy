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
    make_row($datetime->format('Y-m-d H:i:s'));
}
exit;

function truncate($mysqli): void {
    $mysqli->query('TRUNCATE `dump`');
}

function make_row($mysql_datetime) {

}





