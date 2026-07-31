#include "internal.h"
#include <stdlib.h>
#include <string.h>
#include <stdio.h>

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
    host->title = title ? strdup(title) : NULL;

    /* Native dialog state (P-Native P1). */
    host->dialog_result = 0;
    host->last_dialog_kind = -1;
    host->last_dialog_style = 0;
    host->last_dialog_title = NULL;
    host->last_dialog_message = NULL;
    host->last_dialog_buttons = NULL;

    /* Native notification state (P-Native P1). */
    host->last_notify_title = NULL;
    host->last_notify_body = NULL;

    /* Native menu bar state (P-Native P1). */
    host->last_menu = NULL;

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
    free(host->title);
    free(host->last_dialog_title);
    free(host->last_dialog_message);
    free(host->last_dialog_buttons);
    free(host->last_notify_title);
    free(host->last_notify_body);
    free(host->last_menu);
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

void ui3_host_inject_drop(ui3_host *host, int dtype, double x, double y, const char *payload)
{
    if (!host) return;
    push_inject(host, UI3_EVENT_DROP, x, y, (double)dtype, payload);
}

void ui3_host_inject_gesture(ui3_host *host, int gtype, double x, double y, const char *value)
{
    if (!host) return;
    push_inject(host, UI3_EVENT_GESTURE, x, y, (double)gtype, value);
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
// Append "Shift+/Ctrl+/Alt+/Cmd+" tokens for the set modifier bits.
static void ui3_mod_prefix(char *out, int modifiers)
{
    out[0] = '\0';
    if (modifiers & UI3_MOD_SHIFT) strcat(out, "Shift+");
    if (modifiers & UI3_MOD_CTRL)  strcat(out, "Ctrl+");
    if (modifiers & UI3_MOD_ALT)   strcat(out, "Alt+");
    if (modifiers & UI3_MOD_CMD)   strcat(out, "Cmd+");
}

// Canonical key text, including any modifier prefix. `modifiers` is a bitmask
// of UI3_MOD_*; 0 means no modifiers. Single source of truth so Cocoa / Win32 /
// X11 / headless injection all emit identical labels.
char *ui3_key_text(int keycode, int modifiers, const char *chars)
{
    switch (keycode) {
        case 48: {  /* Tab */
            char buf[32];
            ui3_mod_prefix(buf, modifiers);
            char *r = (char *)malloc(strlen(buf) + 4);
            sprintf(r, "%sTab", buf);
            return r;
        }
        case 123: return strdup(modifiers & UI3_MOD_SHIFT ? "\x11" : "\x01");
        case 124: return strdup(modifiers & UI3_MOD_SHIFT ? "\x12" : "\x02");
        case 126: return strdup(modifiers & UI3_MOD_SHIFT ? "\x13" : "\x03");
        case 125: return strdup(modifiers & UI3_MOD_SHIFT ? "\x14" : "\x04");
        case 115: return strdup("\x05");
        case 119: return strdup("\x06");
        case 117: return strdup("\x07");
        case 36:
        case 76: return strdup("\n");
        case 51:  return strdup("\b");
        case 53:  return strdup("\x1b");
        default:
            if (chars && chars[0] != '\0') {
                char buf[32];
                ui3_mod_prefix(buf, modifiers);
                char *r = (char *)malloc(strlen(buf) + strlen(chars) + 1);
                sprintf(r, "%s%s", buf, chars);
                return r;
            }
            return NULL;
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
void ui3_host_inject_raw_key(ui3_host *host, int keycode, int modifiers, const char *chars)
{
    if (!host) return;
    char *t = ui3_key_text(keycode, modifiers, chars);
    if (!t) return;
    push_inject(host, UI3_EVENT_KEY, 0, 0, (double)modifiers, t);
    free(t);
}

void ui3_host_post_key(ui3_host *host, int keycode, int modifiers, const char *chars)
{
    if (!host) return;
    if (host->headless) {
        char *t = ui3_key_text(keycode, modifiers, chars);
        if (!t) return;
        push_inject(host, UI3_EVENT_KEY, 0, 0, (double)modifiers, t);
        free(t);
    } else {
        ui3_plat_post_key(host, keycode, modifiers, chars);
    }
}

/* ---- Window management (P-Native P0): title / size / minimize / close ---- */
void ui3_host_set_title(ui3_host *host, const char *title)
{
    if (!host) return;
    free(host->title);
    host->title = title ? strdup(title) : NULL;
    ui3_plat_set_title(host, host->title);
}

void ui3_host_resize(ui3_host *host, int w, int h)
{
    if (!host) return;
    if (w > 0) host->width = w;
    if (h > 0) host->height = h;
    ui3_plat_resize(host, host->width, host->height);
    host->needs_redraw = 1;
}

void ui3_host_minimize(ui3_host *host)
{
    if (!host) return;
    ui3_plat_minimize(host);
}

void ui3_host_close(ui3_host *host)
{
    if (!host) return;
    host->closed = 1;
    host->running = 0;
    ui3_plat_close(host);
}

const char *ui3_host_title(ui3_host *host)
{
    if (!host || !host->title) return "";
    return host->title;
}

int ui3_host_closed(ui3_host *host)
{
    return host ? host->closed : 0;
}

/* ---- Window management (P-Native P0 续): move / fullscreen / accept-close ---- */

void ui3_host_move(ui3_host *host, int x, int y)
{
    if (!host) return;
    host->x = x;
    host->y = y;
    if (!host->headless) ui3_plat_move(host, x, y);
}

void ui3_host_fullscreen(ui3_host *host)
{
    if (!host) return;
    host->fullscreen = !host->fullscreen;
    if (!host->headless) ui3_plat_fullscreen(host);
}

void ui3_host_set_close_cb(ui3_host *host, void (*cb)(void*, int*), void *ctx)
{
    if (!host) return;
    host->close_cb = cb;
    host->close_ctx = ctx;
}

int ui3_host_x(ui3_host *host)      { return host ? host->x : 0; }
int ui3_host_y(ui3_host *host)      { return host ? host->y : 0; }
int ui3_host_fullscreen_state(ui3_host *host) { return host ? host->fullscreen : 0; }

/* ---- Native modal dialogs (P-Native P1) ---- */
void ui3_host_set_dialog_result(ui3_host *host, int result)
{
    if (!host) return;
    host->dialog_result = result;
}

static void record_dialog(ui3_host *host, int kind, int style,
                          const char *title, const char *message, const char *buttons)
{
    host->last_dialog_kind = kind;
    host->last_dialog_style = style;
    free(host->last_dialog_title);
    free(host->last_dialog_message);
    free(host->last_dialog_buttons);
    host->last_dialog_title = title ? strdup(title) : strdup("");
    host->last_dialog_message = message ? strdup(message) : strdup("");
    host->last_dialog_buttons = buttons ? strdup(buttons) : strdup("");
}

int ui3_host_dialog(ui3_host *host, int kind, int style, const char *title,
                    const char *message, const char *buttons)
{
    if (!host) return -1;
    record_dialog(host, kind, style, title, message, buttons);
    if (host->headless) {
        return host->dialog_result;
    }
    return ui3_plat_dialog(host, kind, style, title, message, buttons);
}

char *ui3_host_last_dialog(ui3_host *host)
{
    if (!host || host->last_dialog_kind < 0) return strdup("");
    size_t need = 32 + strlen(host->last_dialog_title)
                      + strlen(host->last_dialog_message)
                      + strlen(host->last_dialog_buttons);
    char *out = malloc(need);
    if (!out) return strdup("");
    snprintf(out, need, "%d\t%d\t%s\t%s\t%s",
             host->last_dialog_kind, host->last_dialog_style,
             host->last_dialog_title, host->last_dialog_message,
             host->last_dialog_buttons);
    return out;
}

/* ---- Native notification / toast (P-Native P1) ---- */
int ui3_host_notify(ui3_host *host, const char *title, const char *body)
{
    if (!host) return -1;
    free(host->last_notify_title);
    free(host->last_notify_body);
    host->last_notify_title = title ? strdup(title) : strdup("");
    host->last_notify_body = body ? strdup(body) : strdup("");
    if (host->headless) return 0;
    return ui3_plat_notify(host, title, body);
}

char *ui3_host_last_notify(ui3_host *host)
{
    if (!host || !host->last_notify_title) return strdup("");
    size_t need = 8 + strlen(host->last_notify_title) + strlen(host->last_notify_body);
    char *out = malloc(need);
    if (!out) return strdup("");
    snprintf(out, need, "%s\t%s", host->last_notify_title, host->last_notify_body);
    return out;
}

/* ---- Native menu bar (P-Native P1) ---- */
void ui3_host_set_menu(ui3_host *host, const char *menu)
{
    if (!host) return;
    free(host->last_menu);
    host->last_menu = menu ? strdup(menu) : NULL;
    if (!host->headless && host->last_menu) {
        ui3_plat_set_menu(host, host->last_menu);
    }
}

char *ui3_host_last_menu(ui3_host *host)
{
    if (!host || !host->last_menu) return strdup("");
    return strdup(host->last_menu);
}

void ui3_host_click_menu(ui3_host *host, const char *msg)
{
    if (!host || !msg) return;
    if (host->headless) {
        push_inject(host, UI3_EVENT_MENU, 0, 0, 0, msg);
        return;
    }
    if (host->event_cb) {
        host->event_cb(host->event_ctx, UI3_EVENT_MENU, 0, 0, 0, msg);
    }
}

/* ---- Native accessibility tree (P-Native P1) ---- */

static ui3_a11y_node *a11y_copy_node(const ui3_a11y_node *src, int depth)
{
    if (!src) return NULL;
    ui3_a11y_node *dst = malloc(sizeof(ui3_a11y_node));
    if (!dst) return NULL;
    dst->role = src->role ? strdup(src->role) : "";
    dst->label = src->label ? strdup(src->label) : "";
    dst->description = src->description ? strdup(src->description) : "";
    dst->x = src->x;
    dst->y = src->y;
    dst->w = src->w;
    dst->h = src->h;
    dst->has_focus = src->has_focus;
    dst->expanded = src->expanded;
    dst->selected = src->selected;
    dst->disabled = src->disabled;
    dst->checked = src->checked;
    dst->child_count = src->child_count;
    dst->children = NULL;
    if (src->children && src->child_count > 0) {
        dst->children = calloc(src->child_count, sizeof(ui3_a11y_node *));
        if (dst->children) {
            for (int i = 0; i < src->child_count; i++) {
                dst->children[i] = a11y_copy_node(src->children[i], depth + 1);
            }
        }
    }
    return dst;
}

static void a11y_free_tree(ui3_a11y_node *root)
{
    if (!root) return;
    if (root->children) {
        for (int i = 0; i < root->child_count; i++) {
            a11y_free_tree(root->children[i]);
        }
        free(root->children);
    }
    free((void *)root->role);
    free((void *)root->label);
    free((void *)root->description);
    free(root);
}

static void a11y_serialize(ui3_a11y_node *root, char *buf, int *pos, int max, int indent)
{
    if (!root) return;
    int n = 0;
    int rem = max - *pos;
    /* indent */
    for (int i = 0; i < indent; i++) {
        if (rem > 0) buf[(*pos)++] = '\t';
        rem--;
    }
    n = snprintf(buf + *pos, rem,
                 "%s\t%s\t%s\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\t%d\n",
                 root->role, root->label, root->description,
                 root->x, root->y, root->w, root->h,
                 root->has_focus, root->expanded, root->selected,
                 root->disabled, root->checked);
    if (n < 0 || n >= rem) return;
    *pos += n;
    if (root->children) {
        for (int i = 0; i < root->child_count; i++) {
            a11y_serialize(root->children[i], buf, pos, max, indent + 1);
        }
    }
}

static char *a11y_to_text(ui3_a11y_node *root)
{
    if (!root) return strdup("");
    char *buf = malloc(16384);
    if (!buf) return strdup("");
    int pos = 0;
    a11y_serialize(root, buf, &pos, 16384, 0);
    if (pos > 0) buf[pos - 1] = '\0'; /* drop trailing newline */
    return buf;
}

void ui3_host_set_a11y_tree(ui3_host *host, ui3_a11y_node *root)
{
    if (!host) return;

    ui3_a11y_node *tree = a11y_copy_node(root, 0);

    /* Headless mode: serialize tree for automation inspection. */
    if (host->headless) {
        char *text = a11y_to_text(tree);
        free(host->last_a11y_tree);
        host->last_a11y_tree = text;
        a11y_free_tree(tree);
        return;
    }

    /* Native mode: deep-copy tree on host, then hand to platform bridge. */
    a11y_free_tree((ui3_a11y_node *)host->plat_a11y);
    host->plat_a11y = tree;
    if (tree && tree->role) {
        ui3_plat_accessibility(host, tree);
    }
}

char *ui3_host_last_a11y(ui3_host *host)
{
    if (!host || !host->last_a11y_tree) return strdup("");
    return strdup(host->last_a11y_tree);
}

void ui3_host_set_a11y_text(ui3_host *host, const char *text)
{
    if (!host || !text || !text[0]) return;

    /* Parent stack and child arrays, indexed by depth. */
    ui3_a11y_node *parents[32];
    ui3_a11y_node **children[32];
    int cap[32];
    for (int i = 0; i < 32; i++) {
        parents[i] = NULL;
        children[i] = NULL;
        cap[i] = 0;
    }

    char *dup = strdup(text);
    if (!dup) return;

    ui3_a11y_node *root = NULL;
    for (char *line = strtok(dup, "\n"); line; line = strtok(NULL, "\n")) {
        int depth = 0;
        char *rest = line;
        while (*rest == '\t') { depth++; rest++; }
        if (depth >= 32) continue;

        /* Split the rest into exactly 13 tab-delimited fields. */
        char *fields[13];
        int fi = 0;
        for (char *s = rest; fi < 13; ) {
            fields[fi++] = s;
            char *tab = strchr(s, '\t');
            if (!tab) break;
            *tab = '\0';
            s = tab + 1;
        }

        while (fi < 13) fields[fi++] = "";

        char role[64] = "";
        if (fields[0][0]) strncpy(role, fields[0], sizeof(role) - 1);
        int x = atoi(fields[3]);
        int y = atoi(fields[4]);
        int w = atoi(fields[5]);
        int h = atoi(fields[6]);
        int focus     = atoi(fields[7]);
        int expanded  = atoi(fields[8]);
        int selected  = atoi(fields[9]);
        int disabled  = atoi(fields[10]);
        int checked   = atoi(fields[11]);

        ui3_a11y_node *node = malloc(sizeof(ui3_a11y_node));
        if (!node) { a11y_free_tree(root); free(dup); return; }
        node->role = strdup(fields[0][0] ? fields[0] : "group");
        node->label = strdup(fields[1]);
        node->description = strdup(fields[2][0] ? fields[2] : "");
        node->x = x; node->y = y; node->w = w; node->h = h;
        node->has_focus = focus;
        node->expanded = expanded; node->selected = selected;
        node->disabled = disabled; node->checked = checked;
        node->child_count = 0;
        node->children = NULL;

        if (depth == 0) {
            root = node;
        } else if (parents[depth - 1]) {
            int ci = parents[depth - 1]->child_count;
            if (ci >= cap[depth - 1]) {
                int new_cap = cap[depth - 1] ? cap[depth - 1] * 2 : 4;
                ui3_a11y_node **new_arr = realloc(children[depth - 1],
                                                   new_cap * sizeof(ui3_a11y_node *));
                if (!new_arr) { a11y_free_tree(root); free(dup); return; }
                children[depth - 1] = new_arr;
                cap[depth - 1] = new_cap;
            }
            children[depth - 1][ci] = node;
            parents[depth - 1]->child_count++;
            if (parents[depth - 1]->children == NULL) {
                parents[depth - 1]->children = children[depth - 1];
            }
        }
        parents[depth] = node;
    }

    if (root) {
        ui3_host_set_a11y_tree(host, root);
    } else {
        a11y_free_tree(root);
    }
    free(dup);
}

/* ---- Clipboard multi-format (P-Native P2) ---- */

void ui3_host_set_clipboard_image(ui3_host *host, const void *data, int len)
{
    if (!host) return;
    if (host->headless) {
        free(host->last_clip_image);
        if (data && len > 0) {
            host->last_clip_image = malloc(len);
            if (host->last_clip_image) {
                memcpy(host->last_clip_image, data, len);
                host->last_clip_image_len = len;
            }
        } else {
            host->last_clip_image = NULL;
            host->last_clip_image_len = 0;
        }
        return;
    }
    ui3_plat_clipboard_set_image(host, data, len);
}

char *ui3_host_get_clipboard_image(ui3_host *host, int *out_len)
{
    if (!host) {
        if (out_len) *out_len = 0;
        return NULL;
    }
    if (host->headless) {
        if (host->last_clip_image && host->last_clip_image_len > 0) {
            char *copy = malloc(host->last_clip_image_len);
            if (copy) memcpy(copy, host->last_clip_image, host->last_clip_image_len);
            if (out_len) *out_len = copy ? host->last_clip_image_len : 0;
            return copy;
        }
        if (out_len) *out_len = 0;
        return NULL;
    }
    int len = 0;
    const void *raw = ui3_plat_clipboard_get_image(host, &len);
    if (!raw) {
        if (out_len) *out_len = 0;
        return NULL;
    }
    char *copy = malloc(len);
    if (copy) memcpy(copy, raw, len);
    if (out_len) *out_len = copy ? len : 0;
    return copy;
}

void ui3_host_set_clipboard_uris(ui3_host *host, const char *uris)
{
    if (!host) return;
    free(host->last_clip_uris);
    host->last_clip_uris = uris ? strdup(uris) : NULL;
    if (!host->headless && host->last_clip_uris) {
        ui3_plat_clipboard_set_uris(host, host->last_clip_uris);
    }
}

char *ui3_host_get_clipboard_uris(ui3_host *host)
{
    if (!host) return strdup("");
    if (host->headless) return host->last_clip_uris ? strdup(host->last_clip_uris) : strdup("");
    const char *raw = ui3_plat_clipboard_get_uris(host);
    return raw ? strdup(raw) : strdup("");
}

void ui3_host_set_clipboard_html(ui3_host *host, const char *html, const char *base_url)
{
    (void)base_url;
    if (!host) return;
    free(host->last_clip_html);
    host->last_clip_html = html ? strdup(html) : NULL;
    if (!host->headless && host->last_clip_html) {
        ui3_plat_clipboard_set_html(host, host->last_clip_html, base_url);
    }
}

char *ui3_host_get_clipboard_html(ui3_host *host)
{
    if (!host) return strdup("");
    if (host->headless) return host->last_clip_html ? strdup(host->last_clip_html) : strdup("");
    const char *raw = ui3_plat_clipboard_get_html(host);
    return raw ? strdup(raw) : strdup("");
}

int ui3_host_clipboard_formats(ui3_host *host)
{
    if (!host) return 0;
    if (host->headless) {
        int m = 0;
        if (host->last_clip_text) m |= UI3_CLIP_TEXT;
        if (host->last_clip_image) m |= UI3_CLIP_IMAGE;
        if (host->last_clip_uris) m |= UI3_CLIP_FILES;
        if (host->last_clip_html) m |= UI3_CLIP_HTML;
        return m;
    }
    return ui3_plat_clipboard_formats(host);
}
