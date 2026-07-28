/* x11 backend: real X11 window + Cairo Xlib surface.
 * Keyboard events are translated through the SAME ui3_key_text() the Cocoa
 * and headless paths use, so the canonical key text (Tab / Shift+Tab / arrows
 * / Return / Backspace / printable) is identical across every platform. */
#include "internal.h"
#include <X11/Xlib.h>
#include <X11/Xutil.h>
#include <X11/keysym.h>
#include <cairo/cairo.h>
#include <cairo/cairo-xlib.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    ui3_host *host;
    Display *dpy;
    Window win;
    cairo_surface_t *surface;
    Atom wm_delete;
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
        default:              return 0;
    }
}

static void x11_key(x11_plat *p, XKeyEvent *ke)
{
    ui3_host *host = p->host;
    if (!host->event_cb) return;
    int shift = (ke->state & ShiftMask) ? 1 : 0;

    KeySym sym = XLookupKeysym(ke, shift ? 1 : 0);
    int id = x11_key_id(sym);

    char buf[32] = "";
    if (id == 0) {
        /* printable: XLookupString applies Shift for us (e.g. 'A'). */
        int n = XLookupString(ke, buf, sizeof(buf) - 1, NULL, NULL);
        if (n > 0) buf[n] = '\0'; else buf[0] = '\0';
    }

    char *text = ui3_key_text(id, shift, buf[0] ? buf : "");
    if (text) {
        host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, 0, text);
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
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_DOWN,
                               (double)ev->xbutton.x, (double)ev->xbutton.y, 0, NULL);
            break;
        case ButtonRelease:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_UP,
                               (double)ev->xbutton.x, (double)ev->xbutton.y, 0, NULL);
            break;
        case MotionNotify:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_MOVE,
                               (double)ev->xbutton.x, (double)ev->xbutton.y, 0, NULL);
            break;
        case ClientMessage:
            if ((Atom)ev->xclient.data.l[0] == p->wm_delete) host->running = 0;
            break;
        default:
            break;
    }
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
    p->host = host;
    p->dpy = dpy;
    p->win = win;
    p->surface = cairo_xlib_surface_create(dpy, win,
                                           DefaultVisual(dpy, screen),
                                           host->width, host->height);
    p->wm_delete = XInternAtom(dpy, "WM_DELETE_WINDOW", False);
    XSetWMProtocols(dpy, win, &p->wm_delete, 1);

    host->plat = p;
    host->scale = 1.0;
    XMapWindow(dpy, win);
    XFlush(dpy);
    return 0;
}

void ui3_plat_request_redraw(ui3_host *host)
{
    x11_plat *p = host->plat;
    if (!p) return;
    x11_draw(p);   /* paint synchronously so a single present() shows the frame */
}

void ui3_plat_post_key(ui3_host *host, int keycode, int shift, const char *chars)
{
    if (!host || !host->event_cb) return;
    char *t = ui3_key_text(keycode, shift, chars);
    if (!t) return;
    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, 0, t);
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
        XEvent ev;
        XNextEvent(p->dpy, &ev);   /* blocking */
        x11_dispatch(p, &ev);
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
