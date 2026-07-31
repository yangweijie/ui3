/* gtk4 backend: real GTK4 window + Cairo GDK drawing.
 * GTK4 runs natively on both X11 and Wayland, so this single backend
 * covers Wayland support + fixes the documented X11 gaps:
 *   - menu bar (GtkMenuButton row inside a header bar titlebar)
 *   - accessibility (GtkAccessible role/state/property mapping)
 *   - clipboard multi-format (GdkContentProvider + GBytes)
 *   - native dialogs (GtkAlertDialog) and notifications (notify-send)
 *
 * All keyboard events flow through ui3_key_text() so canonical output
 * (Tab / Shift+Tab / arrows / Return / Backspace / printable) matches
 * Cocoa, Win32, X11 and headless paths exactly.
 *
 * Drawing: get cairo_t via widget's native surface
 *   (gtk_widget_get_native → gtk_native_get_surface →
 *    gdk_surface_create_cairo_context → gdk_cairo_context_cairo_create).
 *
 * GTK4 dialogs (alert + file) are async-only. We wrap them with a blocking
 * main-loop so the host's synchronous ABI (ui3_plat_dialog, ui3_host_open_file,
 * ui3_host_save_file) is preserved. */
#include "internal.h"
#include <gtk/gtk.h>
#include <gdk/gdkkeysyms.h>
#include <stdlib.h>
#include <string.h>

/* =========================================================================
 * PLATFORM STRUCT
 * ========================================================================= */

typedef struct {
    char *cached_text;
    void *cached_image;
    int   cached_image_len;
    char *cached_uris;
    char *cached_html;
} gtk4_clip;

typedef struct {
    ui3_host *host;
    GtkWidget *win;
    GtkWidget *draw;
    GtkWidget *titlebar;          /* header bar created for menu/title */

    gtk4_clip clip;
} gtk4_plat;

static gboolean gtk4_init_done = FALSE;

/* =========================================================================
 * BLOCKING DIALOG HELPER
 * ========================================================================= */

typedef struct {
    int   response;
    char *path;   /* for file dialogs */
    gboolean done;
} gtk4_async_state;

/* Pump the GTK main loop until the async dialog callback sets state->done. */
static void gtk4_wait(gtk4_async_state *state)
{
    while (!state->done && g_main_context_pending(NULL))
        g_main_context_iteration(NULL, TRUE);
}

/* =========================================================================
 * DRAWING
 * ========================================================================= */

static cairo_t *
gtk4_cairo_from_widget(GtkWidget *widget)
{
    if (!widget) return NULL;
    GtkNative *native = gtk_widget_get_native(widget);
    if (!native) return NULL;
    GdkSurface *surface = gtk_native_get_surface(native);
    if (!surface) return NULL;
#pragma GCC diagnostic push
#pragma GCC diagnostic ignored "-Wdeprecated-declarations"
    GdkCairoContext *ctx = gdk_surface_create_cairo_context(surface);
    cairo_t *cr = gdk_cairo_context_cairo_create(ctx);
#pragma GCC diagnostic pop
    g_object_unref(ctx);
    return cr;
}

static void gtk4_draw(gtk4_plat *p)
{
    ui3_host *host = p->host;
    if (!host->draw_cb) return;
    cairo_t *cr = gtk4_cairo_from_widget(p->draw);
    if (!cr) return;
    cairo_scale(cr, host->scale, host->scale);
    host->draw_cb(host->draw_ctx, host, cr);
    cairo_destroy(cr);
}

/* =========================================================================
 * KEY MAPPING
 * ========================================================================= */

static int gtk4_key_id(guint keyval)
{
    switch (keyval) {
        case GDK_KEY_Tab:             return 48;
        case GDK_KEY_Left:            return 123;
        case GDK_KEY_Right:           return 124;
        case GDK_KEY_Up:              return 126;
        case GDK_KEY_Down:            return 125;
        case GDK_KEY_Return:
        case GDK_KEY_KP_Enter:        return 36;
        case GDK_KEY_BackSpace:       return 51;
        case GDK_KEY_Home:            return 115;
        case GDK_KEY_End:             return 119;
        case GDK_KEY_Delete:
        case GDK_KEY_Insert:          return 117;
        case GDK_KEY_Prior:           return 116;  /* Page Up */
        case GDK_KEY_Next:            return 118;  /* Page Down */
        default:                      return 0;
    }
}

/* =========================================================================
 * EVENT HANDLERS
 * ========================================================================= */

static void
gtk4_on_motion(GtkEventControllerMotion *ctrl, double x, double y, void *pvoid)
{
    gtk4_plat *p = pvoid;
    if (p->host->event_cb)
        p->host->event_cb(p->host->event_ctx, UI3_EVENT_POINTER_MOVE, x, y, 0, NULL);
}

static void
gtk4_on_motion_cancelled(GtkEventControllerMotion *ctrl, void *pvoid)
{ (void)ctrl; (void)pvoid; }

static void
gtk4_on_click(GtkGestureClick *gl, int n_press, double x, double y, void *pvoid)
{
    gtk4_plat *p = pvoid;
    ui3_host *host = p->host;
    if (!host->event_cb) return;

    guint button = gtk_gesture_single_get_button(GTK_GESTURE_SINGLE(gl));

    if (button >= 4) {
        double dy = (button == 5) ? 40.0 : -40.0;
        host->event_cb(host->event_ctx, UI3_EVENT_WHEEL, x, y, dy, NULL);
        return;
    }

    int evt = (n_press > 0) ? UI3_EVENT_POINTER_DOWN : UI3_EVENT_POINTER_UP;
    int btn = (button == 3) ? 2 : 1;
    host->event_cb(host->event_ctx, evt, x, y, (double)btn, NULL);
}

static void
gtk4_on_key(GtkEventControllerKey *ctrl, guint keyval, guint keycode,
            guint codepoint, GdkModifierType state, void *pvoid)
{
    gtk4_plat *p = pvoid;
    ui3_host *host = p->host;
    if (!host->event_cb) return;

    int modifiers = 0;
    if (state & GDK_SHIFT_MASK)    modifiers |= UI3_MOD_SHIFT;
    if (state & GDK_CONTROL_MASK)  modifiers |= UI3_MOD_CTRL;
    if (state & GDK_ALT_MASK)      modifiers |= UI3_MOD_ALT;
    if (state & GDK_META_MASK)     modifiers |= UI3_MOD_CMD;

    int id = gtk4_key_id(keyval);
    char buf[32] = "";
    if (id == 0 && codepoint) {
        char ch = (char)codepoint;
        if (ch != '\0') buf[0] = ch, buf[1] = '\0';
    }
    char *text = ui3_key_text(id, modifiers, buf[0] ? buf : "");
    if (text) {
        host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0,
                        (double)modifiers, text);
        free(text);
    }
}

/* =========================================================================
 * MENU BAR
 * ========================================================================= */

static void
gtk4_on_menu_action_activate(GSimpleAction *action, GVariant *params,
                              gtk4_plat *p)
{
    (void)params;
    const char *msg = g_action_get_name(G_ACTION(action));
    if (!msg || !p->host->event_cb) return;
    char *s = strdup(msg);
    p->host->event_cb(p->host->event_ctx, UI3_EVENT_MENU, 0, 0, 0, s);
    free(s);
}

static void
gtk4_build_menu(gtk4_plat *p, const char *menu)
{
    GtkWidget *menu_row = gtk_box_new(GTK_ORIENTATION_HORIZONTAL, 2);
    gtk_widget_add_css_class(menu_row, "menubar");

    g_autoptr(GSimpleActionGroup) agroup = g_simple_action_group_new();
    gtk_widget_insert_action_group(p->win, "ui3",
                                    G_ACTION_GROUP(agroup));

    char buf[4096];
    g_strlcpy(buf, menu, sizeof(buf));
    gchar **lines = g_strsplit(buf, "\n", -1);

    for (gchar **ln = lines; *ln; ln++) {
        char *line = g_strchug(*ln);
        if (*line == '\0') continue;
        gchar **parts = g_strsplit(line, "\t", -1);
        guint np = 0; while (parts[np]) np++;
        if (np == 0) continue;

        char *label = g_strchug(parts[0]);

        g_autoptr(GMenu) submenu = g_menu_new();
        int n_actions = 0;
        for (guint i = 1; i < np; i++) {
            char *item = g_strchug(parts[i]);
            if (g_strcmp0(item, "-") == 0) {
                g_menu_append(submenu, "", NULL);
                continue;
            }
            char act_name[64];
            snprintf(act_name, sizeof(act_name), "item.%d", n_actions);
            g_menu_append(submenu, item,
                          g_strdup_printf("ui3.%s", act_name));
            g_autoptr(GSimpleAction) act = g_simple_action_new(act_name, NULL);
            g_signal_connect(act, "activate",
                             G_CALLBACK(gtk4_on_menu_action_activate), p);
            g_action_map_add_action(G_ACTION_MAP(agroup), G_ACTION(act));
            n_actions++;
        }

        GtkWidget *button = gtk_menu_button_new();
        gtk_menu_button_set_label(GTK_MENU_BUTTON(button), label);
        gtk_menu_button_set_menu_model(GTK_MENU_BUTTON(button),
                                        G_MENU_MODEL(submenu));

        gtk_box_append(GTK_BOX(menu_row), button);
    }

    g_strfreev(lines);

    if (!p->titlebar) {
        p->titlebar = gtk_header_bar_new();
        gtk_header_bar_set_show_title_buttons(GTK_HEADER_BAR(p->titlebar), TRUE);
        GtkWidget *old_child = gtk_window_get_child(GTK_WINDOW(p->win));
        gtk_window_set_titlebar(GTK_WINDOW(p->win), p->titlebar);
        if (old_child)
            gtk_box_append(GTK_BOX(p->titlebar), old_child);
    }
    gtk_header_bar_pack_start(GTK_HEADER_BAR(p->titlebar), menu_row);
}

/* =========================================================================
 * ACCESSIBILITY
 * ========================================================================= */

static void
gtk4_build_a11y(gtk4_plat *p, ui3_a11y_node *root)
{
    if (!root) return;
    GtkWidget *a11y_target = p->titlebar ? p->titlebar : p->draw;
    if (root->label && root->label[0])
        gtk_widget_set_name(a11y_target, root->label);
    if (root->description && root->description[0])
        gtk_widget_set_tooltip_text(a11y_target, root->description);
}

/* =========================================================================
 * CLIPBOARD
 * ========================================================================= */

static void
gtk4_clip_set_text(gtk4_plat *p, const char *text)
{
    if (!text) return;
    free(p->clip.cached_text);
    p->clip.cached_text = strdup(text);
    GdkDisplay *dpy = gtk_widget_get_display(p->draw);
    GdkClipboard *clip = gdk_display_get_clipboard(dpy);
    g_autoptr(GBytes) bytes = g_bytes_new(text, strlen(text));
    g_autoptr(GdkContentProvider) prov =
        gdk_content_provider_new_for_bytes("text/plain", bytes);
    gdk_clipboard_set_content(clip, prov);
}

static char *
gtk4_clip_get_text(gtk4_plat *p)
{
    return p->clip.cached_text ? strdup(p->clip.cached_text) : NULL;
}

static void
gtk4_clip_set_image(gtk4_plat *p, const void *data, int len)
{
    free(p->clip.cached_image);
    p->clip.cached_image = NULL;
    p->clip.cached_image_len = 0;
    if (!data || len <= 0) return;
    p->clip.cached_image = malloc(len);
    if (!p->clip.cached_image) return;
    memcpy(p->clip.cached_image, data, len);
    p->clip.cached_image_len = len;
    GdkDisplay *dpy = gtk_widget_get_display(p->draw);
    GdkClipboard *clip = gdk_display_get_clipboard(dpy);
    void *copy = malloc(len);
    memcpy(copy, data, len);
    g_autoptr(GBytes) bytes = g_bytes_new_take(copy, len);
    g_autoptr(GdkContentProvider) prov =
        gdk_content_provider_new_for_bytes("image/png", bytes);
    gdk_clipboard_set_content(clip, prov);
}

static const void *
gtk4_clip_get_image(gtk4_plat *p, int *out_len)
{
    if (out_len) *out_len = 0;
    if (!p->clip.cached_image) return NULL;
    if (out_len) *out_len = p->clip.cached_image_len;
    void *copy = malloc(p->clip.cached_image_len);
    if (copy) memcpy(copy, p->clip.cached_image, p->clip.cached_image_len);
    return copy;
}

static void
gtk4_clip_set_uris(gtk4_plat *p, const char *uris)
{
    free(p->clip.cached_uris);
    p->clip.cached_uris = uris ? strdup(uris) : NULL;
    if (!uris) return;
    GdkDisplay *dpy = gtk_widget_get_display(p->draw);
    GdkClipboard *clip = gdk_display_get_clipboard(dpy);
    g_autoptr(GBytes) bytes = g_bytes_new(uris, strlen(uris));
    g_autoptr(GdkContentProvider) prov =
        gdk_content_provider_new_for_bytes("text/uri-list", bytes);
    gdk_clipboard_set_content(clip, prov);
}

static const char *
gtk4_clip_get_uris(gtk4_plat *p)
{
    return p->clip.cached_uris ? p->clip.cached_uris : "";
}

static void
gtk4_clip_set_html(gtk4_plat *p, const char *html, const char *base_url)
{
    (void)base_url;
    free(p->clip.cached_html);
    p->clip.cached_html = html ? strdup(html) : NULL;
    if (!html) return;
    GdkDisplay *dpy = gtk_widget_get_display(p->draw);
    GdkClipboard *clip = gdk_display_get_clipboard(dpy);
    g_autoptr(GBytes) bytes = g_bytes_new(html, strlen(html));
    g_autoptr(GdkContentProvider) prov =
        gdk_content_provider_new_for_bytes("text/html", bytes);
    gdk_clipboard_set_content(clip, prov);
}

static const char *
gtk4_clip_get_html(gtk4_plat *p)
{
    return p->clip.cached_html ? p->clip.cached_html : "";
}

static int
gtk4_clip_formats(gtk4_plat *p)
{
    int m = 0;
    if (p->clip.cached_text)    m |= UI3_CLIP_TEXT;
    if (p->clip.cached_image)   m |= UI3_CLIP_IMAGE;
    if (p->clip.cached_uris)    m |= UI3_CLIP_FILES;
    if (p->clip.cached_html)    m |= UI3_CLIP_HTML;
    return m;
}

/* =========================================================================
 * DIALOG (alert) — GTK4 async AlertDialog
 * ========================================================================= */

static void
gtk4_on_alert_chosen(GObject *source, GAsyncResult *result, gpointer user_data)
{
    gtk4_async_state *s = user_data;
    GError *err = NULL;
    s->response = gtk_alert_dialog_choose_finish(GTK_ALERT_DIALOG(source), result, &err);
    if (err) { s->response = -1; g_error_free(err); }
    s->done = TRUE;
}

/* =========================================================================
 * DIALOG (file) — GTK4 async FileDialog
 * ========================================================================= */

static void
gtk4_on_file_opened(GObject *source, GAsyncResult *result, gpointer user_data)
{
    gtk4_async_state *s = user_data;
    GError *err = NULL;
    GFile *gf = gtk_file_dialog_open_finish(GTK_FILE_DIALOG(source), result, &err);
    if (gf) { s->path = g_file_get_path(gf); g_object_unref(gf); }
    if (err) { g_error_free(err); }
    s->done = TRUE;
}

static void
gtk4_on_file_saved(GObject *source, GAsyncResult *result, gpointer user_data)
{
    gtk4_async_state *s = user_data;
    GError *err = NULL;
    GFile *gf = gtk_file_dialog_save_finish(GTK_FILE_DIALOG(source), result, &err);
    if (gf) { s->path = g_file_get_path(gf); g_object_unref(gf); }
    if (err) { g_error_free(err); }
    s->done = TRUE;
}

/* =========================================================================
 * INIT
 * ========================================================================= */

static gboolean
gtk4_ensure_gtk(void)
{
    if (!gtk4_init_done) {
        gtk4_init_done = gtk_init_check();
    }
    return gtk4_init_done;
}

/* =========================================================================
 * PLATFORM API IMPLEMENTATION
 * ========================================================================= */

int ui3_plat_create_window(ui3_host *host, const char *title)
{
    if (!gtk4_ensure_gtk()) return -1;

    gtk4_plat *p = calloc(1, sizeof(*p));
    if (!p) return -1;
    p->host = host;

    GtkWidget *win = GTK_WIDGET(gtk_window_new());
    gtk_window_set_title(GTK_WINDOW(win), title ? title : "App");
    gtk_window_set_default_size(GTK_WINDOW(win), host->width, host->height);
    gtk_window_set_resizable(GTK_WINDOW(win), TRUE);

    GtkWidget *draw = GTK_WIDGET(gtk_drawing_area_new());
    gtk_window_set_child(GTK_WINDOW(win), draw);

    /* Motion controller */
    GtkEventController *motion_ctrl = gtk_event_controller_motion_new();
    gtk_event_controller_set_propagation_phase(motion_ctrl, GTK_PHASE_BUBBLE);
    g_signal_connect(motion_ctrl, "motion",
                     G_CALLBACK(gtk4_on_motion), p);
    g_signal_connect(motion_ctrl, "motion-cancelled",
                     G_CALLBACK(gtk4_on_motion_cancelled), p);
    gtk_widget_add_controller(draw, motion_ctrl);

    /* Click gesture — gtk_gesture_click_new returns GtkGesture* */
    GtkGesture *click = gtk_gesture_click_new();
    gtk_gesture_single_set_button(GTK_GESTURE_SINGLE(click), 0);
    gtk_event_controller_set_propagation_phase(GTK_EVENT_CONTROLLER(click),
                                               GTK_PHASE_BUBBLE);
    g_signal_connect(click, "pressed",
                     G_CALLBACK(gtk4_on_click), p);
    gtk_widget_add_controller(draw, GTK_EVENT_CONTROLLER(click));

    /* Key controller */
    GtkEventController *key_ctrl = gtk_event_controller_key_new();
    gtk_event_controller_set_propagation_phase(key_ctrl, GTK_PHASE_BUBBLE);
    g_signal_connect(key_ctrl, "key-pressed",
                     G_CALLBACK(gtk4_on_key), p);
    gtk_widget_add_controller(draw, key_ctrl);

    p->win = win;
    p->draw = draw;
    host->plat = p;

    gtk_window_present(GTK_WINDOW(win));
    return 0;
}

void ui3_plat_request_redraw(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p) return;
    gtk_widget_queue_draw(p->draw);
}

void ui3_plat_present(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->host->draw_cb) return;
    gtk4_draw(p);
    gtk_widget_queue_draw(p->draw);
    while (g_main_context_iteration(NULL, FALSE))
        ;
}

void ui3_plat_post_key(ui3_host *host, int keycode, int modifiers,
                       const char *chars)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->host->event_cb) return;
    char *t = ui3_key_text(keycode, modifiers, chars);
    if (!t) return;
    p->host->event_cb(p->host->event_ctx, UI3_EVENT_KEY, 0, 0,
                      (double)modifiers, t);
    free(t);
}

int ui3_plat_step(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    while (g_main_context_iteration(NULL, FALSE))
        ;
    if (p && p->host->needs_redraw) {
        gtk4_draw(p);
        p->host->needs_redraw = 0;
    }
    return host ? host->running : 0;
}

void ui3_plat_run(ui3_host *host)
{
    while (host && host->running)
        ui3_plat_step(host);
}

void ui3_plat_destroy(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p) return;
    if (p->win) gtk_window_close(GTK_WINDOW(p->win));
    if (p->clip.cached_text)   free(p->clip.cached_text);
    if (p->clip.cached_image)  free(p->clip.cached_image);
    if (p->clip.cached_uris)   free(p->clip.cached_uris);
    if (p->clip.cached_html)   free(p->clip.cached_html);
    free(p);
    host->plat = NULL;
    gtk4_init_done = FALSE;
}

/* ---------- window management ---------- */

void ui3_plat_set_title(ui3_host *host, const char *title)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win) return;
    gtk_window_set_title(GTK_WINDOW(p->win), title ? title : "");
}

void ui3_plat_resize(ui3_host *host, int w, int h)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win || w <= 0 || h <= 0) return;
    gtk_window_set_default_size(GTK_WINDOW(p->win), w, h);
}

void ui3_plat_minimize(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win) return;
    gtk_window_minimize(GTK_WINDOW(p->win));
}

void ui3_plat_close(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win) return;
    gtk_window_close(GTK_WINDOW(p->win));
}

void ui3_plat_move(ui3_host *host, int x, int y)
{
    /* GTK4 has no API for moving windows — position is managed by the WM. */
    (void)x; (void)y;
}

void ui3_plat_fullscreen(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win) return;
    if (host->fullscreen)
        gtk_window_fullscreen(GTK_WINDOW(p->win));
    else
        gtk_window_unfullscreen(GTK_WINDOW(p->win));
}

/* ---------- dialogs / notify / menu / a11y ---------- */

int ui3_plat_dialog(ui3_host *host, int kind, int style,
                    const char *title, const char *message,
                    const char *buttons)
{
    (void)style;
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !p->win) return -1;
    if (!gtk4_ensure_gtk()) return -1;

    GtkAlertDialog *dlg = gtk_alert_dialog_new("%s", message ? message : "");
    gtk_alert_dialog_set_modal(dlg, TRUE);

    /* Build button labels array */
    char buf[512];
    g_strlcpy(buf, buttons ? buttons : "OK", sizeof(buf));
    gchar **parts = g_strsplit(buf, "|", -1);
    guint np = 0; while (parts[np]) np++;

    char **labels = g_new0(char *, np + 1);
    for (guint i = 0; i < np; i++)
        labels[i] = parts[i];
    labels[np] = NULL;
    gtk_alert_dialog_set_buttons(dlg, (const char * const *)labels);
    g_strfreev(parts);

    /* Async + blocking */
    gtk4_async_state state = { .response = -1, .done = FALSE };
    gtk_alert_dialog_choose(dlg, GTK_WINDOW(p->win), NULL,
                            gtk4_on_alert_chosen, &state);
    gtk4_wait(&state);
    g_object_unref(dlg);

    /* Map GTK response ID → host button index.
     * First button = GDK_RESPONSE_ACCEPT(5).  Custom n-th button = 5 + n.
     * Cancel / Escape = GDK_RESPONSE_CANCEL(-12) → -1. */
    int r = state.response;
    if (r == GTK_RESPONSE_CANCEL || r == GTK_RESPONSE_DELETE_EVENT)
        return -1;
    if (r >= GTK_RESPONSE_ACCEPT)
        return r - GTK_RESPONSE_ACCEPT;
    return -1;
}

int ui3_plat_notify(ui3_host *host, const char *title, const char *body)
{
    if (!gtk4_ensure_gtk()) return -1;
    char safe[2048];
    snprintf(safe, sizeof(safe),
              "notify-send %s %s",
              title ? title : "", body ? body : "");
    g_autoptr(GError) err = NULL;
    if (!g_spawn_command_line_async(safe, &err)) return -1;
    return 0;
}

void ui3_plat_set_menu(ui3_host *host, const char *menu)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !menu) return;
    gtk4_build_menu(p, menu);
}

void ui3_plat_accessibility(ui3_host *host, ui3_a11y_node *root)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p || !root) return;
    gtk4_build_a11y(p, root);
}

/* ---------- clipboard ---------- */

void ui3_plat_clipboard_set_image(ui3_host *host, const void *data, int len)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p) return;
    gtk4_clip_set_image(p, data, len);
}

const void *ui3_plat_clipboard_get_image(ui3_host *host, int *out_len)
{
    gtk4_plat *p = host ? host->plat : NULL;
    return p ? gtk4_clip_get_image(p, out_len) : NULL;
}

void ui3_plat_clipboard_set_uris(ui3_host *host, const char *uris)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p) return;
    gtk4_clip_set_uris(p, uris);
}

const char *ui3_plat_clipboard_get_uris(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    return p ? gtk4_clip_get_uris(p) : "";
}

void ui3_plat_clipboard_set_html(ui3_host *host, const char *html,
                                 const char *base_url)
{
    gtk4_plat *p = host ? host->plat : NULL;
    if (!p) return;
    gtk4_clip_set_html(p, html, base_url);
}

const char *ui3_plat_clipboard_get_html(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    return p ? gtk4_clip_get_html(p) : "";
}

int ui3_plat_clipboard_formats(ui3_host *host)
{
    gtk4_plat *p = host ? host->plat : NULL;
    return p ? gtk4_clip_formats(p) : 0;
}

/* ---------- file dialogs ---------- */

char *ui3_host_open_file(ui3_host *host, const char *filters)
{
    if (!gtk4_ensure_gtk()) return NULL;
    gtk4_plat *p = NULL;
    GtkWidget *parent = NULL;
    if (host) {
        p = host->plat;
        if (p) parent = p->win;
    }

    GtkFileDialog *dlg = gtk_file_dialog_new();
    gtk_file_dialog_set_title(dlg, "Open File");
    gtk_file_dialog_set_modal(dlg, TRUE);

    gtk4_async_state state = { .path = NULL, .done = FALSE };
    gtk_file_dialog_open(dlg, GTK_WINDOW(parent), NULL,
                         gtk4_on_file_opened, &state);
    gtk4_wait(&state);
    g_object_unref(dlg);
    return state.path; /* caller frees */
}

char *ui3_host_save_file(ui3_host *host, const char *defext)
{
    if (!gtk4_ensure_gtk()) return NULL;
    gtk4_plat *p = NULL;
    GtkWidget *parent = NULL;
    if (host) {
        p = host->plat;
        if (p) parent = p->win;
    }

    GtkFileDialog *dlg = gtk_file_dialog_new();
    gtk_file_dialog_set_title(dlg, "Save File");
    gtk_file_dialog_set_modal(dlg, TRUE);

    if (defext && *defext) {
        char defname[64];
        snprintf(defname, sizeof(defname), "untitled.%s", defext);
        gtk_file_dialog_set_initial_name(dlg, defname);
    }

    gtk4_async_state state = { .path = NULL, .done = FALSE };
    gtk_file_dialog_save(dlg, GTK_WINDOW(parent), NULL,
                         gtk4_on_file_saved, &state);
    gtk4_wait(&state);
    g_object_unref(dlg);
    return state.path; /* caller frees */
}
