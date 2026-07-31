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
- (BOOL)windowShouldClose:(NSNotification *)n
{
    if (!self.host) return YES;
    if (self.host->close_cb) {
        int accept = 1;
        self.host->close_cb(self.host->close_ctx, &accept);
        if (!accept) return NO;
    }
    return YES;
}

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

@interface Ui3A11yElement : NSAccessibilityElement
- (instancetype)initWithNode:(const ui3_a11y_node *)node;
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

    /* Pan momentum (P-Native P1): when a trackpad two-finger pan gesture is
     * active (phase != None), also deliver a GESTURE event with gtype=3 (pan).
     * Text = "dx,dy" for 2D panning. This fires alongside the WHEEL event so
     * scroll containers still work, while gesture targets get pan data. */
    if ([e phase] != NSEventPhaseNone || [e momentumPhase] != NSEventPhaseNone) {
        char buf[64];
        snprintf(buf, sizeof(buf), "%f,%f", [e scrollingDeltaX], [e scrollingDeltaY]);
        host->event_cb(host->event_ctx, UI3_EVENT_GESTURE, p.x, p.y, 3, buf);
    }
}

/* Drag & drop (P-Native P1): deliver a DROP event with the payload — file
 * paths (newline-separated, dtype=1) when URLs are dropped, else the text
 * (dtype=0). */
- (NSDragOperation)draggingEntered:(id<NSDraggingInfo>)sender {
    return NSDragOperationCopy;
}
- (NSDragOperation)draggingUpdated:(id<NSDraggingInfo>)sender {
    return NSDragOperationCopy;
}
- (BOOL)performDragOperation:(id<NSDraggingInfo>)sender {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return NO;
    NSPoint p = [self convertPoint:[sender draggingLocation] fromView:nil];
    NSPasteboard *pb = [sender draggingPasteboard];
    NSString *payload = nil;
    int dtype = 0;
    NSArray<NSURL *> *urls = [pb readObjectsForClasses:@[[NSURL class]] options:nil];
    if (urls.count > 0) {
        NSMutableArray<NSString *> *paths = [NSMutableArray array];
        for (NSURL *u in urls) {
            if (u.isFileURL) [paths addObject:[u path]];
        }
        if (paths.count > 0) {
            payload = [paths componentsJoinedByString:@"\n"];
            dtype = 1;
        }
    }
    if (!payload) {
        NSString *s = [pb stringForType:NSPasteboardTypeString];
        if (s) { payload = s; dtype = 0; }
    }
    if (!payload) return NO;
    host->event_cb(host->event_ctx, UI3_EVENT_DROP, p.x, p.y, (double)dtype, [payload UTF8String]);
    return YES;
}

/* Trackpad gestures (P-Native P1): deliver GESTURE events — pinch (magnify),
 * rotate, swipe — at the gesture location. data = 0/1/2, text = magnitude/dir. */
- (void)magnifyWithEvent:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    char buf[32];
    snprintf(buf, sizeof(buf), "%f", [e magnification]);
    host->event_cb(host->event_ctx, UI3_EVENT_GESTURE, p.x, p.y, 0, buf);
}
- (void)rotateWithEvent:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    char buf[32];
    snprintf(buf, sizeof(buf), "%f", [e rotation]);
    host->event_cb(host->event_ctx, UI3_EVENT_GESTURE, p.x, p.y, 1, buf);
}
- (void)swipeWithEvent:(NSEvent *)e {
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSPoint p = [self convertPoint:[e locationInWindow] fromView:nil];
    const char *dir = "right";
    if (e.deltaX < -0.5) dir = "left";
    else if (e.deltaY > 0.5) dir = "up";
    else if (e.deltaY < -0.5) dir = "down";
    host->event_cb(host->event_ctx, UI3_EVENT_GESTURE, p.x, p.y, 2, dir);
}

- (NSArray<id<NSAccessibility>> *)accessibilityChildren
{
    ui3_host *host = self.host;
    if (!host || !host->plat_a11y) return @[];
    ui3_a11y_node *root = (ui3_a11y_node *)host->plat_a11y;
    NSMutableArray *children = [NSMutableArray array];
    for (int i = 0; i < root->child_count; i++) {
        Ui3A11yElement *el = [[Ui3A11yElement alloc] initWithNode:root->children[i]];
        [children addObject:el];
    }
    return children;
}
- (NSString *)accessibilityLabel
{
    ui3_host *host = self.host;
    if (!host || !host->plat_a11y) return nil;
    const char *l = ((ui3_a11y_node *)host->plat_a11y)->label;
    return l && l[0] ? [NSString stringWithUTF8String:l] : nil;
}
- (NSString *)accessibilityDescription
{
    ui3_host *host = self.host;
    if (!host || !host->plat_a11y) return nil;
    const char *d = ((ui3_a11y_node *)host->plat_a11y)->description;
    return d && d[0] ? [NSString stringWithUTF8String:d] : nil;
}

@end

@implementation Ui3A11yElement {
    const ui3_a11y_node *_node;
}
- (instancetype)initWithNode:(const ui3_a11y_node *)node
{
    self = [super init];
    if (self) { _node = node; }
    return self;
}
- (NSArray<id<NSAccessibility>> *)accessibilityChildren
{
    if (!_node || _node->child_count == 0) return @[];
    NSMutableArray *arr = [NSMutableArray arrayWithCapacity:_node->child_count];
    for (int i = 0; i < _node->child_count; i++) {
        Ui3A11yElement *el = [[Ui3A11yElement alloc] initWithNode:_node->children[i]];
        [arr addObject:el];
    }
    return arr;
}
- (NSString *)accessibilityLabel
{
    if (!_node || !_node->label || !_node->label[0]) return nil;
    return [NSString stringWithUTF8String:_node->label];
}
- (NSString *)accessibilityDescription
{
    if (!_node || !_node->description || !_node->description[0]) return nil;
    return [NSString stringWithUTF8String:_node->description];
}
- (NSRect)accessibilityFrame
{
    if (!_node) return NSZeroRect;
    return NSMakeRect(_node->x, _node->y, _node->w, _node->h);
}
- (id<NSAccessibility>)accessibilityParentElement { return nil; }
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

/* Menu bar target (P-Native P1): receives menu-item clicks and delivers a
 * UI3_EVENT_MENU carrying the item's onClick message. */
@interface Ui3MenuTarget : NSObject
@property ui3_host *host;
- (void)click:(NSMenuItem *)sender;
@end

@implementation Ui3MenuTarget
- (void)click:(NSMenuItem *)sender
{
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    NSString *msg = sender.representedObject;
    if (!msg || msg.length == 0) return;
    host->event_cb(host->event_ctx, UI3_EVENT_MENU, 0, 0, 0, [msg UTF8String]);
}
@end

@implementation Ui3Window

- (void)routeKey:(NSEvent *)e
{
    ui3_host *host = self.host;
    if (!host || !host->event_cb) return;
    int shift = (e.modifierFlags & NSEventModifierFlagShift)    ? UI3_MOD_SHIFT : 0;
    int ctrl  = (e.modifierFlags & NSEventModifierFlagControl)  ? UI3_MOD_CTRL  : 0;
    int alt   = (e.modifierFlags & NSEventModifierFlagOption)   ? UI3_MOD_ALT   : 0;
    int cmd   = (e.modifierFlags & NSEventModifierFlagCommand)  ? UI3_MOD_CMD   : 0;
    int modifiers = shift | ctrl | alt | cmd;
    const char *chars = [e.characters UTF8String];
    /* Ctrl combos: -characters carries a control char (e.g. "\x01" for Ctrl+A);
     * use the unmodified base so we emit "Ctrl+a" not "Ctrl+\x01". */
    if (ctrl && chars && chars[0] && (unsigned char)chars[0] < 0x20) {
        chars = [e.charactersIgnoringModifiers UTF8String];
    }
    char *text = ui3_key_text((int)e.keyCode, modifiers, chars);
    if (!text) return;
    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, text);
    free(text);
}

- (void)keyDown:(NSEvent *)e { [self routeKey:e]; }
- (BOOL)performKeyEquivalent:(NSEvent *)e
{
    /* Route Cmd+* combos to PHP so app shortcuts (Cmd+C/V/X/Z/A, …) are
     * capturable. Keep the system's reserved app/window shortcuts falling
     * through to macOS (quit/close/hide/minimize/switch/spotlight/cycle). */
    if ((e.modifierFlags & NSEventModifierFlagCommand) &&
        ((int)e.keyCode == 12 || (int)e.keyCode == 13 || (int)e.keyCode == 4 ||
         (int)e.keyCode == 46 || (int)e.keyCode == 48 || (int)e.keyCode == 49 ||
         (int)e.keyCode == 50)) {
        return [super performKeyEquivalent:e];
    }
    [self routeKey:e];
    return YES;
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
        [view registerForDraggedTypes:@[NSPasteboardTypeFileURL, NSPasteboardTypeString]];
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
void ui3_plat_post_key(ui3_host *host, int keycode, int modifiers, const char *chars)
{
    cocoa_plat *p = host->plat;
    if (!p) return;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    NSString *s = [NSString stringWithUTF8String:chars ? chars : ""];
    NSEventModifierFlags mf = 0;
    if (modifiers & UI3_MOD_SHIFT) mf |= NSEventModifierFlagShift;
    if (modifiers & UI3_MOD_CTRL)  mf |= NSEventModifierFlagControl;
    if (modifiers & UI3_MOD_ALT)   mf |= NSEventModifierFlagOption;
    if (modifiers & UI3_MOD_CMD)   mf |= NSEventModifierFlagCommand;
    NSEvent *e = [NSEvent keyEventWithType:NSEventTypeKeyDown
                                    location:NSZeroPoint
                               modifierFlags:mf
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

/* ---- Window management (P1) ---- */
void ui3_plat_set_title(ui3_host *host, const char *title)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    [win setTitle:[NSString stringWithUTF8String:title ? title : "App"]];
}

void ui3_plat_resize(ui3_host *host, int w, int h)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    [win setContentSize:NSMakeSize((CGFloat)w, (CGFloat)h)];
}

void ui3_plat_minimize(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    [win miniaturize:nil];
}

void ui3_plat_close(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    [win close];
}

void ui3_plat_move(ui3_host *host, int x, int y)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    NSRect frame = [win frame];
    frame.origin.x = (CGFloat)x;
    frame.origin.y = (CGFloat)y;
    [win setFrameOrigin:frame.origin];
}

void ui3_plat_fullscreen(ui3_host *host)
{
    if (!host || !host->plat) return;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return;
    [win toggleFullScreen:nil];
}

/* ---- Native modal dialogs (P-Native P1) ---- */
int ui3_plat_dialog(ui3_host *host, int kind, int style, const char *title,
                    const char *message, const char *buttons)
{
    if (!host || !host->plat) return -1;
    cocoa_plat *p = host->plat;
    Ui3Window *win = (__bridge Ui3Window *)(p->win);
    if (!win) return -1;

    __block NSInteger clicked = -1;
    void (^show)(void) = ^{
        NSAlert *alert = [[NSAlert alloc] init];
        NSAlertStyle st = NSAlertStyleInformational;
        if (kind == 1) st = NSAlertStyleWarning;
        else if (kind == 2) st = NSAlertStyleCritical;
        alert.alertStyle = st;
        alert.messageText = [NSString stringWithUTF8String:title ? title : ""];
        alert.informativeText = [NSString stringWithUTF8String:message ? message : ""];

        NSString *bstr = [NSString stringWithUTF8String:buttons ? buttons : "OK"];
        NSArray<NSString *> *labels = [bstr componentsSeparatedByString:@"|"];
        for (NSString *label in labels) {
            if (label.length == 0) continue;
            [alert addButtonWithTitle:label];
        }

        if (style == 1) {
            [alert beginSheetModalForWindow:win
                          completionHandler:^(NSModalResponse r) {
                              clicked = r - NSAlertFirstButtonReturn;
                          }];
            while (clicked < 0) {
                @autoreleasepool {
                    [[NSRunLoop currentRunLoop] runMode:NSDefaultRunLoopMode
                                             beforeDate:[NSDate dateWithTimeIntervalSinceNow:0.05]];
                }
            }
        } else {
            clicked = [alert runModal] - NSAlertFirstButtonReturn;
        }
    };

    if ([NSThread isMainThread]) show();
    else dispatch_sync(dispatch_get_main_queue(), show);
    return (int)clicked;
}

/* ---- Native notification / toast (P-Native P1) ---- */
int ui3_plat_notify(ui3_host *host, const char *title, const char *body)
{
    (void)host;
    @autoreleasepool {
        NSUserNotification *n = [[NSUserNotification alloc] init];
        n.title = [NSString stringWithUTF8String:title ? title : ""];
        n.informativeText = [NSString stringWithUTF8String:body ? body : ""];
        n.soundName = NSUserNotificationDefaultSoundName;
        [[NSUserNotificationCenter defaultUserNotificationCenter] deliverNotification:n];
    }
    return 0;
}

/* ---- Native menu bar (P-Native P1) ---- */
/* Parse the encoded menu text and build the application main menu. Each
 * top-level line is a menu title; tab-indented "\t<label>\t<onClick>\t<shortcut>"
 * lines are items ("\t-" = separator). Shortcuts use "Cmd+O" / "Ctrl+Shift+S"
 * syntax (last token = keyEquivalent, prefixes = modifier mask). */
void ui3_plat_set_menu(ui3_host *host, const char *menu)
{
    if (!host || !menu) return;
    Ui3MenuTarget *target = [[Ui3MenuTarget alloc] init];
    target.host = host;

    void (^build)(void) = ^{
        NSMenu *root = [[NSMenu alloc] init];
        NSMenu *current = nil;
        NSString *all = [NSString stringWithUTF8String:menu];
        for (NSString *line in [all componentsSeparatedByString:@"\n"]) {
            if ([line hasPrefix:@"\t"]) {
                if (!current) continue;
                NSArray<NSString *> *f = [[line substringFromIndex:1] componentsSeparatedByString:@"\t"];
                NSString *label = f.count > 0 ? f[0] : @"";
                if ([label isEqualToString:@"-"]) {
                    [current addItem:[NSMenuItem separatorItem]];
                    continue;
                }
                NSString *onClick = f.count > 1 ? f[1] : @"";
                NSString *shortcut = f.count > 2 ? f[2] : @"";
                NSString *key = @"";
                NSEventModifierFlags mask = 0;
                if (shortcut.length > 0) {
                    NSArray<NSString *> *parts = [shortcut componentsSeparatedByString:@"+"];
                    key = parts.lastObject;
                    for (NSUInteger i = 0; i + 1 < parts.count; i++) {
                        NSString *p = parts[i];
                        if ([p isEqualToString:@"Cmd"]) mask |= NSEventModifierFlagCommand;
                        else if ([p isEqualToString:@"Ctrl"]) mask |= NSEventModifierFlagControl;
                        else if ([p isEqualToString:@"Alt"]) mask |= NSEventModifierFlagOption;
                        else if ([p isEqualToString:@"Shift"]) mask |= NSEventModifierFlagShift;
                    }
                }
                NSMenuItem *item = [[NSMenuItem alloc] initWithTitle:label
                                                               action:@selector(click:)
                                                        keyEquivalent:key];
                item.target = target;
                item.representedObject = onClick;
                item.keyEquivalentModifierMask = mask;
                [current addItem:item];
            } else if (line.length > 0) {
                NSMenuItem *top = [[NSMenuItem alloc] initWithTitle:line action:NULL keyEquivalent:@""];
                NSMenu *m = [[NSMenu alloc] initWithTitle:line];
                top.submenu = m;
                [root addItem:top];
                current = m;
            }
        }
        [NSApp setMainMenu:root];
    };

    if ([NSThread isMainThread]) build();
    else dispatch_sync(dispatch_get_main_queue(), build);
}

/* ---- Accessibility tree (P-Native P1) ---- */
void ui3_plat_accessibility(ui3_host *host, ui3_a11y_node *root)
{
    (void)host;
    (void)root;
    /* Ui3View already reads host->plat_a11y via accessibilityChildren.
     * VoiceOver picks up changes automatically when a11y children change. */
}

/* ---- Clipboard multi-format (P-Native P2) ---- */

static const char * const ui3_png_type = "public.png";
static const char * const ui3_files_type = "NSFilenamesPboardType";
static const char * const ui3_html_type = "com.apple.html";

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
    @autoreleasepool {
        if (!data || len <= 0) return;
        NSPasteboard *pb = [NSPasteboard generalPasteboard];
        [pb setData:[NSData dataWithBytes:data length:len] forType:[NSString stringWithUTF8String:ui3_png_type]];
    }
}

const void *ui3_plat_clipboard_get_image(ui3_host *host, int *out_len)
{
    (void)host;
    if (out_len) *out_len = 0;
    NSPasteboard *pb = [NSPasteboard generalPasteboard];
    NSData *d = [pb dataForType:[NSString stringWithUTF8String:ui3_png_type]];
    if (!d || [d length] == 0) return NULL;
    if (out_len) *out_len = (int)[d length];
    return [d bytes];
}

void ui3_plat_clipboard_set_uris(ui3_host *host, const char *uris)
{
    if (host && host->headless) {
        free(host->last_clip_uris);
        host->last_clip_uris = uris ? strdup(uris) : NULL;
        return;
    }
    @autoreleasepool {
        NSMutableArray *arr = [NSMutableArray array];
        char *dup = uris ? strdup(uris) : NULL;
        if (!dup) return;
        for (char *line = strtok(dup, "\n"); line; line = strtok(NULL, "\n")) {
            NSString *url = [NSString stringWithUTF8String:line];
            if ([url length]) [arr addObject:[NSURL URLWithString:url]];
        }
        free(dup);
        NSPasteboard *pb = [NSPasteboard generalPasteboard];
        [pb setData:[NSKeyedArchiver archivedDataWithRootObject:arr] forType:[NSString stringWithUTF8String:ui3_files_type]];
    }
}

static char *ui3_memo_uris = NULL;

const char *ui3_plat_clipboard_get_uris(ui3_host *host)
{
    (void)host;
    free(ui3_memo_uris); ui3_memo_uris = NULL;
    NSPasteboard *pb = [NSPasteboard generalPasteboard];
    NSData *d = [pb dataForType:[NSString stringWithUTF8String:ui3_files_type]];
    if (!d) return "";
    NSArray *arr = [NSKeyedUnarchiver unarchivedObjectOfClass:[NSArray class] fromData:d error:nil];
    if (!arr || ![arr count]) return "";
    NSMutableString *m = [NSMutableString string];
    for (NSURL *u in arr) {
        if ([m length]) [m appendString:@"\n"];
        NSString *p = [u path];
        if (p) [m appendString:p];
    }
    ui3_memo_uris = strdup([m UTF8String]);
    return ui3_memo_uris;
}

static char *ui3_memo_html = NULL;

void ui3_plat_clipboard_set_html(ui3_host *host, const char *html, const char *base_url)
{
    if (host && host->headless) {
        free(host->last_clip_html);
        host->last_clip_html = html ? strdup(html) : NULL;
        return;
    }
    @autoreleasepool {
        if (!html || !html[0]) return;
        NSPasteboard *pb = [NSPasteboard generalPasteboard];
        NSData *d = [NSData dataWithBytes:html length:(int)strlen(html)];
        [pb setData:d forType:[NSString stringWithUTF8String:ui3_html_type]];
    }
}

const char *ui3_plat_clipboard_get_html(ui3_host *host)
{
    (void)host;
    free(ui3_memo_html); ui3_memo_html = NULL;
    NSPasteboard *pb = [NSPasteboard generalPasteboard];
    NSData *d = [pb dataForType:[NSString stringWithUTF8String:ui3_html_type]];
    if (!d || [d length] == 0) return "";
    ui3_memo_html = malloc([d length] + 1);
    if (ui3_memo_html) {
        memcpy(ui3_memo_html, [d bytes], [d length]);
        ui3_memo_html[[d length]] = '\0';
    }
    return ui3_memo_html;
}

int ui3_plat_clipboard_formats(ui3_host *host)
{
    (void)host;
    int m = 0;
    NSPasteboard *pb = [NSPasteboard generalPasteboard];
    NSArray *types = [pb types];
    for (NSString *t in types) {
        const char *ts = [t UTF8String];
        if ([t isEqualToString:NSPasteboardTypeString] || strcmp(ts, "public.utf8-plain-text") == 0)
            m |= UI3_CLIP_TEXT;
        if (strcmp(ts, ui3_png_type) == 0)
            m |= UI3_CLIP_IMAGE;
        if (strcmp(ts, ui3_files_type) == 0)
            m |= UI3_CLIP_FILES;
        if (strcmp(ts, ui3_html_type) == 0)
            m |= UI3_CLIP_HTML;
    }
    return m;
}

/* ---- System clipboard (P0.2) ---- */
void ui3_host_set_clipboard_text(ui3_host *host, const char *text)
{
    if (!text) return;
    if (host && host->headless) {
        free(host->last_clip_text);
        host->last_clip_text = strdup(text);
    }
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
