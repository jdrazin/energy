<?php
declare(strict_types=1);
namespace Src;
require_once __DIR__ . '/vendor/autoload.php';
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

// see slim 4 documentation: https://www.slimframework.com/docs/v4/
$app = AppFactory::create();
$app->addRoutingMiddleware();

/**
 * Add Error Middleware
 *
 * @param bool                  $displayErrorDetails -> Should be set to false in production
 * @param bool                  $logErrors -> Parameter is passed to the default ErrorHandler
 * @param bool                  $logErrorDetails -> Display error details in error log
 * @param LoggerInterface|null  $logger -> Optional PSR-3 Logger
 *
 */
$app->get('/control/slots', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($slots = $energy->slots()) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($slots);
    return $response->withHeader('Content-Type', 'application/json')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->get('/control/tariff_combinations', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($tariff_combinations = $energy->tariff_combinations()) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($tariff_combinations);
    return $response->withHeader('Content-Type', 'application/json')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->get('/control/slot_command', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($slot_command = $energy->slot_command()) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($slot_command);
    return $response->withHeader('Content-Type', 'application/text')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->post('/projections', function (Request $request, Response $response) {
    $config_json = (string) $request->getBody();
    $config = json_decode($config_json, true);
    $energy = new Energy(null);
    $email = $config['email'];
    $projection_id = $energy->submitProjection($config_json, $email);
    $response->getBody()->write('Get your result at: https://www.drazin.net:8443/projections?projection=' . $projection_id . '. Will e-mail you when ready at ' . $email . '. Ciao!');
    return $response;
});
$app->run();
