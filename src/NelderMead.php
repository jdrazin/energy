<?php
declare(strict_types=1);
namespace Src;

use MathPHP\Exception\BadDataException;
use MathPHP\Exception\VectorException;
use MathPHP\LinearAlgebra\Vector;

final class NelderMead {
    public float $alpha = 1.0;  // reflection
    public float $gamma = 2.0;  // expansion
    public float $rho   = 0.5;  // contraction
    public float $sigma = 0.5;  // shrink

    public int   $maxIter     = 2000;
    public int   $maxEval     = 100000;
    public float $ftol        = 1e-9;     // stop if f spread is tiny
    public float $xtol        = 1e-9;     // stop if simplex is tiny
    public float $penaltyCoef = 1e6;      // box-constraint penalty strength

    /**
     * @param callable(Vector $x): float $objective objective to MINIMIZE
     * @param float[] $x0 initial guess (n dims)
     * @param float[]|null $step step size per-dim (default = 5% of |x0_i| or 1.0 if 0)
     * @param float[]|null $lower lower bounds (optional, length n)
     * @param float[]|null $upper upper bounds (optional, length n)
     * @return array{best: float, x: float[], iters: int, evals: int}
     * @throws BadDataException|VectorException
     */
    public function minimize(
        callable $objective,
        array $x0,
        ?array $step = null,
        ?array $lower = null,
        ?array $upper = null
    ): array {
        $n = count($x0);
        if ($n < 1) {
            throw new \InvalidArgumentException('x0 must have at least 1 dimension');
        }

        // Build initial simplex: x0 plus n points offset along each dimension
        $simplex = [];
        $simplex[] = new Vector($x0);

        if ($step === null) {
            $step = array_map(fn($v) => ($v != 0.0) ? 0.05 * abs($v) : 1.0, $x0);
        }

        for ($i = 0; $i < $n; $i++) {
            $xi = $x0;
            $xi[$i] += $step[$i];
            $simplex[] = new Vector($xi);
        }

        // Evaluate
        $f = [];
        $evals = 0;
        foreach ($simplex as $v) {
            $f[] = $this->penalized($objective, $v, $lower, $upper);
            $evals++;
        }

        $iters = 0;
        while ($iters < $this->maxIter && $evals < $this->maxEval) {
            // Order simplex by f ascending
            array_multisort($f, SORT_ASC, $simplex);

            // Check termination: function spread and simplex size
            $fbest = $f[0]; $fworst = $f[$n];
            $fspread = abs($fworst - $fbest);
            $xspread = 0.0;
            $centroid = $this->centroid($simplex, $n); // excluding worst later; we compute full then recompute below
            // Simple x-spread measure
            for ($i = 0; $i <= $n; $i++) {
                $xspread = max($xspread, $simplex[0]->subtract($simplex[$i])->l2norm());
            }
            if ($fspread < $this->ftol && $xspread < $this->xtol) break;

            // Centroid excluding worst
            $centroid = $this->centroid($simplex, $n, excludeIndex: $n);

            // Reflection
            $xr = $this->affine($centroid, $simplex[$n], $this->alpha);
            $fr = $this->penalized($objective, $xr, $lower, $upper); $evals++;

            if ($fr < $f[0]) {
                // Expansion
                $xe = $this->affine($centroid, $simplex[$n], $this->gamma); // expansion
                $fe = $this->penalized($objective, $xe, $lower, $upper); $evals++;
                if ($fe < $fr) {
                    $simplex[$n] = $xe; $f[$n] = $fe;
                } else {
                    $simplex[$n] = $xr; $f[$n] = $fr;
                }
            } elseif ($fr < $f[$n-1]) {
                // Accept reflection
                $simplex[$n] = $xr; $f[$n] = $fr;
            } else {
                // Contraction
                $xc = null; $fc = null;
                if ($fr < $f[$n]) {
                    // Outside contraction
                    $xc = $this->affine($centroid, $simplex[$n], $this->rho);
                } else {
                    // Inside contraction
                    $xc = $this->affine($centroid, $simplex[$n], -$this->rho);
                }
                $fc = $this->penalized($objective, $xc, $lower, $upper); $evals++;

                if ($fc < min($f[$n], $fr)) {
                    $simplex[$n] = $xc; $f[$n] = $fc;
                } else {
                    // Shrink towards best
                    for ($i = 1; $i <= $n; $i++) {
                        $simplex[$i] = $simplex[0]->add($simplex[$i]->subtract($simplex[0])->scalarMultiply($this->sigma));
                        $f[$i] = $this->penalized($objective, $simplex[$i], $lower, $upper);
                        $evals++;
                    }
                }
            }
            $iters++;
        }

        // Final ordering
        array_multisort($f, SORT_ASC, $simplex);
        return [
            'best'  => $f[0],
            'x'     => $simplex[0]->getVector(),
            'iters' => $iters,
            'evals' => $evals,
        ];
    }

    /**
     * @throws BadDataException
     * @throws VectorException
     */
    private function centroid(array $simplex, int $n, ?int $excludeIndex = null): Vector
    {
        $sum = new Vector(array_fill(0, count($simplex[0]), 0.0));
        $count = 0;
        for ($i = 0; $i <= $n; $i++) {
            if ($excludeIndex !== null && $i === $excludeIndex) continue;
            $sum = $sum->add($simplex[$i]);
            $count++;
        }
        return $sum->scalarMultiply(1.0 / $count);
    }

    // Build point: centroid + coeff * (centroid - worst)
    private function affine(Vector $centroid, Vector $worst, float $coeff): Vector
    {
        return $centroid->add($centroid->subtract($worst)->scalarMultiply($coeff));
    }

    private function penalized(callable $objective, Vector $x, ?array $lb, ?array $ub): float
    {
        $fx = $objective($x);
        if ($lb === null && $ub === null) return $fx;

        // simple quadratic penalties if out of bounds
        $pen = 0.0;
        $arr = $x->getVector();
        $n = count($arr);
        for ($i = 0; $i < $n; $i++) {
            if ($lb !== null && $arr[$i] < $lb[$i]) {
                $d = $lb[$i] - $arr[$i];
                $pen += $d * $d;
            }
            if ($ub !== null && $arr[$i] > $ub[$i]) {
                $d = $arr[$i] - $ub[$i];
                $pen += $d * $d;
            }
        }
        return $fx + $this->penaltyCoef * $pen;
    }
}
