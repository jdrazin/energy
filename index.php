<?php
declare(strict_types=1);
namespace Src;
require_once __DIR__ . '/vendor/autoload.php';

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;

const SERVER_EXTERNAL_IP_ADDRESS_PORT = "88.202.150.174:8444";

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
$app->get('/info', function (Request $request, Response $response) {
    ob_start();
    phpinfo();
    $phpinfo = ob_get_clean();
    $response->getBody()->write($phpinfo);
    return $response->withHeader('Content-Type', 'text/html');
});
$app->get('/control/slots', function (Request $request, Response $response) {
    $energy = new Energy(null);
    $cubic_spline = isset($_GET['cubic_spline']) && $_GET['cubic_spline'];
    if (($slots = $energy->slots($cubic_spline)) === false) {
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
$app->get('/control/slot_solution', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($slot_solution = $energy->slot_solution()) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($slot_solution);
    return $response->withHeader('Content-Type', 'application/text')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->get('/projections/text', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($projection = $energy->get_text((int) $_GET['id'])) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($projection);
    return $response->withHeader('Content-Type', 'application/text')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->get('/projections/projection', function (Request $request, Response $response) {
    $energy = new Energy(null);
    if (($projection = $energy->get_projection((int) $_GET['id'])) === false) {
        return $response->withStatus(401);
    }
    $response->getBody()->write($projection);
    return $response->withHeader('Content-Type', 'application/text')->withHeader('Access-Control-Allow-Origin', '*');
});
$app->post('/projections', function (Request $request, Response $response) {  // submit json
    $config_json = (string) $request->getBody();
    $crc32  = crc32($config_json);
    $config = json_decode($config_json, true);
    $energy = new Energy(null);
    if (($projection_id = $energy->submitProjection($crc32, $config)) === false) {
        $code    = 401;
        $message = 'You\'re not authorised, see https://renewable-visions.com/submitting-a-request-to-my-server/';
    }
    else {
        $code    = 201;
        $email   = $config['email'] ?? false;
        $message = 'Get your result at: https://' . SERVER_EXTERNAL_IP_ADDRESS_PORT . '/projection.html?id=' . $projection_id . ' ' . ($email ? ' Will e-mail you when ready at ' . $email . '.' : '');
        $message .= ' Error handling is work in progress, so you may not get an adequate explanation if your simulation fails.';
    }
    $body               = [];
    $body['message']    = $message;
    $json_body          = json_encode($body, JSON_PRETTY_PRINT);
    $response->getBody()->write($json_body);
    return $response->withHeader('Content-Type', 'application/json')->withStatus($code);
});
$app->run();
