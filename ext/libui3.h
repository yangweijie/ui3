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
    UI3_EVENT_DROP = 6,  /* data = 0 text / 1 files; text = payload (UTF-8; newline-separated paths for files) */
    UI3_EVENT_MENU = 7,  /* text = the clicked menu item's onClick message */
    UI3_EVENT_GESTURE = 8, /* data = 0 pinch / 1 rotate / 2 swipe; text = magnitude/dir */
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
int ui3_host_x(ui3_host *host);
int ui3_host_y(ui3_host *host);
int ui3_host_fullscreen_state(ui3_host *host);

/* Input injection for automation / headless driving. */
void ui3_host_inject_pointer(ui3_host *host, double x, double y, int down, int button);
void ui3_host_inject_move(void *host, double x, double y);
void ui3_host_inject_key(ui3_host *host, const char *text);
/* Inject a drop event (automation / headless). dtype 0=text 1=files; payload
 * is UTF-8 (newline-separated paths for files). Mirrors a native drag-drop. */
void ui3_host_inject_drop(ui3_host *host, int dtype, double x, double y, const char *payload);
/* Inject a gesture event (automation / headless). gtype 0=pinch 1=rotate
 * 2=swipe; value is the magnitude/direction string. Mirrors a native trackpad
 * gesture. */
void ui3_host_inject_gesture(ui3_host *host, int gtype, double x, double y, const char *value);
int  ui3_host_is_headless(void *host);
/* Keyboard modifier bits — shared by ui3_key_text() and the KEY event stream. */
#define UI3_MOD_SHIFT 1
#define UI3_MOD_CTRL  2
#define UI3_MOD_ALT   4
#define UI3_MOD_CMD   8
/* Drive a key by raw scancode + modifiers (mirrors native keyDown). */
void ui3_host_inject_raw_key(ui3_host *host, int keycode, int modifiers, const char *chars);
/* Post a keystroke the SAME way the OS would: headless -> inject queue;
 * real window -> a synthesized native key event through the platform key path
 * (e.g. Cocoa window.keyDown: -> routeKey). Lets automation verify the real path. */
void ui3_host_post_key(ui3_host *host, int keycode, int modifiers, const char *chars);
/* Window management (P-Native P0): title / size / minimize / close. */
void ui3_host_set_title(ui3_host *host, const char* title);
void ui3_host_resize(ui3_host *host, int w, int h);
void ui3_host_minimize(ui3_host *host);
void ui3_host_close(ui3_host *host);
const char* ui3_host_title(ui3_host *host);
int ui3_host_closed(ui3_host *host);
/* Window management (P-Native P0 续): move / fullscreen / accept-close. */
void ui3_host_move(ui3_host *host, int x, int y);
void ui3_host_fullscreen(ui3_host *host);
void ui3_host_set_close_cb(ui3_host *host, void (*cb)(void* ctx, int* accept), void* ctx);

/* System clipboard (UTF-8 text). get_clipboard_text returns a malloc'd string
 * (caller reads it immediately) or NULL when empty. */
void  ui3_host_set_clipboard_text(ui3_host *host, const char *text);
char *ui3_host_get_clipboard_text(ui3_host *host);

/* Clipboard format bitmask (returned by clipboard_formats). */
#define UI3_CLIP_TEXT   1
#define UI3_CLIP_IMAGE  2
#define UI3_CLIP_FILES  4
#define UI3_CLIP_HTML   8

/* Multi-format clipboard (P-Native P2). */
void ui3_host_set_clipboard_image(ui3_host *host, const void *data, int len);
char *ui3_host_get_clipboard_image(ui3_host *host, int *out_len);
void ui3_host_set_clipboard_uris(ui3_host *host, const char *uris);
char *ui3_host_get_clipboard_uris(ui3_host *host);
void ui3_host_set_clipboard_html(ui3_host *host, const char *html, const char *base_url);
char *ui3_host_get_clipboard_html(ui3_host *host);
int  ui3_host_clipboard_formats(ui3_host *host);

/* Modal file dialogs. Returns a malloc'd path or NULL when cancelled.
 * open_file: filters is a comma-separated extension list (e.g. "png,jpg") or "".
 * save_file: defext is the default extension (e.g. "png") or "". */
char *ui3_host_open_file(ui3_host *host, const char *filters);
char *ui3_host_save_file(ui3_host *host, const char *defext);

/* Native modal dialogs (P-Native P1). Shows an alert/confirm/sheet-style
 * dialog and returns the 0-based index of the clicked button, or -1 if no
 * window is available to present it.
 *   kind:   0=info 1=warning 2=error 3=question
 *   style:  0=window-modal 1=sheet (attached to the window; native modals on
 *           platforms without sheets)
 *   title/message: UTF-8 text
 *   buttons: "|"-separated labels, e.g. "OK" or "OK|Cancel"
 * In headless mode the call is recorded on the host and the result is taken
 * from a preset (ui3_host_set_dialog_result), default 0. */
int   ui3_host_dialog(ui3_host *host, int kind, int style, const char* title,
                      const char* message, const char* buttons);
/* Preset the value returned by ui3_host_dialog in headless mode (automation).
 * Default 0. */
void  ui3_host_set_dialog_result(ui3_host *host, int result);
/* malloc'd "kind\tstyle\ttitle\tmessage\tbuttons" of the last dialog, or ""
 * if none. Free with free(). Used by automation to assert the call headless. */
char* ui3_host_last_dialog(ui3_host *host);

/* Native notification / toast (P-Native P1). Shows an OS notification and
 * records the call on the host (so automation can assert it headless). Returns
 * 0 on success, -1 if unavailable. */
int   ui3_host_notify(ui3_host *host, const char* title, const char* body);
/* malloc'd "title\tbody" of the last notification, or "" if none. Free with
 * free(). Used by automation to assert the call headless. */
char* ui3_host_last_notify(ui3_host *host);

/* Native menu bar (P-Native P1). $menu is a newline/tab text encoding:
 *   "File\n\tOpen\topen\tCmd+O\n\tSave\tsave\t\n\t-\nEdit\n\tCut\tcut\tCmd+X\n"
 * top-level menu label per unindented line; items are tab-indented
 * "\t<label>\t<onClick>\t<shortcut>"; a line "\t-" is a separator. The call
 * is recorded on the host (headless assertion). */
void  ui3_host_set_menu(ui3_host *host, const char *menu);
/* malloc'd copy of the last set_menu text, or "" if none. Free with free(). */
char* ui3_host_last_menu(ui3_host *host);
/* Simulate clicking a menu item (automation): delivers UI3_EVENT_MENU with
 * text=$msg through the event path, exactly like a native menu click. */
void  ui3_host_click_menu(ui3_host *host, const char *msg);

/* Native accessibility tree (P-Native P1). Passes the tree to the platform
 * bridge for registration with the OS accessibility system. In headless mode
 * stores the tree as text for automation inspection via ui3_host_last_a11y. */
typedef struct ui3_a11y_node {
    const char *role;
    const char *label;
    const char *description;
    int x, y, w, h;
    int has_focus;
    int expanded, selected, disabled, checked;
    struct ui3_a11y_node **children;
    int child_count;
} ui3_a11y_node;

void  ui3_host_set_a11y_tree(ui3_host *host, ui3_a11y_node *root);
char* ui3_host_last_a11y(ui3_host *host); /* malloc'd, or "" if none. Free(). */

/* Set accessibility tree from text (one node per line; tabs separate fields;
 * leading tabs indicate nesting depth). Format:
 *   role\tlabel\tdesc\tx\ty\tw\th\tfocus\texpanded\tselected\tdisabled\tchecked
 * Preferred over set_a11y_tree for FFI callers (avoids struct pointer lifetime). */
void ui3_host_set_a11y_text(ui3_host *host, const char *text);

#endif /* LIBUI3_H */
