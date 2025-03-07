<?php
declare(strict_types=1);
namespace Src;

require_once __DIR__ . '/../vendor/autoload.php';

error_reporting(E_ALL);
set_time_limit(36000);
ini_set('mysql.connect_timeout', '36000');
ini_set('max_execution_time', '36000');
ini_set('mysql.connect_timeout','36000');

(new SMTPEmail())->email(['subject'     => 'EnergyController: Error',
                          'html'      => false,
                          'bodyHTML'  => 'This is a test',
                          'bodyAlt'   => 'This is a test']);
exit(0);

