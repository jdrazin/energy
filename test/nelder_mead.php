<?php
// see https://chatgpt.com/c/689e483a-8e68-8324-b1a9-4273c7f33a25
declare(strict_types=1);
namespace Src;
require_once __DIR__ . '/../vendor/autoload.php';

use MathPHP\Exception\BadDataException;
use MathPHP\Exception\VectorException;
use MathPHP\LinearAlgebra\Vector;

$nm = new NelderMead();
$nm->maxIter = 5000;       // allow more iterations for 24D
$nm->maxEval = 200000;

$n  = 24;
$x0 = array_fill(0, $n, 5.0);
$lb = array_fill(0, $n, -10.0);
$ub = array_fill(0, $n,  10.0);
$step = array_fill(0, $n, 1.0);

// Objective: sum((x_i - 3)^2) with mild noise
$objective = function (Vector $x): float {
    $v = $x->getVector();
    $sum = 0.0;
    foreach ($v as $xi) {
        $sum += ($xi - 3.0) * ($xi - 3.0);
    }
    return $sum + 1e-6 * mt_rand() / mt_getrandmax(); // optional noise
};

try {
    $result = $nm->minimize($objective, $x0, $step, $lb, $ub);
}
catch (BadDataException|VectorException $e) {

}

printf("Best f: %.6g\n", $result['best']);
printf("x*: [%s]\n", implode(', ', array_map(fn($z)=>sprintf('%.4f',$z), $result['x'])));
printf("iters: %d evals: %d\n", $result['iters'], $result['evals']);
