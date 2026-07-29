<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\FFI;

use FFI;
use Kingbes\Phpc\{Library, Phpc};

/**
 * Loads the libui3 canvas host through kingbes/phpc. The C ABI is declared here
 * and must stay in sync with ext/libui3.h.
 *
 * The host owns ONE native surface (macOS AppKit NSWindow + Cairo Quartz, or an
 * offscreen CAIRO_IMAGE in headless mode) and a single draw callback. It does
 * NOT create per-widget native controls — the PHP side holds the Element tree,
 * lays it out, and paints everything via the Cairo FFI wrapper.
 */
final class LibUi3
{
    private static ?FFI $ffi = null;

    private const HEADER = <<<'C'
typedef struct _cairo cairo_t;
typedef void (*ui3_draw_cb)(void* ctx, void* host, void* cr);
typedef void (*ui3_event_cb)(void* ctx, int kind, double x, double y, double data, const char* text);

void* ui3_host_create(const char* title, int width, int height, int headless);
void  ui3_host_destroy(void* host);
void  ui3_host_set_draw_cb(void* host, ui3_draw_cb cb, void* ctx);
void  ui3_host_set_event_cb(void* host, ui3_event_cb cb, void* ctx);
void  ui3_host_run(void* host);
void  ui3_host_quit(void* host);
int   ui3_host_step(void* host);
void  ui3_host_request_redraw(void* host);
void  ui3_host_present(void* host);
double ui3_host_scale(void* host);
int   ui3_host_width(void* host);
int   ui3_host_height(void* host);
void  ui3_host_inject_pointer(void* host, double x, double y, int down, int button);
void  ui3_host_inject_move(void* host, double x, double y);
void  ui3_host_inject_key(void* host, const char* text);
int   ui3_host_is_headless(void* host);
void  ui3_host_inject_raw_key(void* host, int keycode, int shift, const char* chars);
void  ui3_host_post_key(void* host, int keycode, int shift, const char* chars);
void  ui3_host_set_clipboard_text(void* host, const char* text);
char* ui3_host_get_clipboard_text(void* host);
char* ui3_host_open_file(void* host, const char* filters);
char* ui3_host_save_file(void* host, const char* defext);
C;

    private static function libName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Windows' => 'libui3.dll',
            'Darwin'  => 'libui3.dylib',
            default   => 'libui3.so',
        };
    }

    public static function ffi(): FFI
    {
        if (self::$ffi === null) {
            self::$ffi = self::loadFfi();
        }
        return self::$ffi;
    }

    /**
     * Load libui3 through phpc's whitelist (name-based; relies on
     * DYLD_LIBRARY_PATH / LD_LIBRARY_PATH or system paths). Falls back to the
     * project build/ absolute path so it also works under PHP version managers
     * (e.g. PhpWebStudy) whose wrapper scripts reset the environment and drop
     * DYLD_LIBRARY_PATH, which would otherwise make the name-based load fail.
     */
    private static function loadFfi(): FFI
    {
        $lib = self::libName();
        try {
            Library::permit($lib);
            return Library::load($lib, self::HEADER);
        } catch (\FFI\Exception $e) {
            $abs = realpath(__DIR__ . '/../../build/' . $lib);
            if ($abs === false || !is_file($abs)) {
                throw $e;
            }
            return \FFI::cdef(self::HEADER, $abs);
        }
    }
}
