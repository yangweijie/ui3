<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * A resident animation ticker — a frame clock independent of any native window.
 *
 * The Canvas host drives animations from its own frame loop; but headless /
 * pure-PHP backends (Reference) have no such loop. Ticker fills that gap: it
 * advances a monotonic clock and calls $onFrame($t) once per frame until
 * $durationSec elapses (or $onFrame returns false). The default clock is the
 * monotonic hrtime, but a callable can be injected so tests are deterministic
 * and don't actually sleep.
 */
final class Ticker
{
    /** Frames emitted since the last run() — handy for tests / observation. */
    public int $frames = 0;

    /** @var callable():float */
    private $now;
    private float $start = 0.0;

    /** @param ?callable():float $now injectable clock source (seconds); defaults to hrtime. */
    public function __construct(?callable $now = null)
    {
        $this->now = $now ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * Drive $onFrame(float $t) from t=0 at a fixed $fps until $durationSec.
     * If a frame callback returns explicit false, the loop stops early.
     * Returns the number of frames emitted.
     *
     * @param \Closure(float):(bool|void) $onFrame
     */
    public function run(\Closure $onFrame, float $durationSec, float $fps = 60.0): int
    {
        $this->start = ($this->now)();
        $this->frames = 0;
        $frameDur = $fps > 0 ? 1.0 / $fps : 0.0;

        while (true) {
            $t = ($this->now)() - $this->start;
            if ($t >= $durationSec) {
                $onFrame($durationSec);
                $this->frames++;
                break;
            }
            $cont = $onFrame($t);
            $this->frames++;
            if ($cont === false) {
                break;
            }
            if ($frameDur > 0.0) {
                $sleepUs = (int) (($this->start + $this->frames * $frameDur - ($this->now)()) * 1e6);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }
        }

        return $this->frames;
    }

    /** Seconds elapsed since run() started (or since construction if never run). */
    public function elapsed(): float
    {
        return ($this->now)() - $this->start;
    }
}
