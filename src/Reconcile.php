<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Keyed list reconciliation — mirrors native's canvas_widget_reconcile diff.
 *
 * Given the previous and next child lists, matches elements by a stable key
 * (`key` prop, falling back to `id`, then list index) and reports which items
 * are unchanged, moved, added, or removed. This lets transient per-item state
 * (highlight, scroll, animation) survive reorders and model updates because the
 * identity is preserved rather than positional.
 *
 * Each entry: ['key'=>string, 'prev'=>?Element, 'next'=>?Element, 'status'=>string]
 * where status is one of 'same' | 'moved' | 'added' | 'removed'.
 */
final class Reconcile
{
    /**
     * @param array<int,Element> $prev
     * @param array<int,Element> $next
     * @return list<array{key:string,prev:?Element,next:?Element,status:string}>
     */
    public static function keyed(array $prev, array $next): array
    {
        $keyOf = static fn(Element $e, int $i): string => (string) ($e->prop('key') ?? $e->prop('id') ?? '@' . $i);

        $prevByKey = [];
        $prevIdx = [];
        foreach ($prev as $i => $e) {
            $k = $keyOf($e, $i);
            $prevByKey[$k] = $e;
            $prevIdx[$k] = $i;
        }
        $nextByKey = [];
        $nextIdx = [];
        foreach ($next as $i => $e) {
            $k = $keyOf($e, $i);
            $nextByKey[$k] = $e;
            $nextIdx[$k] = $i;
        }

        $out = [];
        foreach ($next as $i => $e) {
            $k = $keyOf($e, $i);
            $had = isset($prevByKey[$k]);
            $out[] = [
                'key' => $k,
                'prev' => $had ? $prevByKey[$k] : null,
                'next' => $e,
                'status' => $had ? ($prevIdx[$k] === $i ? 'same' : 'moved') : 'added',
            ];
        }
        foreach ($prev as $i => $e) {
            $k = $keyOf($e, $i);
            if (!isset($nextByKey[$k])) {
                $out[] = ['key' => $k, 'prev' => $e, 'next' => null, 'status' => 'removed'];
            }
        }
        return $out;
    }
}
