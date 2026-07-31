<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Design tokens (mirrors native's tokens.zig). Colors are normalized 0..1 RGB
 * triples (Cairo's range); non-color tokens drive geometry and typography.
 * A theme is just an associative array, so apps can register bespoke themes
 * without subclassing.
 */
final class Theme
{
    public const LIGHT = 'light';
    public const DARK = 'dark';

    /** @var array<string,array<string,mixed>> */
    private static array $registry = [
        self::LIGHT => [
            'bg' => [0.98, 0.98, 0.99],
            'surface' => [1.0, 1.0, 1.0],
            'surfaceAlt' => [0.94, 0.94, 0.96],
            'text' => [0.10, 0.10, 0.12],
            'textMuted' => [0.50, 0.50, 0.55],
            'border' => [0.70, 0.70, 0.75],
            'accent' => [0.20, 0.40, 0.90],
            'accentSoft' => [0.85, 0.90, 0.98],
            'danger' => [0.85, 0.15, 0.20],
            'selected' => [0.85, 0.90, 0.98],
            'scrollbarTrack' => [0.78, 0.78, 0.82],
            'scrollbarThumb' => [0.55, 0.55, 0.62],
            'scrollbarRadius' => 4,
            'scrollbarThickness' => 8,
            'radius' => 6,
            'font' => 'sans',
            'fontSize' => 13,
        ],
        self::DARK => [
            'bg' => [0.13, 0.13, 0.15],
            'surface' => [0.20, 0.20, 0.23],
            'surfaceAlt' => [0.26, 0.26, 0.30],
            'text' => [0.95, 0.95, 0.97],
            'textMuted' => [0.60, 0.60, 0.66],
            'border' => [0.36, 0.36, 0.42],
            'accent' => [0.40, 0.60, 1.00],
            'accentSoft' => [0.22, 0.30, 0.50],
            'danger' => [0.90, 0.35, 0.40],
            'selected' => [0.25, 0.34, 0.55],
            'scrollbarTrack' => [0.30, 0.30, 0.36],
            'scrollbarThumb' => [0.55, 0.55, 0.64],
            'scrollbarRadius' => 4,
            'scrollbarThickness' => 8,
            'radius' => 6,
            'font' => 'sans',
            'fontSize' => 13,
        ],
    ];

    /** Register or override a named theme. */
    public static function register(string $name, array $tokens): void
    {
        self::$registry[$name] = $tokens;
    }

    /** Resolve a theme by name (falls back to light). Accepts a raw token array too. */
    public static function get(string|array $theme): array
    {
        if (is_array($theme)) {
            return $theme;
        }
        return self::$registry[$theme] ?? self::$registry[self::LIGHT];
    }

    /** Names of every registered theme. */
    public static function names(): array
    {
        return array_keys(self::$registry);
    }
}
