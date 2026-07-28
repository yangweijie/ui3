<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\FFI;

use FFI;

/**
 * Thin FFI wrapper around Cairo (the 2D drawing backend used by the canvas
 * host). Loaded directly via FFI::cdef so it is independent of phpc's
 * whitelist. The cairo_t* handed to our draw callback comes from the same
 * libcairo the host links, so cross-FFI calls are safe.
 */
final class Cairo
{
    private static ?FFI $ffi = null;
    private static ?FFI\CData $measureCr = null;

    private const HEADER = <<<'C'
typedef struct _cairo cairo_t;
typedef struct _cairo_surface cairo_surface_t;
typedef struct _cairo_text_extents {
    double x_bearing, y_bearing, width, height, x_advance, y_advance;
} cairo_text_extents_t;

void cairo_set_source_rgb(cairo_t* cr, double r, double g, double b);
void cairo_set_source_rgba(cairo_t* cr, double r, double g, double b, double a);
void cairo_rectangle(cairo_t* cr, double x, double y, double w, double h);
void cairo_fill(cairo_t* cr);
void cairo_stroke(cairo_t* cr);
void cairo_move_to(cairo_t* cr, double x, double y);
void cairo_line_to(cairo_t* cr, double x, double y);
void cairo_set_line_width(cairo_t* cr, double w);
void cairo_select_font_face(cairo_t* cr, const char* family, int slant, int weight);
void cairo_set_font_size(cairo_t* cr, double size);
void cairo_show_text(cairo_t* cr, const char* text);
void cairo_text_extents(cairo_t* cr, const char* text, cairo_text_extents_t* extents);
cairo_surface_t* cairo_image_surface_create(int format, int width, int height);
cairo_t* cairo_create(cairo_surface_t* surface);
void cairo_destroy(cairo_t* cr);
void cairo_surface_destroy(cairo_surface_t* surface);
C;

    public static function ffi(): FFI
    {
        if (self::$ffi === null) {
            self::$ffi = FFI::cdef(self::HEADER, self::lib());
        }
        return self::$ffi;
    }

    private static function lib(): string
    {
        $cands = [
            '/opt/homebrew/lib/libcairo.dylib',
            '/usr/local/lib/libcairo.dylib',
            '/usr/lib/x86_64-linux-gnu/libcairo.so.2',
            '/usr/lib/libcairo.so.2',
            'libcairo.dylib',
            'libcairo.so.2',
        ];
        foreach ($cands as $c) {
            if (file_exists($c)) {
                return $c;
            }
        }
        return 'libcairo';
    }

    public static function fillRect($cr, float $x, float $y, float $w, float $h, float $r, float $g, float $b, float $a = 1.0): void
    {
        $f = self::ffi();
        if ($a >= 1.0) {
            $f->cairo_set_source_rgb($cr, $r, $g, $b);
        } else {
            $f->cairo_set_source_rgba($cr, $r, $g, $b, $a);
        }
        $f->cairo_rectangle($cr, $x, $y, $w, $h);
        $f->cairo_fill($cr);
    }

    public static function strokeRect($cr, float $x, float $y, float $w, float $h, float $r, float $g, float $b, float $lw = 1.0): void
    {
        $f = self::ffi();
        $f->cairo_set_source_rgb($cr, $r, $g, $b);
        $f->cairo_set_line_width($cr, $lw);
        $f->cairo_rectangle($cr, $x, $y, $w, $h);
        $f->cairo_stroke($cr);
    }

    public static function line($cr, float $x1, float $y1, float $x2, float $y2, float $r, float $g, float $b, float $lw = 1.0): void
    {
        $f = self::ffi();
        $f->cairo_set_source_rgb($cr, $r, $g, $b);
        $f->cairo_set_line_width($cr, $lw);
        $f->cairo_move_to($cr, $x1, $y1);
        $f->cairo_line_to($cr, $x2, $y2);
        $f->cairo_stroke($cr);
    }

    /** @return array{w:float,h:float,x_advance:float} */
    public static function textExtents($cr, string $text): array
    {
        $f = self::ffi();
        $ext = $f->new('cairo_text_extents_t');
        $f->cairo_text_extents($cr, $text, \FFI::addr($ext));
        return ['w' => $ext->width, 'h' => $ext->height, 'x_advance' => $ext->x_advance];
    }

    /** Pick a font covering CJK glyphs on the host platform. The old hardcoded
     *  'Helvetica' had no Chinese glyphs and rendered tofu boxes. */
    private static function defaultFamily(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Darwin' => 'PingFang SC',
            'Windows' => 'Microsoft YaHei',
            'Linux' => 'Noto Sans CJK SC',
            default => 'sans-serif',
        };
    }

    public static function text($cr, float $x, float $y, string $text, float $size, float $r, float $g, float $b, ?string $family = null): void
    {
        $f = self::ffi();
        $f->cairo_select_font_face($cr, $family ?? self::defaultFamily(), 0, 0);
        $f->cairo_set_font_size($cr, $size);
        $f->cairo_set_source_rgb($cr, $r, $g, $b);
        $f->cairo_move_to($cr, $x, $y);
        $f->cairo_show_text($cr, $text);
    }

    /** Measure text with real cairo_text_extents (replaces width estimates). */
    public static function measureText(string $text, float $size, ?string $family = null): array
    {
        $cr = self::measureCr();
        if ($cr === null) {
            return ['w' => mb_strlen($text) * $size * 0.55, 'h' => (int)($size * 1.4)];
        }
        $f = self::ffi();
        $f->cairo_select_font_face($cr, $family ?? self::defaultFamily(), 0, 0);
        $f->cairo_set_font_size($cr, $size);
        $ext = $f->new('cairo_text_extents_t');
        $f->cairo_text_extents($cr, $text, \FFI::addr($ext));
        return [
            'w' => (float) $ext->x_advance,
            'h' => $ext->height > 0 ? (float) $ext->height : (float) $size * 1.2,
        ];
    }

    private static function measureCr(): ?FFI\CData
    {
        if (self::$measureCr === null) {
            $f = self::ffi();
            $surf = $f->cairo_image_surface_create(0, 16, 16);
            if ($surf === null) {
                return null;
            }
            self::$measureCr = $f->cairo_create($surf);
            $f->cairo_surface_destroy($surf);
        }
        return self::$measureCr;
    }
}
