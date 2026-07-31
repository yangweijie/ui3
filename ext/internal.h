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
    int closed;                 /* set by ui3_host_close (P-Native P0) */
    char *title;               /* window title, set by ui3_host_set_title */
    int x, y;                   /* window position (P-Native P0 续) */
    int fullscreen;             /* 0=normal 1=fullscreen (P-Native P0 续) */
    void (*close_cb)(void*, int*); /* acceptClose callback: *accept=0 to block */
    void *close_ctx;

    /* Native dialog state (P-Native P1): preset result + last invocation. */
    int dialog_result;
    int last_dialog_kind;
    int last_dialog_style;
    char *last_dialog_title;
    char *last_dialog_message;
    char *last_dialog_buttons;

    /* Native notification state (P-Native P1): last invocation. */
    char *last_notify_title;
    char *last_notify_body;

    /* Native menu bar state (P-Native P1): last set_menu text. */
    char *last_menu;

    /* Accessibility tree (P-Native P1): cached last tree as text. */
    char *last_a11y_tree;

    /* Deep-copied accessibility tree for native bridge (P-Native P1). */
    void *plat_a11y;

    /* Clipboard multi-format headless storage (P-Native P2). */
    char *last_clip_text;
    char *last_clip_image;
    int   last_clip_image_len;
    char *last_clip_uris;
    char *last_clip_html;

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
void ui3_plat_post_key(ui3_host *host, int keycode, int modifiers, const char *chars);

/* Window-management hooks (P-Native P0): title / size / minimize / close. */
void ui3_plat_set_title(ui3_host *host, const char *title);
void ui3_plat_resize(ui3_host *host, int w, int h);
void ui3_plat_minimize(ui3_host *host);
void ui3_plat_close(ui3_host *host);
/* Window-management hooks (P-Native P0 续): move / fullscreen. */
void ui3_plat_move(ui3_host *host, int x, int y);
void ui3_plat_fullscreen(ui3_host *host);

/* Native modal dialog (P-Native P1). kind/style/title/message/buttons mirror
 * ui3_host_dialog; returns the 0-based index of the clicked button. */
int ui3_plat_dialog(ui3_host *host, int kind, int style, const char *title,
                    const char *message, const char *buttons);

/* Native notification / toast (P-Native P1). Returns 0 on success, -1 if the
 * platform cannot show one. */
int ui3_plat_notify(ui3_host *host, const char *title, const char *body);

/* Native menu bar (P-Native P1). Build the OS menu bar from the encoded text
 * (see ui3_host_set_menu). No-op on platforms without a native menu bar. */
void ui3_plat_set_menu(ui3_host *host, const char *menu);

/* Platform accessibility hooks (P-Native P1). Receives a ui3_a11y_node tree,
 * exports it to the native OS accessibility system. In headless mode stores
 * the tree as text for automation inspection. */
void ui3_plat_accessibility(ui3_host *host, ui3_a11y_node *root);

/* Platform clipboard multi-format (P-Native P2).
 * image sets/gets PNG bytes; uris sets/gets file URLs; html sets/gets HTML.
 * formats returns available formats as a bitmask. */
void ui3_plat_clipboard_set_image(ui3_host *host, const void *data, int len);
const void *ui3_plat_clipboard_get_image(ui3_host *host, int *out_len);
void ui3_plat_clipboard_set_uris(ui3_host *host, const char *uris);
const char *ui3_plat_clipboard_get_uris(ui3_host *host);
void ui3_plat_clipboard_set_html(ui3_host *host, const char *html, const char *base_url);
const char *ui3_plat_clipboard_get_html(ui3_host *host);
int ui3_plat_clipboard_formats(ui3_host *host);

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
