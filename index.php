<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

require_once __DIR__ . '/Battery.php';
require_once __DIR__ . '/Boiler.php';
require_once __DIR__ . '/Climate.php';
require_once __DIR__ . '/Demand.php';
require_once __DIR__ . '/EmonCms.php';
require_once __DIR__ . "/Energy.php";
require_once __DIR__ . "/Powers.php";
require_once __DIR__ . "/GivEnergy.php";
require_once __DIR__ . '/HeatPump.php';
require_once __DIR__ . '/Inverter.php';
require_once __DIR__ . '/MetOffice.php';
require_once __DIR__ . '/Npv.php';
require_once __DIR__ . "/Octopus.php";
require_once __DIR__ . "/EnergyCost.php";
require_once __DIR__ . '/ParameterPermutations.php';
require_once __DIR__ . '/DbSlots.php';
require_once __DIR__ . '/Slots.php';
require_once __DIR__ . '/Solar.php';
require_once __DIR__ . '/SolarCollectors.php';
require_once __DIR__ . '/Solcast.php';
require_once __DIR__ . '/Supply.php';
require_once __DIR__ . '/ThermalInertia.php';
require_once __DIR__ . '/ThermalTank.php';
require_once __DIR__ . '/Time.php';

require __DIR__ . '/vendor/autoload.php';

// see slim 4 documentation: https://www.slimframework.com/docs/v4/
$app = AppFactory::create();

/**
 * The routing middleware should be added earlier than the ErrorMiddleware
 * Otherwise exceptions thrown from it will not be handled by the middleware
 */
$app->addRoutingMiddleware();


/**
 * Add Error Middleware
 *
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null  $logger -> Optional PSR-3 Logger
 *
 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->post('/permute', function (Request $request, Response $response, $args) {
    $energy = new Energy();
    $energy->permute(json_decode($request->getBody(), true));
    $response->getBody()->write("Hello!");
    return $response;
});

// Run app
$app->run();
