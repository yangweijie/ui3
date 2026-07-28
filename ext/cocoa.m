#import "internal.h"
#import <Cocoa/Cocoa.h>
#import <cairo/cairo.h>
#import <cairo/cairo-quartz.h>

typedef struct {
    void *win;
    void *view;
    void *delegate;
} cocoa_plat;

@interface Ui3Delegate : NSObject <NSWindowDelegate>
@property ui3_host *host;
@end

@implementation Ui3Delegate
- (void)windowWillClose:(NSNotification *)n
{
    if (self.host) self.host->running = 0;
    /* Break out of [NSApp run]. -stop only takes effect after the current
     * event is drained, so post a dummy event to flush it immediately. */
    [NSApp stop:nil];
    NSEvent *e = [NSEvent otherEventWithType:NSEventTypeApplicationDefined
                                     location:NSZeroPoint
                                modifierFlags:0
                                    timestamp:0
                                 windowNumber:0
                                      context:nil
                                      subtype:0
                                        data1:0
                                        data2:0];
    [NSApp postEvent:e atStart:YES];
}
@end

/* Paint one frame into the given CGContext (used by both drawRect and
 * request_redraw so a single present() reliably shows a frame). */
static void cocoa_paint(ui3_host *host, CGContextRef cg)
{
    double scale = host->scale > 0 ? host->scale : 1.0;
    int w = (int)(host->width * scale);
    int h = (int)(host->height * scale);
    if (w <= 0 || h <= 0) return;
    cairo_surface_t *surface = cairo_quartz_surface_create_for_cg_context(cg, w, h);
    if (!surface) return;
    cairo_t *cr = cairo_create(surface);
    cairo_scale(cr, scale, scale); /* draw in CSS px */
    host->draw_cb(host->draw_ctx, host, cr);
    cairo_destroy(cr);
    cairo_surface_destroy(surface);
}

@interface Ui3View : NSView
@property ui3_host *host;
@end

@implementation Ui3View

- (BOOL)isFlipped { return YES; } /* top-left origin, matches our layout */

- (void)drawRect:(NSRect)dirty
{
    [super drawRect:dirty];
    ui3_host *host = self.host;
    if (!host || !host->draw_cb) return;
    CGContextRef cg = [[NSGraphicsContext currentContext] CGContext];
    if (!cg) return;
    cocoa_paint(host, cg);
}

- (void)forwardEvent:(NSEvent *)e down:(int)down
{
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    host->event_cb(host->event_ctx,
                   down ? UI3_EVENT_POINTER_DOWN : UI3_EVENT_POINTER_UP,
                   p.x, p.y, (double)e.buttonNumber, NULL);
}

- (void)mouseDown:(NSEvent *)e { [self forwardEvent:e down:1]; }
- (void)mouseUp:(NSEvent *)e   { [self forwardEvent:e down:0]; }
- (void)mouseMoved:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    host->event_cb(host->event_ctx, UI3_EVENT_POINTER_MOVE, p.x, p.y, 0, NULL);
}

@end

/* Key handling lives at the WINDOW level, not the content view: a custom
 * NSView only receives keyDown: once it is the first responder, which is
 * unreliable. Routing through the window's keyDown: (and performKeyEquivalent:)
 * guarantees keystrokes always reach the PHP onKey router regardless of first
 * responder state. */
@interface Ui3Window : NSWindow
@property ui3_host *host;
- (void)routeKey:(NSEvent *)e;
@end

@implementation Ui3Window

- (void)routeKey:(NSEvent *)e
{
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    int shift = (e.modifierFlags & NSEventModifierFlagShift) ? 1 : 0;
    char *text = ui3_key_text((int)e.keyCode, shift, [e.characters UTF8String]);
    if (!text) return;
    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, 0, text);
    free(text);
}

- (void)keyDown:(NSEvent *)e { [self routeKey:e]; }
- (BOOL)performKeyEquivalent:(NSEvent *)e
{
    /* Claim only keys we actually route (Tab/arrows/Enter/Backspace/printable);
     * let genuine app key-equivalents (Cmd+Q, Cmd+W, ...) fall through to the
     * system so they keep working. */
    int shift = (e.modifierFlags & NSEventModifierFlagShift) ? 1 : 0;
    char *text = ui3_key_text((int)e.keyCode, shift, [e.characters UTF8String]);
    if (text) { free(text); [self routeKey:e]; return YES; }
    return [super performKeyEquivalent:e];
}

@end

int ui3_plat_create_window(ui3_host *host, const char *title)
{
    @autoreleasepool {
        NSApplication *app = [NSApplication sharedApplication];
        [app setActivationPolicy:NSApplicationActivationPolicyRegular];

        NSRect rect = NSMakeRect(0, 0, host->width, host->height);
        Ui3Window *win = [[Ui3Window alloc]
            initWithContentRect:rect
                      styleMask:(NSWindowStyleMaskTitled | NSWindowStyleMaskClosable |
                                 NSWindowStyleMaskResizable)
                        backing:NSBackingStoreBuffered
                          defer:NO];
        win.host = host;
        [win setTitle:[NSString stringWithUTF8String:title ? title : "App"]];
        [win center]; /* open centered on the main screen */

        Ui3View *view = [[Ui3View alloc] initWithFrame:rect];
        view.host = host;
        win.contentView = view;

        Ui3Delegate *delegate = [[Ui3Delegate alloc] init];
        delegate.host = host;
        win.delegate = delegate;

        [win makeKeyAndOrderFront:nil];
        [app activateIgnoringOtherApps:YES];

        cocoa_plat *p = malloc(sizeof(*p));
        p->win = (__bridge_retained void *)win;
        p->view = (__bridge_retained void *)view;
        p->delegate = (__bridge_retained void *)delegate;
        host->plat = p;
        host->scale = win.backingScaleFactor > 0 ? win.backingScaleFactor : 1.0;
    }
    return 0;
}

#pragma clang diagnostic push
#pragma clang diagnostic ignored "-Wdeprecated-declarations"
void ui3_plat_request_redraw(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3View *view = (__bridge Ui3View *)(p->view);
    if (!view) return;
    [view lockFocus];
    CGContextRef cg = [[NSGraphicsContext currentContext] CGContext];
    if (cg) cocoa_paint(host, cg);
    [view unlockFocus];
}
#pragma clang diagnostic pop

/* Verify/automate the real key path: build a genuine NSEvent and hand it to the
 * window's keyDown:, exercising the exact routeKey/event_cb chain that physical
 * typing uses (so automation can test it without a human). */
void ui3_plat_post_key(ui3_host *host, int keycode, int shift, const char *chars)
{
    cocoa_plat *p = host->plat;
    if (!p) return;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    NSString *s = [NSString stringWithUTF8String:chars ? chars : ""];
    NSEvent *e = [NSEvent keyEventWithType:NSEventTypeKeyDown
                                    location:NSZeroPoint
                               modifierFlags:shift ? NSEventModifierFlagShift : 0
                                   timestamp:0
                                windowNumber:win.windowNumber
                                     context:nil
                                     characters:s
                    charactersIgnoringModifiers:s
                                  isARepeat:NO
                                    keyCode:keycode];
    if (e) [win keyDown:e];
}

int ui3_plat_step(ui3_host *host)
{
    (void)host;
    @autoreleasepool {
        NSEvent *e = [NSApp nextEventMatchingMask:NSEventMaskAny
                                        untilDate:[NSDate distantPast]
                                           inMode:NSDefaultRunLoopMode
                                          dequeue:YES];
        if (e) [NSApp sendEvent:e];
    }
    return host->running;
}

void ui3_plat_run(ui3_host *host)
{
    (void)host;
    @autoreleasepool { [NSApp run]; }
}

void ui3_plat_destroy(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    NSWindow *win = (__bridge_transfer NSWindow *)(p->win);
    (void)(__bridge_transfer NSView *)(p->view);
    [win close];
    free(p);
    host->plat = NULL;
}
