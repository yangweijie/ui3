#ifndef LIBUI3_H
#define LIBUI3_H

/*
 * Canvas host ABI — libui-free.
 *
 * The host owns ONE native surface (macOS AppKit NSWindow + a Cairo Quartz
 * context, or an offscreen CAIRO_IMAGE surface in headless mode) and a single
 * draw callback. It does NOT create per-widget native controls. The PHP side
 * holds the Element tree, computes layout, and paints everything through Cairo
 * FFI using the cairo_t* handed to the draw callback.
 */

typedef struct _cairo cairo_t; /* opaque; real cairo.h pulled in by the C impl */

typedef enum {
    UI3_EVENT_QUIT = 0,
    UI3_EVENT_POINTER_DOWN = 1,
    UI3_EVENT_POINTER_UP = 2,
    UI3_EVENT_POINTER_MOVE = 3,
    UI3_EVENT_KEY = 4,
    UI3_EVENT_WHEEL = 5, /* data = pixels; > 0 == scroll down (viewport offset increases) */
} ui3_event_kind;

typedef struct ui3_host ui3_host;

/* Called whenever a frame must be painted. `cr` is valid only during the call. */
typedef void (*ui3_draw_cb)(void *ctx, ui3_host *host, cairo_t *cr);

/* kind, x, y (CSS px, top-left origin), data (button/keycode), text (utf8 key). */
typedef void (*ui3_event_cb)(void *ctx, int kind, double x, double y, double data, const char *text);

ui3_host *ui3_host_create(const char *title, int width, int height, int headless);
void ui3_host_destroy(ui3_host *host);

void ui3_host_set_draw_cb(ui3_host *host, ui3_draw_cb cb, void *ctx);
void ui3_host_set_event_cb(ui3_host *host, ui3_event_cb cb, void *ctx);

void ui3_host_run(ui3_host *host);
void ui3_host_quit(ui3_host *host);
int  ui3_host_step(ui3_host *host);          /* 1 = keep going, 0 = stop */
void ui3_host_request_redraw(ui3_host *host);
void ui3_host_present(ui3_host *host);       /* render one frame (offscreen in headless) */

double ui3_host_scale(ui3_host *host);
int ui3_host_width(ui3_host *host);
int ui3_host_height(ui3_host *host);

/* Input injection for automation / headless driving. */
void ui3_host_inject_pointer(ui3_host *host, double x, double y, int down);
void ui3_host_inject_move(void *host, double x, double y);
void ui3_host_inject_key(ui3_host *host, const char *text);
int  ui3_host_is_headless(void *host);
/* Drive a key by raw scancode + modifiers (mirrors native keyDown). */
void ui3_host_inject_raw_key(ui3_host *host, int keycode, int shift, const char *chars);
/* Post a keystroke the SAME way the OS would: headless -> inject queue;
 * real window -> a synthesized native key event through the platform key path
 * (e.g. Cocoa window.keyDown: -> routeKey). Lets automation verify the real path. */
void ui3_host_post_key(ui3_host *host, int keycode, int shift, const char *chars);

#endif /* LIBUI3_H */
