<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Animation primitives: easing curves, progress clamping and linear
 * interpolation. Mirrors native's Animation module — the backend's paint loop
 * is the ticker (it advances a clock and asks Animation to interpolate).
 *
 * An animation spec (attached to an Element via `Ui::animate`) is a list of:
 *   ['key' => 'opacity'|'x'|'y'|'scale', 'from' => float, 'to' => float,
 *    'duration' => int ms, 'delay' => int ms, 'easing' => string]
 */
final class Animation
{
    /** Normalized progress in [0,1] for an animation at $elapsedMs. */
    public static function progress(float $elapsedMs, float $durationMs, float $delayMs = 0.0): float
    {
        if ($durationMs <= 0.0) {
            return 1.0;
        }
        $t = ($elapsedMs - $delayMs) / $durationMs;
        return max(0.0, min(1.0, $t));
    }

    public static function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }

    /** Easing curve $name evaluated at normalized $t in [0,1]. */
    public static function ease(string $name, float $t): float
    {
        $t = max(0.0, min(1.0, $t));
        if ($name === 'easeOutBack') {
            $c1 = 1.70158;
            $c3 = $c1 + 1.0;
            $p = $t - 1.0;
            return 1.0 + $c3 * $p * $p * $p + $c1 * $p * $p;
        }
        return match ($name) {
            'linear' => $t,
            'easeIn' => $t * $t,
            'easeOut' => $t * (2.0 - $t),
            'easeInOut' => $t < 0.5 ? 2.0 * $t * $t : -1.0 + (4.0 - 2.0 * $t) * $t,
            'step' => $t >= 1.0 ? 1.0 : 0.0,
            default => $t,
        };
    }

    /**
     * Interpolate one element's animation spec at $elapsedMs. Backend-agnostic:
     * the Canvas host and the headless Reference renderer both call this so the
     * interpolation math lives in exactly one place. Returns the same shape
     * Canvas stored in $animStates: ['alpha','dx','dy','scale','done'].
     */
    public static function frame(array $spec, float $elapsedMs): array
    {
        $dx = 0.0; $dy = 0.0; $scale = 1.0; $alpha = 1.0; $done = true;
        foreach ($spec as $s) {
            $dur = (float)($s['duration'] ?? 1000);
            $delay = (float)($s['delay'] ?? 0);
            $p = self::progress($elapsedMs, $dur, $delay);
            if ($p < 1.0) {
                $done = false;
            }
            $e = self::ease((string)($s['easing'] ?? 'linear'), $p);
            $from = (float)($s['from'] ?? 0);
            $to = (float)($s['to'] ?? 1);
            $v = $from + ($to - $from) * $e;
            switch ($s['key'] ?? 'opacity') {
                case 'x':
                    $dx = $v;
                    break;
                case 'y':
                    $dy = $v;
                    break;
                case 'scale':
                    $scale = $v;
                    break;
                default:
                    $alpha = $v;
            }
        }
        return ['alpha' => $alpha, 'dx' => $dx, 'dy' => $dy, 'scale' => $scale, 'done' => $done];
    }
}
