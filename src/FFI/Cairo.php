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
void cairo_push_group(cairo_t* cr);
void cairo_pop_group_to_source(cairo_t* cr);
void cairo_paint_with_alpha(cairo_t* cr, double alpha);
void cairo_save(cairo_t* cr);
void cairo_restore(cairo_t* cr);
void cairo_new_path(cairo_t* cr);
void cairo_close_path(cairo_t* cr);
void cairo_arc(cairo_t* cr, double xc, double yc, double radius, double angle1, double angle2);
void cairo_clip(cairo_t* cr);
void cairo_paint(cairo_t* cr);
void cairo_set_source_surface(cairo_t* cr, cairo_surface_t* surface, double x, double y);
void cairo_surface_mark_dirty_rectangle(cairo_surface_t* surface, int x, int y, int width, int height);
cairo_surface_t* cairo_get_target(cairo_t* cr);
void cairo_surface_flush(cairo_surface_t* surface);
unsigned char* cairo_image_surface_get_data(cairo_surface_t* surface);
int cairo_image_surface_get_width(cairo_surface_t* surface);
int cairo_image_surface_get_height(cairo_surface_t* surface);
int cairo_image_surface_get_stride(cairo_surface_t* surface);
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

    /**
     * Filled rounded rectangle (mirrors native's fillRoundedRect). Used by the
     * scrollbar track/thumb so the bar is themeable via Design Tokens.
     */
    public static function fillRoundedRect(
        $cr, float $x, float $y, float $w, float $h, float $radius,
        float $r, float $g, float $b, float $a = 1.0
    ): void {
        $f = self::ffi();
        $rad = min($radius, $w / 2.0, $h / 2.0);
        if ($rad <= 0.5) {
            self::fillRect($cr, $x, $y, $w, $h, $r, $g, $b, $a);
            return;
        }
        if ($a >= 1.0) {
            $f->cairo_set_source_rgb($cr, $r, $g, $b);
        } else {
            $f->cairo_set_source_rgba($cr, $r, $g, $b, $a);
        }
        $f->cairo_new_path($cr);
        $f->cairo_arc($cr, $x + $w - $rad, $y + $rad, $rad, -M_PI / 2, 0);
        $f->cairo_arc($cr, $x + $w - $rad, $y + $h - $rad, $rad, 0, M_PI / 2);
        $f->cairo_arc($cr, $x + $rad, $y + $h - $rad, $rad, M_PI / 2, M_PI);
        $f->cairo_arc($cr, $x + $rad, $y + $rad, $rad, M_PI, M_PI * 1.5);
        $f->cairo_close_path($cr);
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

    /** Save the current cairo state (push) before applying a clip region. */
    public static function save($cr): void
    {
        self::ffi()->cairo_save($cr);
    }

    /** Restore the cairo state saved by {@see save} (pop), dropping any clip. */
    public static function restore($cr): void
    {
        self::ffi()->cairo_restore($cr);
    }

    /** Clip subsequent drawing to the rectangle (x, y, w, h). */
    public static function clip($cr, float $x, float $y, float $w, float $h): void
    {
        $f = self::ffi();
        $f->cairo_rectangle($cr, $x, $y, $w, $h);
        $f->cairo_clip($cr);
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

    public static function text($cr, float $x, float $y, string $text, float $size, float $r, float $g, float $b, ?string $family = null, int $weight = 0, int $slant = 0): void
    {
        $f = self::ffi();
        $f->cairo_select_font_face($cr, $family ?? self::defaultFamily(), $slant, $weight);
        $f->cairo_set_font_size($cr, $size);
        $f->cairo_set_source_rgb($cr, $r, $g, $b);
        $f->cairo_move_to($cr, $x, $y);
        $f->cairo_show_text($cr, $text);
    }

    /** Measure text with real cairo_text_extents (replaces width estimates). */
    public static function measureText(string $text, float $size, ?string $family = null, int $weight = 0, int $slant = 0): array
    {
        $cr = self::measureCr();
        if ($cr === null) {
            return ['w' => mb_strlen($text) * $size * 0.55, 'h' => (int)($size * 1.4)];
        }
        $f = self::ffi();
        $f->cairo_select_font_face($cr, $family ?? self::defaultFamily(), $slant, $weight);
        $f->cairo_set_font_size($cr, $size);
        $ext = $f->new('cairo_text_extents_t');
        $f->cairo_text_extents($cr, $text, \FFI::addr($ext));
        return [
            'w' => (float) $ext->x_advance,
            'h' => $ext->height > 0 ? (float) $ext->height : (float) $size * 1.2,
        ];
    }

    /** Context-free text extents (ink width + advance), same measureCr as
     *  measureText but returns cairo's width (bounding box) not just advance. */
    public static function measureExtents(string $text, float $size, ?string $family = null, int $weight = 0, int $slant = 0): array
    {
        $cr = self::measureCr();
        if ($cr === null) {
            $w = mb_strlen($text) * $size * 0.55;
            return ['w' => $w, 'x_advance' => $w];
        }
        $f = self::ffi();
        $f->cairo_select_font_face($cr, $family ?? self::defaultFamily(), $slant, $weight);
        $f->cairo_set_font_size($cr, $size);
        $ext = $f->new('cairo_text_extents_t');
        $f->cairo_text_extents($cr, $text, \FFI::addr($ext));
        return [
            'w' => (float) $ext->width,
            'x_advance' => (float) $ext->x_advance,
        ];
    }

    /**
     * Set the source pattern to the given surface. Used by the compositor
     * to blit the backing surface to the host cr.
     */
    public static function setSourceSurface($cr, $surface, float $x, float $y): void
    {
        self::ffi()->cairo_set_source_surface($cr, $surface, $x, $y);
    }

    /**
     * Paint the current source (surface / pattern) to the destination.
     * Used after cairo_set_source_surface to blit a backing surface.
     */
    public static function paint($cr): void
    {
        self::ffi()->cairo_paint($cr);
    }

    /** Return the surface associated with a cairo context. */
    public static function getTarget($cr)
    {
        return self::ffi()->cairo_get_target($cr);
    }

    /** Notify cairo that a rectangular region of the surface has been modified. */
    public static function surfaceMarkDirtyRectangle($surface, int $x, int $y, int $w, int $h): void
    {
        self::ffi()->cairo_surface_mark_dirty_rectangle($surface, $x, $y, $w, $h);
    }

    public static function pushGroup($cr): void
    {
        self::ffi()->cairo_push_group($cr);
    }

    public static function popGroupToSource($cr): void
    {
        self::ffi()->cairo_pop_group_to_source($cr);
    }

    public static function paintWithAlpha($cr, float $alpha): void
    {
        self::ffi()->cairo_paint_with_alpha($cr, $alpha);
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
