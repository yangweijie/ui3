#include "internal.h"
#include <stdlib.h>
#include <string.h>

ui3_host *ui3_host_create(const char *title, int width, int height, int headless)
{
    ui3_host *host = calloc(1, sizeof(*host));
    if (!host) return NULL;
    host->width = width > 0 ? width : 320;
    host->height = height > 0 ? height : 240;
    host->headless = headless ? 1 : 0;
    host->scale = 1.0;
    host->needs_redraw = 1;
    host->running = 1;
    host->inject_head = NULL;
    host->inject_tail = &host->inject_head;

    if (!host->headless) {
        if (ui3_plat_create_window(host, title) != 0) {
            /* No native window available on this platform: fall back to
             * offscreen so headless/automation still works. */
            host->headless = 1;
        }
    }
    return host;
}

void ui3_host_destroy(ui3_host *host)
{
    if (!host) return;
    ui3_plat_destroy(host);
    struct ui3_inject *n = host->inject_head;
    while (n) {
        struct ui3_inject *next = n->next;
        free(n->text);
        free(n);
        n = next;
    }
    free(host);
}

void ui3_host_set_draw_cb(ui3_host *host, ui3_draw_cb cb, void *ctx)
{
    if (!host) return;
    host->draw_cb = cb;
    host->draw_ctx = ctx;
}

void ui3_host_set_event_cb(ui3_host *host, ui3_event_cb cb, void *ctx)
{
    if (!host) return;
    host->event_cb = cb;
    host->event_ctx = ctx;
}

static void present_offscreen(ui3_host *host)
{
    int w = (int)(host->width * host->scale);
    int h = (int)(host->height * host->scale);
    cairo_surface_t *surf = cairo_image_surface_create(CAIRO_FORMAT_ARGB32, w, h);
    cairo_t *cr = cairo_create(surf);
    cairo_scale(cr, host->scale, host->scale);
    if (host->draw_cb) host->draw_cb(host->draw_ctx, host, cr);
    cairo_destroy(cr);
    cairo_surface_destroy(surf);
    host->needs_redraw = 0;
}

void ui3_host_request_redraw(ui3_host *host)
{
    if (!host) return;
    host->needs_redraw = 1;
    if (!host->headless) ui3_plat_request_redraw(host);
}

void ui3_host_present(ui3_host *host)
{
    if (!host) return;
    if (host->headless) {
        present_offscreen(host);
    } else {
        ui3_plat_present(host);
    }
    host->needs_redraw = 0;
}

double ui3_host_scale(ui3_host *host)   { return host ? host->scale : 1.0; }
int ui3_host_width(ui3_host *host)      { return host ? host->width : 0; }
int ui3_host_height(ui3_host *host)     { return host ? host->height : 0; }

void ui3_host_run(ui3_host *host)
{
    if (!host) return;
    if (host->headless) {
        ui3_host_present(host);
        while (host->running) ui3_host_step(host);
        return;
    }
    ui3_plat_run(host);
}

void ui3_host_quit(ui3_host *host)
{
    if (!host) return;
    host->running = 0;
}

int ui3_host_step(ui3_host *host)
{
    if (!host) return 0;

    if (host->headless) {
        if (host->needs_redraw) ui3_host_present(host);
        struct ui3_inject *n = host->inject_head;
        while (n) {
            struct ui3_inject *next = n->next;
            if (host->event_cb) {
                host->event_cb(host->event_ctx, n->kind, n->x, n->y, n->data, n->text);
            }
            free(n->text);
            free(n);
            n = next;
        }
        host->inject_head = NULL;
        host->inject_tail = &host->inject_head;
        return host->running;
    }

    int r = ui3_plat_step(host);
    if (host->needs_redraw) ui3_host_present(host);
    return r;
}

static void push_inject(ui3_host *host, int kind, double x, double y, double data, const char *text)
{
    struct ui3_inject *n = calloc(1, sizeof(*n));
    if (!n) return;
    n->kind = kind;
    n->x = x; n->y = y; n->data = data;
    n->text = text ? strdup(text) : NULL;
    n->next = NULL;
    *host->inject_tail = n;
    host->inject_tail = &n->next;
}

void ui3_host_inject_pointer(ui3_host *host, double x, double y, int down, int button)
{
    if (!host) return;
    push_inject(host, down ? UI3_EVENT_POINTER_DOWN : UI3_EVENT_POINTER_UP, x, y, (double)button, NULL);
}

void ui3_host_inject_move(void *host, double x, double y)
{
    if (!host) return;
    push_inject(host, UI3_EVENT_POINTER_MOVE, x, y, 0, NULL);
}

int ui3_host_is_headless(void *host)
{
    if (!host) return 1;
    return ((ui3_host *)host)->headless;
}

void ui3_host_inject_key(ui3_host *host, const char *text)
{
    if (!host) return;
    push_inject(host, UI3_EVENT_KEY, 0, 0, 0, text);
}

/* Canonical key text for the PHP onKey router (#3/#4/#5a/#5b/#5c), shared by
 * the real Cocoa/Win32/X11 keyDown paths and the headless raw-key injection
 * used for tests. Returns a malloc'd string (caller frees), or NULL to ignore
 * the key.
 *
 * `keycode` is a PLATFORM-NEUTRAL logical key id. Its numeric values currently
 * coincide with macOS virtual keycodes (48=Tab, 123-126=arrows, 36/76=Return,
 * 51=Backspace) so the headless inject_raw_key tests and the Cocoa path keep
 * working unchanged; Win32/X11 map their native keycodes onto these same ids
 * before calling this function, guaranteeing identical canonical output. */
char *ui3_key_text(int keycode, int shift, const char *chars)
{
    switch (keycode) {
        case 48:  return strdup(shift ? "Shift+Tab" : "Tab");
        case 123: return strdup(shift ? "\x11" : "\x01");   /* ArrowLeft  */
        case 124: return strdup(shift ? "\x12" : "\x02");   /* ArrowRight */
        case 126: return strdup(shift ? "\x13" : "\x03");   /* ArrowUp    */
        case 125: return strdup(shift ? "\x14" : "\x04");   /* ArrowDown  */
        case 115: return strdup("\x05");   /* Home  */
        case 119: return strdup("\x06");   /* End   */
        case 117: return strdup("\x07");   /* ForwardDelete */
        case 36:  case 76: return strdup("\n");   /* Return / Enter */
        case 51:  return strdup("\b");     /* Backspace  */
        case 53:  return strdup("\x1b");   /* Escape     */
        default:
            /* Any key that produced printable characters (incl. with Shift). */
            if (chars && chars[0] != '\0') return strdup(chars);
            return NULL;                    /* ignore Function/modifier-only keys */
    }
}

/* Parse a comma-separated extension list into a filter group, stripping any
 * leading dot or surrounding spaces, and capping at the group's capacity. */
static void ui3_parse_exts(ui3_filter_group *g, const char *exts)
{
    char buf[256];
    strncpy(buf, exts, sizeof(buf) - 1);
    buf[sizeof(buf) - 1] = '\0';
    char *p = buf;
    while (*p && g->nexts < 24) {
        char *comma = strchr(p, ',');
        if (comma) *comma = '\0';
        char *e = p;
        while (*e == '.' || *e == ' ') e++;
        int len = (int)strlen(e);
        if (len > 0 && g->nexts < 24) {
            strncpy(g->exts[g->nexts], e, 15);
            g->exts[g->nexts][15] = '\0';
            g->nexts++;
        }
        if (!comma) break;
        p = comma + 1;
    }
}

int ui3_parse_filters(const char *spec, ui3_filter_group *groups, int max)
{
    int ng = 0;
    if (!spec || !*spec || max <= 0) return 0;

    int has_label = 0;
    for (const char *p = spec; *p; p++) {
        if (*p == ':') { has_label = 1; break; }
    }

    char buf[1024];
    strncpy(buf, spec, sizeof(buf) - 1);
    buf[sizeof(buf) - 1] = '\0';

    if (!has_label) {
        if (ng < max) {
            memset(&groups[ng], 0, sizeof(groups[ng]));
            strncpy(groups[ng].label, "Files", sizeof(groups[ng].label) - 1);
            ui3_parse_exts(&groups[ng], buf);
            ng++;
        }
        return ng;
    }

    char *p = buf;
    while (*p && ng < max) {
        char *semi = strchr(p, ';');
        if (semi) *semi = '\0';
        memset(&groups[ng], 0, sizeof(groups[ng]));
        char *colon = strchr(p, ':');
        if (colon) {
            int ll = (int)(colon - p);
            if (ll > 63) ll = 63;
            memcpy(groups[ng].label, p, ll);
            groups[ng].label[ll] = '\0';
            ui3_parse_exts(&groups[ng], colon + 1);
        } else {
            strncpy(groups[ng].label, "Files", sizeof(groups[ng].label) - 1);
            ui3_parse_exts(&groups[ng], p);
        }
        ng++;
        if (!semi) break;
        p = semi + 1;
    }
    return ng;
}

/* Drive a key by raw scancode + modifiers through the SAME translation the
 * real native keyDown uses, so the headless event path exercises identical
 * routing to a physical window. */
void ui3_host_inject_raw_key(ui3_host *host, int keycode, int shift, const char *chars)
{
    if (!host) return;
    char *t = ui3_key_text(keycode, shift, chars);
    if (!t) return;
    push_inject(host, UI3_EVENT_KEY, 0, 0, 0, t);
    free(t);
}

void ui3_host_post_key(ui3_host *host, int keycode, int shift, const char *chars)
{
    if (!host) return;
    if (host->headless) {
        char *t = ui3_key_text(keycode, shift, chars);
        if (!t) return;
        push_inject(host, UI3_EVENT_KEY, 0, 0, 0, t);
        free(t);
    } else {
        ui3_plat_post_key(host, keycode, shift, chars);
    }
}
