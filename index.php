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
 * Note: This middleware should be added last. It will not handle any exceptions/errors
 * for middleware added after it.
 */
$errorMiddleware = $app->addErrorMiddleware(true, true, true);
$app->get('/example', function (Request $request, Response $response, $args) {
    $response->getBody()->write('[{"label": "Category1", "value": 10}, {"label": "Category2", "value": 20}]');
    return $response->withHeader('Content-Type', 'application/json');
});
$app->get('/slots', function (Request $request, Response $response, $args) {
    $energy = new Energy(null);
    $slots = $energy->slots();
    $response->getBody()->write($slots);
    return $response->withHeader('Content-Type', 'application/json');
});
$app->post('/permute', function (Request $request, Response $response, $args) {
    $config = json_decode((string) $request->getBody(), true);
    $energy = new Energy($config);
    $energy->permute();
    $response->getBody()->write("Hello!");
    return $response;
});

// Run app
$app->run();
