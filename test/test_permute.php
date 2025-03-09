<?php
declare(strict_types=1);
namespace Src;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

define('CONFIG_PATH', '/var/www/html/energy/config.json');

$config_json = file_get_contents(CONFIG_PATH);
$config = json_decode($config_json, true);
$energy = new Energy(null);
$email = $config['email'];
try {
    $projection_id = $energy->submitJob($config_json, $email);
} catch (\Exception $e) {

}
exit(0);

