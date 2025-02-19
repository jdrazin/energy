<?php //
    declare(strict_types=1);
    namespace Src;
    require_once __DIR__ . '/../vendor/autoload.php';

    $e = empty(0);

    $x = [0, 1, 2];
    $y = [1, 3, 2];

    try {
        $cubic_spline = new CubicSpline(100);
    } catch (\Exception $e) {

    }
    $cubic_spline->x($x);
    $result = $cubic_spline->cubic_spline_y($y);
    exit(0);


	