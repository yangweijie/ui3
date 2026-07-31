/* x11 backend: real X11 window + Cairo Xlib surface.
 * Keyboard events are translated through the SAME ui3_key_text() the Cocoa
 * and headless paths use, so the canonical key text (Tab / Shift+Tab / arrows
 * / Return / Backspace / printable) is identical across every platform. */
#include "internal.h"
#include <X11/Xlib.h>
#include <X11/Xutil.h>
#include <X11/keysym.h>
#include <X11/extensions/XInput2.h>
#include <cairo/cairo.h>
#include <cairo/cairo-xlib.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>

typedef struct {
    ui3_host *host;
    Display *dpy;
    Window win;
    cairo_surface_t *surface;
    Atom wm_delete;

    /* Xdnd drag-and-drop support */
    Atom xdnd_aware;
    Atom xdnd_drop;
    int  xdnd_dropping;   /* TRUE while waiting for XdndSelection reply */

    /* XI2 multitouch tracking for gesture detection */
    int xi2_opcode;
    struct { int id; int active; double x, y; } touches[10];
    int touch_count;
    double ref_cx, ref_cy, ref_dist, ref_angle;
    int gesture_gtype;  /* -1=unclassified, 0=pinch, 1=rotate, 3=pan */
} x11_plat;

static void x11_draw(x11_plat *p)
{
    ui3_host *host = p->host;
    if (!p->surface || !host->draw_cb) return;
    cairo_t *cr = cairo_create(p->surface);
    cairo_scale(cr, host->scale, host->scale);
    host->draw_cb(host->draw_ctx, host, cr);
    cairo_destroy(cr);
    cairo_surface_flush(p->surface);
    XFlush(p->dpy);
}

/* X11 keysym -> the logical id space ui3_key_text() expects (values mirror
 * macOS virtual keycodes so output matches the other platforms). */
static int x11_key_id(KeySym sym)
{
    switch (sym) {
        case XK_Tab:
        case XK_ISO_Left_Tab: return 48;   /* Shift+Tab yields ISO_Left_Tab */
        case XK_Left:         return 123;
        case XK_Right:        return 124;
        case XK_Up:           return 126;
        case XK_Down:         return 125;
        case XK_Return:
        case XK_KP_Enter:     return 36;
        case XK_BackSpace:    return 51;
        case XK_Home:         return 115;
        case XK_End:          return 119;
        case XK_Delete:       return 117;
        default:              return 0;
    }
}

static void x11_key(x11_plat *p, XKeyEvent *ke)
{
    ui3_host *host = p->host;
    if (!host->event_cb) return;
    int modifiers = 0;
    if (ke->state & ShiftMask)   modifiers |= UI3_MOD_SHIFT;
    if (ke->state & ControlMask) modifiers |= UI3_MOD_CTRL;
    if (ke->state & Mod1Mask)    modifiers |= UI3_MOD_ALT;
    if (ke->state & Mod4Mask)    modifiers |= UI3_MOD_CMD;

    KeySym sym = XLookupKeysym(ke, modifiers & UI3_MOD_SHIFT ? 1 : 0);
    int id = x11_key_id(sym);

    char buf[32] = "";
    if (id == 0) {
        /* printable: XLookupString applies Shift for us (e.g. 'A'). */
        int n = XLookupString(ke, buf, sizeof(buf) - 1, NULL, NULL);
        if (n > 0) buf[n] = '\0'; else buf[0] = '\0';
    }

    char *text = ui3_key_text(id, modifiers, buf[0] ? buf : "");
    if (text) {
        host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, text);
        free(text);
    }
}

static void x11_dispatch(x11_plat *p, XEvent *ev)
{
    ui3_host *host = p->host;
    switch (ev->type) {
        case Expose:
            x11_draw(p);
            break;
        case KeyPress:
            x11_key(p, &ev->xkey);
            break;
        case ButtonPress:
            if (host->event_cb) {
                if (ev->xbutton.button == 4 || ev->xbutton.button == 5) {
                    // data > 0 == scroll down. X11 Button5 is down, Button4 up.
                    double dy = (ev->xbutton.button == 5) ? 40.0 : -40.0;
                    host->event_cb(host->event_ctx, UI3_EVENT_WHEEL,
                                   (double)ev->xbutton.x, (double)ev->xbutton.y, dy, NULL);
                } else {
                    int btn = (ev->xbutton.button == 3) ? 2 : 1;
                    host->event_cb(host->event_ctx, UI3_EVENT_POINTER_DOWN,
                                   (double)ev->xbutton.x, (double)ev->xbutton.y, (double)btn, NULL);
                }
            }
            break;
        case ButtonRelease:
            if (host->event_cb)
                int btn = (ev->xbutton.button == 3) ? 2 : 1;
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_UP,
                               (double)ev->xbutton.x, (double)ev->xbutton.y, (double)btn, NULL);
            break;
        case MotionNotify:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_MOVE,
                               (double)ev->xbutton.x, (double)ev->xbutton.y, 0, NULL);
            break;
        case ClientMessage:
            if ((Atom)ev->xclient.data.l[0] == p->wm_delete) {
                if (host->close_cb) {
                    int accept = 1;
                    host->close_cb(host->close_ctx, &accept);
                    if (!accept) break;
                }
                host->running = 0;
            } else if (!x11_handle_xdnd(p, &ev->xclient)) {
                /* ignore non-Xdnd, non-WM_DELETE_WINDOW client messages */
            }
            break;
        case SelectionNotify:
            x11_handle_selection_notify(p, &ev->xselection);
            break;
        case GenericEvent:
        {
            XGenericEventCookie *cookie = &ev->xcookie;
            if (XGetEventData(p->dpy, cookie) && cookie->extension == p->xi2_opcode) {
                XIDeviceEvent *devev = (XIDeviceEvent *)cookie->data;
                x11_handle_touch(p, devev);
                XFreeEventData(p->dpy, cookie);
            }
            break;
        }
        default:
            break;
    }
}

/* ---- XI2 Touch gesture detection ---- */

static void x11_handle_touch(x11_plat *p, XIDeviceEvent *e)
{
    if (!p) return;
    ui3_host *host = p->host;
    if (host->headless) return;
    if (!host->event_cb) return;

    int event_type = e->evtype;
    int touch_id = e->detail;
    int found = -1;
    for (int i = 0; i < 10; i++) {
        if (p->touches[i].id == touch_id) {
            found = i;
            break;
        }
    }

    if (event_type == XI_TouchBegin) {
        if (found == -1) {
            int idx = -1;
            for (int i = 0; i < 10; i++) {
                if (!p->touches[i].active) { idx = i; break; }
            }
            if (idx == -1) return;
            p->touches[idx].id = touch_id;
            p->touches[idx].active = 1;
            p->touches[idx].x = e->event_x;
            p->touches[idx].y = e->event_y;
            p->touch_count++;
        } else {
            p->touches[found].x = e->event_x;
            p->touches[found].y = e->event_y;
        }

        if (p->touch_count == 2) {
            int a = -1, b = -1;
            for (int i = 0; i < 10; i++) {
                if (p->touches[i].active) {
                    if (a == -1) a = i; else b = i;
                }
            }
            if (a != -1 && b != -1) {
                p->ref_cx = (p->touches[a].x + p->touches[b].x) / 2.0;
                p->ref_cy = (p->touches[a].y + p->touches[b].y) / 2.0;
                double dx = p->touches[b].x - p->touches[a].x;
                double dy = p->touches[b].y - p->touches[a].y;
                p->ref_dist = sqrt(dx * dx + dy * dy);
                p->ref_angle = atan2(dy, dx);
                p->gesture_gtype = 0; /* start as pinch */
            }
        }
    } else if (event_type == XI_TouchUpdate) {
        if (found != -1) {
            p->touches[found].x = e->event_x;
            p->touches[found].y = e->event_y;
        } else {
            int idx = -1;
            for (int i = 0; i < 10; i++) {
                if (!p->touches[i].active) { idx = i; break; }
            }
            if (idx == -1) return;
            p->touches[idx].id = touch_id;
            p->touches[idx].active = 1;
            p->touches[idx].x = e->event_x;
            p->touches[idx].y = e->event_y;
            p->touch_count++;
        }

        if (p->touch_count == 1) {
            p->gesture_gtype = 3; /* pan */
            int idx = -1;
            for (int i = 0; i < 10; i++) {
                if (p->touches[i].active) { idx = i; break; }
            }
            if (idx != -1) {
                double x = p->touches[idx].x, y = p->touches[idx].y;
                char buf[64];
                snprintf(buf, sizeof(buf), "%f,%f", x, y);
                host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                               0, 0, (double)3, buf);
            }
        } else if (p->touch_count == 2) {
            int a = -1, b = -1;
            for (int i = 0; i < 10; i++) {
                if (p->touches[i].active) {
                    if (a == -1) a = i; else b = i;
                }
            }
            if (a == -1 || b == -1) return;
            double cx = (p->touches[a].x + p->touches[b].x) / 2.0;
            double cy = (p->touches[a].y + p->touches[b].y) / 2.0;
            double dx = p->touches[b].x - p->touches[a].x;
            double dy = p->touches[b].y - p->touches[a].y;
            double dist = sqrt(dx * dx + dy * dy);
            double angle = atan2(dy, dx);
            double scale = (p->ref_dist > 0.01) ? dist / p->ref_dist : 1.0;
            double dangle = angle - p->ref_angle;
            double cx_delta = cx - p->ref_cx;
            double cy_delta = cy - p->ref_cy;

            /* Deliver pinch (scale) and rotate (angle) together; caller picks. */
            char buf[64];
            snprintf(buf, sizeof(buf), "%f,%f,%f,%f", scale, dangle, cx_delta, cy_delta);
            host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                           0, 0, (double)0, buf);

            p->ref_cx = cx; p->ref_cy = cy;
            p->ref_dist = dist; p->ref_angle = angle;
            p->gesture_gtype = 0;
        }
    } else if (event_type == XI_TouchEnd) {
        if (found != -1) {
            p->touches[found].active = 0;
            p->touches[found].id = -1;
        }
        if (p->touch_count > 0) p->touch_count--;
        p->gesture_gtype = -1;
    }
}

/* ---- Xdnd drag-and-drop (version 5) ---- */

/* Send XdndStatus reply to source: action = copy (1). */
static void
x11_xdnd_status(x11_plat *p, Window src, long x, long y, int action)
{
    XClientMessage msg;
    msg.type = ClientMessage;
    msg.message_type = p->xdnd_drop;
    msg.display = p->dpy;
    msg.window = src;
    msg.format = 32;
    msg.data.l[0] = p->win;
    msg.data.l[1] = action;             /* XdndActionCopy = 1 */
    msg.data.l[2] = (short)x << 16 | (short)y;
    msg.data.l[3] = 0;                  /* rect length */
    msg.data.l[4] = CurrentTime;
    XSendEvent(p->dpy, src, False, NoEventMask, &msg);
    XFlush(p->dpy);
}

static void
x11_xdnd_request_files(x11_plat *p, Window src, long x, long y)
{
    p->xdnd_dropping = 1;

    XClientMessage msg;
    msg.type = ClientMessage;
    msg.message_type = p->xdnd_drop;
    msg.display = p->dpy;
    msg.window = src;
    msg.format = 32;
    msg.data.l[0] = p->win;
    msg.data.l[1] = CurrentTime;
    msg.data.l[2] = 0;
    msg.data.l[3] = 0;
    msg.data.l[4] = 0;
    XSendEvent(p->dpy, src, False, NoEventMask, &msg);

    Atom xdnd_sel = XInternAtom(p->dpy, "XdndSelection", False);
    XSelectionRequestEvent req;
    req.type = SelectionRequest;
    req.owner = src;
    req.requestor = p->win;
    req.selection = xdnd_sel;
    req.target = XA_STRING;
    req.property = XA_STRING;
    req.time = CurrentTime;
    XSendEvent(p->dpy, src, False, NoEventMask, (XEvent *)&req);
    XFlush(p->dpy);
}

static int x11_handle_xdnd(x11_plat *p, XClientMessageEvent *ev)
{
    long *data = ev->data.l;
    if (data[1] == 0) return 0;

    switch ((int)data[1]) {
        case 1:
            x11_xdnd_status(p, ev->window, 0, 0, 1);
            break;
        case 2: {
            int x = data[2] & 0xFFFF;
            int y = (data[2] >> 16) & 0xFFFF;
            x11_xdnd_status(p, ev->window, x, y, 1);
            break;
        }
        case 3: {
            long x = data[2] & 0xFFFF;
            long y = (data[2] >> 16) & 0xFFFF;
            x11_xdnd_request_files(p, ev->window, x, y);
            break;
        }
        case 4:
            p->xdnd_dropping = 0;
            break;
        default:
            break;
    }
    return 1;
}

static void x11_handle_selection_notify(x11_plat *p, XSelectionEvent *ev)
{
    if (!p->xdnd_dropping) return;
    if (ev->target != XA_STRING || ev->property == None) return;

    char *data = NULL;
    int nitems, remaining;
    unsigned char type;
    int fmt;
    XGetWindowProperty(p->dpy, p->win, ev->property,
                       0, 65536, False, AnyPropertyType,
                       &type, &fmt, (unsigned char **)&data,
                       &nitems, &remaining);

    if (data && nitems > 0) {
        data[nitems] = '\0';
        x11_xdnd_deliver_drop(p, (char *)data, 0, 0);
    }
    if (data) XFree(data);
    p->xdnd_dropping = 0;
}

static void x11_xdnd_deliver_drop(x11_plat *p, const char *files,
                                  long x, long y)
{
    ui3_host *host = p->host;
    if (!host->event_cb || !files) return;
    double cx = (x > 0) ? x / host->scale : 0.0;
    double cy = (y > 0) ? y / host->scale : 0.0;
    char *payload = strdup(files);
    host->event_cb(host->event_ctx, UI3_EVENT_DROP, cx, cy, 1.0, payload);
    free(payload);
}

int ui3_plat_create_window(ui3_host *host, const char *title)
{
    Display *dpy = XOpenDisplay(NULL);
    if (!dpy) return -1;   /* no X server -> host falls back to headless */

    int screen = DefaultScreen(dpy);
    XSetWindowAttributes wa;
    wa.event_mask = ExposureMask | KeyPressMask | ButtonPressMask |
                    ButtonReleaseMask | PointerMotionMask;

    Window win = XCreateWindow(dpy, RootWindow(dpy, screen),
                               0, 0, host->width, host->height, 0,
                               CopyFromParent, InputOutput, CopyFromParent,
                               CWEventMask, &wa);
    XStoreName(dpy, win, title ? title : "App");

    /* Open centered on the screen (XCreateWindow put it at 0,0). */
    int sw = DisplayWidth(dpy, screen);
    int sh = DisplayHeight(dpy, screen);
    XMoveWindow(dpy, win, (sw - host->width) / 2, (sh - host->height) / 2);

    x11_plat *p = malloc(sizeof(*p));
    memset(p, 0, sizeof(*p));
    p->host = host;
    p->dpy = dpy;
    p->win = win;
    p->xdnd_aware = XInternAtom(dpy, "XdndAware", False);
    p->xdnd_drop  = XInternAtom(dpy, "XdndDrop", False);
    p->xdnd_dropping = 0;
    p->surface = cairo_xlib_surface_create(dpy, win,
                                           DefaultVisual(dpy, screen),
                                           host->width, host->height);
    p->wm_delete = XInternAtom(dpy, "WM_DELETE_WINDOW", False);
    XSetWMProtocols(dpy, win, &p->wm_delete, 1);

    /* Advertise as Xdnd drop target (version 5).
     * types[0..2] are the target atoms we accept (XA_STRING = generic text);
     * the source will send file:// URIs in this format. */
    Atom xdnd_types[3] = {
        XInternAtom(dpy, "text/plain", False),
        XInternAtom(dpy, "text/uri-list", False),
        XA_STRING
    };
    long xdnd_data[4] = { 5, xdnd_types[0], xdnd_types[1], xdnd_types[2] };
    XChangeProperty(dpy, win, p->xdnd_aware, p->xdnd_aware, 32,
                    PropModeReplace, (const unsigned char *)xdnd_data, 4);

    host->plat = p;
    host->scale = 1.0;
    XMapWindow(dpy, win);

    /* Select XI2 touch events for gesture detection. */
    int xi_event, xi_error;
    if (XQueryExtension(dpy, "XInputExtension", &p->xi2_opcode, &xi_event, &xi_error)) {
        int major = 2, minor = 0;
        if (XIQueryVersion(dpy, &major, &minor) == Success && major >= 2) {
            unsigned char mask[(XIMaskLen(XI_LASTEVENT) + 3) / 4] = {0};
            XISetMask(mask, XI_TouchBegin);
            XISetMask(mask, XI_TouchUpdate);
            XISetMask(mask, XI_TouchEnd);
            XIEventMask evmask;
            evmask.deviceid = XIAllMasterDevices;
            evmask.mask_len = sizeof(mask);
            evmask.mask = mask;
            XISelectEvents(dpy, win, &evmask, 1);
        }
    }
    p->touch_count = 0;
    p->gesture_gtype = -1;
    memset(p->touches, 0, sizeof(p->touches));
    p->ref_cx = p->ref_cy = p->ref_dist = p->ref_angle = 0;

    XFlush(dpy);
    return 0;
}

/* ASYNC: only flag the need; the run loop (ui3_plat_run) draws when idle so a
 * draw callback that requests a redraw cannot re-enter paint() synchronously. */
void ui3_plat_request_redraw(ui3_host *host)
{
    if (!host) return;
    host->needs_redraw = 1;
}

/* SYNC: paint one frame immediately. */
void ui3_plat_present(ui3_host *host)
{
    x11_plat *p = host->plat;
    if (!p) return;
    x11_draw(p);
}

void ui3_plat_post_key(ui3_host *host, int keycode, int modifiers, const char *chars)
{
    if (!host || !host->event_cb) return;
    char *t = ui3_key_text(keycode, modifiers, chars);
    if (!t) return;
    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, t);
    free(t);
}

int ui3_plat_step(ui3_host *host)
{
    x11_plat *p = host->plat;
    if (!p) return host ? host->running : 0;
    while (XPending(p->dpy)) {
        XEvent ev;
        XNextEvent(p->dpy, &ev);
        x11_dispatch(p, &ev);
        if (!host->running) break;
    }
    return host->running;
}

void ui3_plat_run(ui3_host *host)
{
    x11_plat *p = host->plat;
    if (!p) return;
    while (host->running) {
        if (host->needs_redraw) {
            /* Animation/fade keeps requesting redraws: present and spin so the
             * frame loop advances without blocking on the X event queue. */
            ui3_plat_present(p);
            host->needs_redraw = 0;
            usleep(4000); /* ~250Hz cap while animating; keeps CPU sane */
        } else {
            XEvent ev;
            XNextEvent(p->dpy, &ev);   /* blocking when idle (0 CPU) */
            x11_dispatch(p, &ev);
        }
    }
}

void ui3_plat_destroy(ui3_host *host)
{
    x11_plat *p = host->plat;
    if (!p) return;
    if (p->surface) cairo_surface_destroy(p->surface);
    if (p->win) XDestroyWindow(p->dpy, p->win);
    if (p->dpy) XCloseDisplay(p->dpy);
    free(p);
    host->plat = NULL;
}

/* ---- Window management (P1) ---- */
void ui3_plat_set_title(ui3_host *host, const char *title)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    XStoreName(p->dpy, p->win, title ? title : "App");
    XSetIconName(p->dpy, p->win, title ? title : "App");
    XFlush(p->dpy);
}

void ui3_plat_resize(ui3_host *host, int w, int h)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    XResizeWindow(p->dpy, p->win, w, h);
    if (p->surface) cairo_xlib_surface_set_size(p->surface, w, h);
    XFlush(p->dpy);
}

void ui3_plat_minimize(ui3_host *host)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    XIconifyWindow(p->dpy, p->win, DefaultScreen(p->dpy));
    XFlush(p->dpy);
}

void ui3_plat_close(ui3_host *host)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    XDestroyWindow(p->dpy, p->win);
    p->win = 0;
    XFlush(p->dpy);
}

void ui3_plat_move(ui3_host *host, int x, int y)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    XMoveWindow(p->dpy, p->win, x, y);
    XFlush(p->dpy);
}

void ui3_plat_fullscreen(ui3_host *host)
{
    if (!host || !host->plat) return;
    x11_plat *p = host->plat;
    if (!p->dpy || !p->win) return;
    Atom wm_state = XInternAtom(p->dpy, "_NET_WM_STATE", False);
    Atom fullscreen = XInternAtom(p->dpy, "_NET_WM_STATE_FULLSCREEN", False);
    XEvent ev;
    memset(&ev, 0, sizeof(ev));
    ev.type = ClientMessage;
    ev.xclient.window = p->win;
    ev.xclient.message_type = wm_state;
    ev.xclient.format = 32;
    ev.xclient.data.l[0] = host->fullscreen ? 1 : 0;
    ev.xclient.data.l[1] = fullscreen;
    ev.xclient.data.l[2] = 0;
    ev.xclient.data.l[3] = 1;
    XSendEvent(p->dpy, DefaultRootWindow(p->dpy), False,
               SubstructureRedirectMask | SubstructureNotifyMask, &ev);
    XFlush(p->dpy);
}

/* ---- Native clipboard & file dialogs (Linux/X11, via GTK 3) -----------------
 * Raw X11 has no clipboard or file-dialog API; GTK provides portable ones that
 * interoperate with the rest of the desktop. GTK is initialized lazily (and only
 * once) on first use, so headless/server builds that never touch these paths
 * are unaffected. If GTK can't initialize (e.g. no display) the calls degrade
 * gracefully to no-op / NULL. */

#include <gtk/gtk.h>

static gboolean x11_gtk_inited = FALSE;

static gboolean x11_ensure_gtk(void)
{
    if (!x11_gtk_inited) {
        int argc = 0;
        char **argv = NULL;
        x11_gtk_inited = gtk_init_check(&argc, &argv);
    }
    return x11_gtk_inited;
}

/* Native modal dialogs (P-Native P1). GTK dialogs are always window-modal, so
 * the sheet style degrades to a modal box. Buttons are mapped to response ids
 * 100+i so the clicked button's index is (response - 100). */
int ui3_plat_dialog(ui3_host *host, int kind, int style, const char *title,
                    const char *message, const char *buttons)
{
    (void)style;
    if (!host || !host->plat) return -1;
    if (!x11_ensure_gtk()) return -1;

    GtkMessageType mt = GTK_MESSAGE_INFO;
    if (kind == 1) mt = GTK_MESSAGE_WARNING;
    else if (kind == 2) mt = GTK_MESSAGE_ERROR;
    else if (kind == 3) mt = GTK_MESSAGE_QUESTION;

    GtkWidget *dlg = gtk_message_dialog_new(
        NULL,
        GTK_DIALOG_MODAL | GTK_DIALOG_DESTROY_WITH_PARENT,
        mt, GTK_BUTTONS_NONE, "%s", message ? message : "");
    gtk_window_set_title(GTK_WINDOW(dlg), title ? title : "");

    char buf[512];
    g_strlcpy(buf, buttons ? buttons : "OK", sizeof(buf));
    char **parts = g_strsplit(buf, "|", -1);
    for (int i = 0; parts[i]; i++) {
        gtk_dialog_add_button(GTK_DIALOG(dlg), parts[i], 100 + i);
    }

    int resp = gtk_dialog_run(GTK_DIALOG(dlg));
    gtk_widget_destroy(dlg);
    while (gtk_events_pending()) gtk_main_iteration();
    g_strfreev(parts);

    if (resp >= 100) return resp - 100;
    return -1;
}

/* ---- Native notification / toast (P-Native P1) ---- */
/* GTK has no built-in toast; shell out to the ubiquitous `notify-send`
 * (dependency-free, present on every desktop with a notification daemon).
 * Best-effort: a missing notify-send is ignored. */
int ui3_plat_notify(ui3_host *host, const char *title, const char *body)
{
    (void)host;
    if (!x11_ensure_gtk()) return -1;
    char safe[2048];
    snprintf(safe, sizeof(safe), "notify-send %.1900s %.1900s",
             title ? title : "", body ? body : "");
    GError *err = NULL;
    if (!g_spawn_command_line_async(safe, &err)) {
        if (err) g_error_free(err);
        return -1;
    }
    return 0;
}

/* ---- Native menu bar (P-Native P1) ---- */
/* The raw-X11 backend has no GtkWidget to host a GtkMenuBar, and raw X11 has no
 * menu-bar concept (the desktop shell provides app menus via GTK/GApplication).
 * Best-effort no-op: the call is recorded by common.c so headless automation
 * still works; a real X11 menu bar waits on a GTK widget backend. */
void ui3_plat_set_menu(ui3_host *host, const char *menu)
{
    (void)host; (void)menu;
}

/* The raw-X11 backend has no GtkWidget, so ATK/AT-SPI registration is
 * impossible — ATKObject requires a GTK widget to attach to.  The tree is
 * still serialised to text on host->last_a11y_tree (common.c) for headless
 * / automation inspection via ui3_host_last_a11y().  A proper ATK bridge
 * needs a GTK-based canvas widget; deferred until such a backend exists. */
void ui3_plat_accessibility(ui3_host *host, ui3_a11y_node *root)
{
    (void)host; (void)root;
}

void ui3_host_set_clipboard_text(void *host, const char *text)
{
    if (!text) return;
    if (host && ((ui3_host *)host)->headless) {
        free(((ui3_host *)host)->last_clip_text);
        ((ui3_host *)host)->last_clip_text = strdup(text);
    }
    if (!x11_ensure_gtk()) return;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    gtk_clipboard_set_text(cb, text, -1);
}

char *ui3_host_get_clipboard_text(void *host)
{
    (void)host;
    if (!x11_ensure_gtk()) return NULL;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    gchar *s = gtk_clipboard_wait_for_text(cb);
    if (!s) return NULL;
    static char *g = NULL;
    free(g);
    g = strdup(s);
    g_free(s);
    return g;
}

/* ---- Clipboard multi-format (P-Native P2) ---- */

/* ---- Clipboard multi-format (P-Native P2) ----
 *
 * GTK3 multi-format clipboard: register target atoms + get-callbacks.
 * The callbacks are invoked by GTK when another app requests data of a
 * given atom.  We serve data from per-atom storage on the host struct.
 *
 * set_html / set_image: use gtk_clipboard_set_with_data with a callback
 * that provides the data on demand and a cleanup callback that frees it.
 *
 * set_text / set_uris: already use gtk_clipboard_set_text (works fine).
 *
 * formats: query the clipboard's target list synchronously. */

static void
x11_clip_get_html(GtkClipboard *cb, GtkSelectionData *sd,
                  guint info, gpointer data)
{
    (void)cb; (void)info;
    char *html = data;
    if (!html) return;
    gtk_selection_data_set(sd,
        gtk_selection_data_get_target(sd), 8,
        (const guint8 *)html, strlen(html));
}

static void
x11_clip_cleanup_html(gpointer data)
{
    free(data);
}

static void
x11_clip_get_image(GtkClipboard *cb, GtkSelectionData *sd,
                   guint info, gpointer data)
{
    (void)cb; (void)info;
    x11_plat *p = (x11_plat *)data;
    if (!p || !p->host) return;
    const void *src = p->host->last_clip_image;
    if (!src) return;
    gtk_selection_data_set(sd,
        gdk_atom_intern_static_string("image/png"), 8,
        (const guint8 *)src, p->host->last_clip_image_len);
}

static void
x11_clip_cleanup_image(gpointer data)
{
    x11_plat *p = (x11_plat *)data;
    if (p && p->host) {
        free(p->host->last_clip_image);
        p->host->last_clip_image = NULL;
        p->host->last_clip_image_len = 0;
    }
}

void ui3_plat_clipboard_set_image(ui3_host *host, const void *data, int len)
{
    if (host && host->headless) {
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
    if (!x11_ensure_gtk()) return;
    if (!data || len <= 0) return;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    x11_plat *p = host ? host->plat : NULL;
    if (!p) return;
    free(p->host->last_clip_image);
    p->host->last_clip_image = malloc(len);
    if (p->host->last_clip_image)
        memcpy(p->host->last_clip_image, data, len);
    p->host->last_clip_image_len = len;
    GdkAtom atom = gdk_atom_intern_static_string("image/png");
    gtk_clipboard_set_with_data(cb, &atom, 1,
                                x11_clip_get_image,
                                x11_clip_cleanup_image,
                                p);
}

const void *ui3_plat_clipboard_get_image(ui3_host *host, int *out_len)
{
    (void)host;
    if (out_len) *out_len = 0;
    if (!x11_ensure_gtk()) return NULL;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    GdkAtom atom = gdk_atom_intern_static_string("image/png");
    GtkSelectionData *sd = gtk_clipboard_wait_for_contents(cb, atom);
    if (!sd || sd->length <= 0) { gtk_selection_data_free(sd); return NULL; }
    void *data = malloc(sd->length);
    if (data) {
        memcpy(data, sd->data, sd->length);
        if (out_len) *out_len = (int)sd->length;
    }
    gtk_selection_data_free(sd);
    return data;
}

void ui3_plat_clipboard_set_uris(ui3_host *host, const char *uris)
{
    if (host && host->headless) {
        free(host->last_clip_uris);
        host->last_clip_uris = uris ? strdup(uris) : NULL;
        return;
    }
    if (!uris) return;
    if (!x11_ensure_gtk()) return;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    gtk_clipboard_set_text(cb, uris, -1);
}

const char *ui3_plat_clipboard_get_uris(ui3_host *host)
{
    (void)host;
    if (!x11_ensure_gtk()) return "";
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    gchar *s = gtk_clipboard_wait_for_text(cb);
    if (!s) return "";
    static char *g = NULL;
    free(g); g = strdup(s);
    g_free(s);
    return g;
}

void ui3_plat_clipboard_set_html(ui3_host *host, const char *html,
                                 const char *base_url)
{
    (void)base_url;
    if (host && host->headless) {
        free(host->last_clip_html);
        host->last_clip_html = html ? strdup(html) : NULL;
        return;
    }
    if (!html) return;
    if (!x11_ensure_gtk()) return;
    char *stored = strdup(html);
    if (!stored) return;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    GdkAtom atom = gdk_atom_intern_static_string("text/html");
    gtk_clipboard_set_with_data(cb, &atom, 1,
                                x11_clip_get_html,
                                x11_clip_cleanup_html,
                                stored);
}

const char *ui3_plat_clipboard_get_html(ui3_host *host)
{
    (void)host;
    if (!x11_ensure_gtk()) return "";
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    GdkAtom atom = gdk_atom_intern_static_string("text/html");
    GtkSelectionData *sd = gtk_clipboard_wait_for_contents(cb, atom);
    if (!sd || sd->length <= 0) { gtk_selection_data_free(sd); return ""; }
    static char *g = NULL;
    free(g);
    g = malloc(sd->length + 1);
    if (g) { memcpy(g, sd->data, sd->length); g[sd->length] = '\0'; }
    gtk_selection_data_free(sd);
    return g;
}

int ui3_plat_clipboard_formats(ui3_host *host)
{
    (void)host;
    if (!x11_ensure_gtk()) return 0;
    int m = 0;
    GtkClipboard *cb = gtk_clipboard_get(GDK_SELECTION_CLIPBOARD);
    GdkAtomList *targets = gtk_clipboard_wait_for_targets(cb);
    if (!targets) return 0;
    for (GList *l = targets->atoms; l; l = l->next) {
        const gchar *name = gdk_atom_name((GdkAtom)l->data);
        if (!name) continue;
        if (g_strcmp0(name, "UTF8_STRING") == 0 ||
            g_strcmp0(name, "text/plain;charset=UTF-8") == 0 ||
            g_strcmp0(name, "TEXT") == 0 ||
            g_strcmp0(name, "STRING") == 0)
            m |= UI3_CLIP_TEXT;
        else if (g_strcmp0(name, "image/png") == 0)
            m |= UI3_CLIP_IMAGE;
        else if (g_strcmp0(name, "text/uri-list") == 0 ||
                 g_strcmp0(name, "URI_LIST") == 0)
            m |= UI3_CLIP_FILES;
        else if (g_strcmp0(name, "text/html") == 0)
            m |= UI3_CLIP_HTML;
    }
    gdk_atom_list_free(targets);
    return m;
}

char *ui3_host_open_file(void *host, const char *filters)
{
    (void)host;
    if (!x11_ensure_gtk()) return NULL;
    GtkWidget *dlg = gtk_file_chooser_dialog_new(
        "Open File", NULL, GTK_FILE_CHOOSER_ACTION_OPEN,
        "_Open", GTK_RESPONSE_ACCEPT, "_Cancel", GTK_RESPONSE_CANCEL, NULL);
    ui3_filter_group groups[8];
    int ng = ui3_parse_filters(filters, groups, 8);
    if (ng > 0) {
        for (int i = 0; i < ng; i++) {
            GtkFileFilter *f = gtk_file_filter_new();
            gtk_file_filter_set_name(f, groups[i].label);
            for (int j = 0; j < groups[i].nexts; j++) {
                char pat[32];
                snprintf(pat, sizeof(pat), "*.%s", groups[i].exts[j]);
                gtk_file_filter_add_pattern(f, pat);
            }
            gtk_file_chooser_add_filter(GTK_FILE_CHOOSER(dlg), f);
        }
        /* "All Files" fallback so users can bypass the filter. */
        GtkFileFilter *all = gtk_file_filter_new();
        gtk_file_filter_set_name(all, "All Files");
        gtk_file_filter_add_pattern(all, "*");
        gtk_file_chooser_add_filter(GTK_FILE_CHOOSER(dlg), all);
    }
    char *result = NULL;
    if (gtk_dialog_run(GTK_DIALOG(dlg)) == GTK_RESPONSE_ACCEPT) {
        char *fn = gtk_file_chooser_get_filename(GTK_FILE_CHOOSER(dlg));
        if (fn) { result = strdup(fn); g_free(fn); }
    }
    gtk_widget_destroy(dlg);
    while (gtk_events_pending()) gtk_main_iteration();
    return result;
}

char *ui3_host_save_file(void *host, const char *defext)
{
    (void)host;
    if (!x11_ensure_gtk()) return NULL;
    GtkWidget *dlg = gtk_file_chooser_dialog_new(
        "Save File", NULL, GTK_FILE_CHOOSER_ACTION_SAVE,
        "_Save", GTK_RESPONSE_ACCEPT, "_Cancel", GTK_RESPONSE_CANCEL, NULL);
    gtk_file_chooser_set_do_overwrite_confirmation(GTK_FILE_CHOOSER(dlg), TRUE);
    if (defext && *defext) {
        GtkFileFilter *f = gtk_file_filter_new();
        char pat[32], name[64];
        snprintf(pat, sizeof(pat), "*.%s", defext);
        snprintf(name, sizeof(name), "*.%s", defext);
        gtk_file_filter_set_name(f, name);
        gtk_file_filter_add_pattern(f, pat);
        gtk_file_chooser_add_filter(GTK_FILE_CHOOSER(dlg), f);
        gtk_file_chooser_set_filter(GTK_FILE_CHOOSER(dlg), f);
        char defname[64];
        snprintf(defname, sizeof(defname), "untitled.%s", defext);
        gtk_file_chooser_set_current_name(GTK_FILE_CHOOSER(dlg), defname);
    }
    char *result = NULL;
    if (gtk_dialog_run(GTK_DIALOG(dlg)) == GTK_RESPONSE_ACCEPT) {
        char *fn = gtk_file_chooser_get_filename(GTK_FILE_CHOOSER(dlg));
        if (fn) { result = strdup(fn); g_free(fn); }
    }
    gtk_widget_destroy(dlg);
    while (gtk_events_pending()) gtk_main_iteration();
    return result;
}
