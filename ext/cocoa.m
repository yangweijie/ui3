#import "internal.h"
#import <Cocoa/Cocoa.h>
#import <UniformTypeIdentifiers/UniformTypeIdentifiers.h>
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
                   p.x, p.y, (double)((int)e.buttonNumber + 1), NULL);
}

- (void)mouseDown:(NSEvent *)e { [self forwardEvent:e down:1]; }
- (void)mouseUp:(NSEvent *)e   { [self forwardEvent:e down:0]; }
- (void)rightMouseDown:(NSEvent *)e { [self forwardEvent:e down:1]; }
- (void)rightMouseUp:(NSEvent *)e   { [self forwardEvent:e down:0]; }
- (void)forwardMove:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    host->event_cb(host->event_ctx, UI3_EVENT_POINTER_MOVE, p.x, p.y, 0, NULL);
}
/* NB: while a mouse button is held, Cocoa sends mouseDragged:, NOT mouseMoved:.
 * Without this the thumb can't be dragged (no MOVE events arrive mid-drag). */
- (void)mouseMoved:(NSEvent *)e  { [self forwardMove:e]; }
- (void)mouseDragged:(NSEvent *)e { [self forwardMove:e]; }

- (void)scrollWheel:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    // data > 0 == scroll down (viewport offset increases). Cocoa reports a
    // downward physical scroll as a negative deltaY, so negate.
    host->event_cb(host->event_ctx, UI3_EVENT_WHEEL, p.x, p.y, -[e scrollingDeltaY], NULL);
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
    int ctrl  = (e.modifierFlags & NSEventModifierFlagControl) ? 1 : 0;
    const char *chars = [e.characters UTF8String];
    /* Ctrl combos: -characters carries a control char (e.g. "\x01" for Ctrl+A);
     * use the unmodified base so we emit "Ctrl+a" not "Ctrl+\x01". */
    if (ctrl && chars && chars[0] && (unsigned char)chars[0] < 0x20) {
        chars = [e.charactersIgnoringModifiers UTF8String];
    }
    char *text = ui3_key_text((int)e.keyCode, shift, chars);
    if (!text) return;
    /* Prefix printable keys with "Ctrl+" so PHP can bind shortcuts (P0.1). */
    if (ctrl && text[0] && (unsigned char)text[0] >= 0x20 && text[0] != '\t') {
        size_t L = strlen(text);
        char *ct = (char *)malloc(L + 6);
        if (ct) {
            memcpy(ct, "Ctrl+", 5);
            memcpy(ct + 5, text, L + 1);
            free(text);
            text = ct;
        }
    }
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
        [win setAcceptsMouseMovedEvents:YES]; /* let hover reveal the scrollbar */
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
/* ASYNC: schedule a redraw via the OS compositor. This must NOT paint
 * synchronously, otherwise a draw callback that requests a redraw (e.g. an
 * animation keeps the frame loop alive) re-enters paint() and overflows the
 * stack. The OS calls drawRect on its own schedule, which is the proper
 * vsync-aligned frame loop. */
void ui3_plat_request_redraw(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3View *view = (__bridge Ui3View *)(p->view);
    if (!view) return;
    [view setNeedsDisplay:YES];
}

/* SYNC: paint one frame immediately into the current graphics context. Used by
 * ui3_host_present() for the initial frame and explicit single-shot redraws. */
void ui3_plat_present(ui3_host *host)
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

/* ---- System clipboard (P0.2) ---- */
void ui3_host_set_clipboard_text(ui3_host *host, const char *text)
{
    (void)host;
    if (!text) return;
    @autoreleasepool {
        NSPasteboard *pb = [NSPasteboard generalPasteboard];
        [pb clearContents];
        [pb setString:[NSString stringWithUTF8String:text] forType:NSPasteboardTypeString];
    }
}

char *ui3_host_get_clipboard_text(ui3_host *host)
{
    (void)host;
    static char *g = NULL;
    @autoreleasepool {
        NSPasteboard *pb = [NSPasteboard generalPasteboard];
        NSString *s = [pb stringForType:NSPasteboardTypeString];
        if (!s) { free(g); g = NULL; return NULL; }
        const char *u = [s UTF8String];
        size_t L = strlen(u);
        char *n = (char *)malloc(L + 1);
        if (!n) { free(g); g = NULL; return NULL; }
        memcpy(n, u, L + 1);
        free(g); g = n;
    }
    return g;
}

/* ---- Modal file dialogs (P0.3) ---- */
char *ui3_host_open_file(ui3_host *host, const char *filters)
{
    (void)host;
    static char *g = NULL;
    @autoreleasepool {
        NSOpenPanel *panel = [NSOpenPanel openPanel];
        [panel setCanChooseFiles:YES];
        [panel setCanChooseDirectories:NO];
        [panel setAllowsMultipleSelection:NO];
        if (filters && filters[0]) {
            ui3_filter_group groups[8];
            int ng = ui3_parse_filters(filters, groups, 8);
            if (ng > 0) {
                NSMutableArray *uts = [NSMutableArray array];
                for (int i = 0; i < ng; i++) {
                    for (int j = 0; j < groups[i].nexts; j++) {
                        UTType *ut = [UTType typeWithFilenameExtension:[NSString stringWithUTF8String:groups[i].exts[j]]];
                        if (ut && ![uts containsObject:ut]) [uts addObject:ut];
                    }
                }
                if (uts.count) { panel.allowedContentTypes = uts; }
            }
        }
        if ([panel runModal] == NSModalResponseOK) {
            NSURL *url = panel.URLs.firstObject;
            if (url) {
                const char *u = [[url path] UTF8String];
                free(g); g = strdup(u);
                return g;
            }
        }
    }
    free(g); g = NULL;
    return NULL;
}

char *ui3_host_save_file(ui3_host *host, const char *defext)
{
    (void)host;
    static char *g = NULL;
    @autoreleasepool {
        NSSavePanel *panel = [NSSavePanel savePanel];
        if (defext && defext[0]) {
            UTType *ut = [UTType typeWithFilenameExtension:[NSString stringWithUTF8String:defext]];
            if (ut) {
                [panel setAllowedContentTypes:@[ut]];
            }
            [panel setNameFieldStringValue:[NSString stringWithFormat:@"untitled.%s", defext]];
        }
        if ([panel runModal] == NSModalResponseOK) {
            NSURL *url = panel.URL;
            if (url) {
                const char *u = [[url path] UTF8String];
                free(g); g = strdup(u);
                return g;
            }
        }
    }
    free(g); g = NULL;
    return NULL;
}
