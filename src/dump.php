<?php
declare(strict_types=1);
namespace Src;
use DateTime;
use DateInterval;

/*
   dumps house data to table "dump"
    - grid_w
    - battery_w
    - ev_charge_w
    - solar_w
    - load_w
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
        INTERVAL_SECONDS            = 1800;

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





