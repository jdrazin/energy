<?php //
    declare(strict_types=1);
    namespace Src;
    require_once __DIR__ . '/../vendor/autoload.php';


    $x = [0, 1, 2];
    $y = [1, 3, 2];

	$cubic_spline = new CubicSpline(3);
    $cubic_spline->x($x);



	