#ifndef INTERNAL_H
#define INTERNAL_H

#include "libui3.h"
#include <cairo/cairo.h>

struct ui3_inject {
    int kind;
    double x, y, data;
    char *text;
    struct ui3_inject *next;
};

struct ui3_host {
    int width, height;
    int headless;
    double scale;
    ui3_draw_cb draw_cb;
    void *draw_ctx;
    ui3_event_cb event_cb;
    void *event_ctx;
    int needs_redraw;
    int running;

    void *plat;                 /* platform-specific state (NSWindow/NSView) */

    struct ui3_inject *inject_head;
    struct ui3_inject **inject_tail;
};

/* Platform hooks (cocoa.m / win32.c / x11.c). */
int  ui3_plat_create_window(ui3_host *host, const char *title); /* 0 ok, -1 unsupported */
void ui3_plat_request_redraw(ui3_host *host); /* ASYNC: schedule a redraw (no synchronous draw) */
void ui3_plat_present(ui3_host *host);        /* SYNC: paint one frame immediately */
int  ui3_plat_step(ui3_host *host);   /* pump one event batch; return host->running */
void ui3_plat_run(ui3_host *host);
void ui3_plat_destroy(ui3_host *host);
/* Synthesize + deliver a real key event through the platform key path. */
void ui3_plat_post_key(ui3_host *host, int keycode, int shift, const char *chars);

/* Canonical key-text mapping, shared by native keyDown and inject_raw_key. */
char *ui3_key_text(int keycode, int shift, const char *chars);

/* File-dialog filter parsing, shared by every platform so the caller never
 * re-implements platform-specific filter syntax.
 *
 * Format:
 *   "ext1,ext2"                     -> single unlabeled group "Files"
 *   "Label1:ext1,ext2;Label2:ext3"  -> multiple labeled groups
 *
 * Extensions may be written with or without a leading dot; each group may hold
 * up to 24 extensions. Returns the number of groups written (capped at max). */
typedef struct {
    char label[64];
    char exts[24][16];
    int  nexts;
} ui3_filter_group;

int ui3_parse_filters(const char *spec, ui3_filter_group *groups, int max);

#endif /* INTERNAL_H */
