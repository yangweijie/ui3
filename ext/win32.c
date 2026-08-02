/* win32 backend: real Win32 window + Cairo Win32 surface.
 * Keyboard events are translated through the SAME ui3_key_text() the Cocoa
 * and headless paths use, so the canonical key text (Tab / Shift+Tab / arrows
 * / Return / Backspace / printable) is identical across every platform. */
#ifndef _WIN32_WINNT
#define _WIN32_WINNT 0x0601  /* Windows 7: WM_GESTURE / RegisterTouchWindow */
#endif
#include "internal.h"
#include <windows.h>
#include <windowsx.h>        /* GET_X_LPARAM */
#include <uiautomation.h>    /* IUIAutomationRegistrar */
#include <cairo/cairo.h>
#include <cairo/cairo-win32.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    ui3_host *host;
    HWND hwnd;
    /* Menu bar (P-Native P1): id -> onClick message mapping. */
    char **menu_msgs;
    int menu_count;
    /* UIA accessibility (P-Native P1). */
    BOOL com_inited;
    IUIAutomationRegistrar *uiaRegistrar;
    void *uiaCallback;          /* win32_uia_callback * — opaque to avoid fwd-decl ordering */
} win32_plat;

/* Win32 VK_* -> the logical id space ui3_key_text() expects (values mirror
 * macOS virtual keycodes so output matches the other platforms). */
static int win32_key_id(int vk)
{
    switch (vk) {
        case VK_TAB:    return 48;
        case VK_LEFT:   return 123;
        case VK_RIGHT:  return 124;
        case VK_UP:     return 126;
        case VK_DOWN:   return 125;
        case VK_RETURN: return 36;
        case VK_BACK:   return 51;
        case VK_HOME:   return 115;
        case VK_END:    return 119;
        case VK_DELETE: return 117;
        default:        return 0;
    }
}

static LRESULT CALLBACK win32_wndproc(HWND hwnd, UINT msg, WPARAM wParam, LPARAM lParam)
{
    win32_plat *p = (win32_plat *)GetWindowLongPtr(hwnd, GWLP_USERDATA);
    if (!p) return DefWindowProc(hwnd, msg, wParam, lParam);
    ui3_host *host = p->host;

    switch (msg) {
        case WM_CLOSE:
            if (host->close_cb) {
                int accept = 1;
                host->close_cb(host->close_ctx, &accept);
                if (!accept) return 0;
            }
            DestroyWindow(hwnd);
            return 0;

        case WM_DESTROY:
            host->running = 0;
            PostQuitMessage(0);
            return 0;

        case WM_GESTURE: {
            GESTUREINFO gi = {0};
            gi.cbSize = sizeof(gi);
            if (!GetGestureInfo((HGESTUREINFO)lParam, &gi))
                return DefWindowProc(hwnd, msg, wParam, lParam);
            BOOL handled = TRUE;
            switch (gi.dwID) {
                case GID_ZOOM:   /* pinch — data=0, text=zoom factor */
                    if (host->event_cb && (gi.dwFlags & GF_BEGIN)) {
                        float zoom = *(float*)&gi.ullArguments;
                        char buf[64];
                        snprintf(buf, sizeof(buf), "%.2f", zoom);
                        host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                                       (double)gi.ptsLocation.x, (double)gi.ptsLocation.y, 0, buf);
                    }
                    break;
                case GID_ROTATE: /* rotate — data=1, text=angle */
                    if (host->event_cb && (gi.dwFlags & GF_BEGIN)) {
                        float angle = *(float*)&gi.ullArguments;
                        char buf[64];
                        snprintf(buf, sizeof(buf), "%.2f", angle);
                        host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                                       (double)gi.ptsLocation.x, (double)gi.ptsLocation.y, 1, buf);
                    }
                    break;
                case GID_PAN:    /* pan — data=3, text=delta */
                    if (host->event_cb) {
                        char buf[64];
                        snprintf(buf, sizeof(buf), "%.0f,%.0f",
                                 (double)(SHORT)LOWORD(gi.ullArguments),
                                 (double)(SHORT)HIWORD(gi.ullArguments));
                        host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                                       (double)gi.ptsLocation.x, (double)gi.ptsLocation.y, 3, buf);
                    }
                    break;
                case GID_TWOFINGERTAP: /* swipe — data=2 */
                    if (host->event_cb)
                        host->event_cb(host->event_ctx, UI3_EVENT_GESTURE,
                                       (double)gi.ptsLocation.x, (double)gi.ptsLocation.y, 2, "");
                    break;
                default:
                    handled = FALSE;
                    break;
            }
            CloseGestureInfoHandle((HGESTUREINFO)lParam);
            if (handled) return 0;
            return DefWindowProc(hwnd, msg, wParam, lParam);
        }

        case WM_COMMAND: {
            /* Menu bar click (P-Native P1): LOWORD(wParam) = item id. */
            int id = LOWORD(wParam);
            if (host->event_cb && id >= 0 && id < p->menu_count && p->menu_msgs[id]) {
                host->event_cb(host->event_ctx, UI3_EVENT_MENU, 0, 0, 0, p->menu_msgs[id]);
            }
            return 0;
        }

        case WM_DROPFILES: {
            /* File drag-drop (P-Native P1). Concatenate dropped paths with
             * newlines and deliver a DROP event (dtype=1). */
            HDROP hd = (HDROP)wParam;
            POINT pt;
            DragQueryPoint(hd, &pt);
            UINT n = DragQueryFileW(hd, 0xFFFFFFFF, NULL, 0);
            char buf[4096];
            size_t off = 0;
            for (UINT i = 0; i < n && off < sizeof(buf) - 1; i++) {
                wchar_t wpath[MAX_PATH];
                DragQueryFileW(hd, i, wpath, MAX_PATH);
                int blen = WideCharToMultiByte(CP_UTF8, 0, wpath, -1, NULL, 0, NULL, NULL);
                if (off + blen > (int)sizeof(buf) - 1) break;
                WideCharToMultiByte(CP_UTF8, 0, wpath, -1, buf + off, blen, NULL, NULL);
                off += blen - 1;
                if (i + 1 < n && off < sizeof(buf) - 1) buf[off++] = '\n';
            }
            buf[off] = '\0';
            DragFinish(hd);
            if (host->event_cb && off > 0)
                host->event_cb(host->event_ctx, UI3_EVENT_DROP, (double)pt.x, (double)pt.y, 1, buf);
            return 0;
        }

        case WM_PAINT: {
            PAINTSTRUCT ps;
            HDC hdc = BeginPaint(hwnd, &ps);
            cairo_surface_t *surf = cairo_win32_surface_create(hdc);
            cairo_t *cr = cairo_create(surf);
            cairo_scale(cr, host->scale, host->scale);
            if (host->draw_cb) host->draw_cb(host->draw_ctx, host, cr);
            cairo_destroy(cr);
            cairo_surface_destroy(surf);
            EndPaint(hwnd, &ps);
            return 0;
        }

        case WM_LBUTTONDOWN:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_DOWN,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 1, NULL);
            return 0;
        case WM_LBUTTONUP:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_UP,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 1, NULL);
            return 0;
        case WM_RBUTTONDOWN:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_DOWN,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 2, NULL);
            return 0;
        case WM_RBUTTONUP:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_UP,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 2, NULL);
            return 0;
        case WM_MOUSEWHEEL: {
            if (host->event_cb) {
                POINT pt = { GET_X_LPARAM(lParam), GET_Y_LPARAM(lParam) };
                ScreenToClient(hwnd, &pt);
                // data > 0 == scroll down. Win32 reports up as positive, so negate.
                double dy = -(double)GET_WHEEL_DELTA_WPARAM(wParam) / (double)WHEEL_DELTA * 40.0;
                host->event_cb(host->event_ctx, UI3_EVENT_WHEEL,
                               (double)pt.x, (double)pt.y, dy, NULL);
            }
            return 0;
        }
        case WM_MOUSEMOVE:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_MOVE,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 0, NULL);
            return 0;

        case WM_KEYDOWN: {
            int vk = (int)wParam;
            int modifiers = 0;
            if (GetKeyState(VK_SHIFT) & 0x8000)   modifiers |= UI3_MOD_SHIFT;
            if (GetKeyState(VK_CONTROL) & 0x8000) modifiers |= UI3_MOD_CTRL;
            if (GetKeyState(VK_MENU) & 0x8000)    modifiers |= UI3_MOD_ALT;
            if ((GetKeyState(VK_LWIN) & 0x8000) || (GetKeyState(VK_RWIN) & 0x8000)) modifiers |= UI3_MOD_CMD;
            int id = win32_key_id(vk);
            if (id != 0) {
                char *text = ui3_key_text(id, modifiers, "");
                if (text) {
                    if (host->event_cb)
                        host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, text);
                    free(text);
                }
            }
            /* We own the only "control"; swallow Tab so the system does not try
             * to move focus to a native control that does not exist. */
            if (vk == VK_TAB) return 0;
            break;
        }

        case WM_CHAR: {
            int ch = (int)wParam;                 /* UTF-16 code unit */
            int modifiers = 0;
            if (GetKeyState(VK_SHIFT) & 0x8000)   modifiers |= UI3_MOD_SHIFT;
            if (GetKeyState(VK_CONTROL) & 0x8000) modifiers |= UI3_MOD_CTRL;
            if (GetKeyState(VK_MENU) & 0x8000)    modifiers |= UI3_MOD_ALT;
            if ((GetKeyState(VK_LWIN) & 0x8000) || (GetKeyState(VK_RWIN) & 0x8000)) modifiers |= UI3_MOD_CMD;
            if (ch < 0x20) {
                /* Ctrl+letter arrives as a control char; turn it back into
                 * "Ctrl+<letter>" so the same shortcut path as macOS runs. */
                if (modifiers & UI3_MOD_CTRL) {
                    char letter = (char)('a' + (ch - 1));
                    char *text = ui3_key_text(0, modifiers, &letter);
                    if (text) {
                        if (host->event_cb)
                            host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, text);
                        free(text);
                    }
                }
                return 0;
            }
            WCHAR wch = (WCHAR)ch;
            char utf8[8];
            int n = WideCharToMultiByte(CP_UTF8, 0, &wch, 1, utf8, sizeof(utf8) - 1, NULL, NULL);
            if (n <= 0) return 0;
            utf8[n] = '\0';
            char *text = ui3_key_text(0, modifiers, utf8);
            if (text) {
                if (host->event_cb)
                    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, (double)modifiers, text);
                free(text);
            }
            return 0;
        }
    }
    return DefWindowProc(hwnd, msg, wParam, lParam);
}

int ui3_plat_create_window(ui3_host *host, const char *title)
{
    HINSTANCE hinst = GetModuleHandle(NULL);
    static const wchar_t *cls = L"Ui3Win";

    WNDCLASSEXW wc;
    memset(&wc, 0, sizeof(wc));
    wc.cbSize = sizeof(wc);
    wc.lpfnWndProc = win32_wndproc;
    wc.hInstance = hinst;
    wc.hCursor = LoadCursor(NULL, IDC_ARROW);
    wc.lpszClassName = cls;
    wc.style = CS_HREDRAW | CS_VREDRAW;
    if (!RegisterClassExW(&wc)) {
        if (GetLastError() != ERROR_CLASS_ALREADY_EXISTS) return -1;
    }

    int wn = MultiByteToWideChar(CP_UTF8, 0, title ? title : "App", -1, NULL, 0);
    wchar_t *wt = malloc((size_t)wn * sizeof(wchar_t));
    MultiByteToWideChar(CP_UTF8, 0, title ? title : "App", -1, wt, wn);

    /* Center on the primary monitor instead of the cascading CW_USEDEFAULT. */
    int wx = (GetSystemMetrics(SM_CXSCREEN) - host->width) / 2;
    int wy = (GetSystemMetrics(SM_CYSCREEN) - host->height) / 2;
    HWND hwnd = CreateWindowExW(0, cls, wt, WS_OVERLAPPEDWINDOW,
                                wx, wy, host->width, host->height,
                                NULL, NULL, hinst, NULL);
    free(wt);
    if (!hwnd) return -1;

    win32_plat *p = malloc(sizeof(*p));
    memset(p, 0, sizeof(*p));
    p->host = host;
    p->hwnd = hwnd;
    host->plat = p;
    host->scale = 1.0;
    SetWindowLongPtr(hwnd, GWLP_USERDATA, (LONG_PTR)p);

    ShowWindow(hwnd, SW_SHOW);
    UpdateWindow(hwnd);
    DragAcceptFiles(hwnd, TRUE); /* enable WM_DROPFILES for file drag-drop */
    RegisterTouchWindow(hwnd, 0); /* enable WM_GESTURE for touch/trackpad */
    return 0;
}

void ui3_plat_request_redraw(ui3_host *host)
{
    win32_plat *p = host->plat;
    if (!p || !p->hwnd) return;
    InvalidateRect(p->hwnd, NULL, FALSE);
}

void ui3_plat_present(ui3_host *host)
{
    win32_plat *p = host->plat;
    if (!p || !p->hwnd) return;
    InvalidateRect(p->hwnd, NULL, FALSE);
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
    win32_plat *p = host->plat;
    if (!p) return host ? host->running : 0;
    MSG msg;
    while (PeekMessage(&msg, NULL, 0, 0, PM_REMOVE)) {
        TranslateMessage(&msg);   /* generates WM_CHAR from WM_KEYDOWN */
        DispatchMessage(&msg);
        if (!host->running) break;
    }
    return host->running;
}

void ui3_plat_run(ui3_host *host)
{
    win32_plat *p = host->plat;
    if (!p) return;
    MSG msg;
    while (host->running) {
        if (GetMessage(&msg, NULL, 0, 0) > 0) {
            TranslateMessage(&msg);
            DispatchMessage(&msg);
        }
    }
}

/* ---- System clipboard (P0.2) ---- */
void ui3_host_set_clipboard_text(ui3_host *host, const char *text)
{
    if (!text) return;
    if (host && host->headless) {
        free(host->last_clip_text);
        host->last_clip_text = strdup(text);
    }
    if (!OpenClipboard(NULL)) return;
    EmptyClipboard();

    int need = MultiByteToWideChar(CP_UTF8, 0, text, (int)strlen(text), NULL, 0);
    if (need <= 0) {
        CloseClipboard();
        return;
    }
    HGLOBAL h = GlobalAlloc(GMEM_MOVABLE, (need + 1) * sizeof(wchar_t));
    if (h) {
        wchar_t *p = (wchar_t *)GlobalLock(h);
        if (p) {
            MultiByteToWideChar(CP_UTF8, 0, text, (int)strlen(text), p, need + 1);
            GlobalUnlock(h);
        }
        SetClipboardData(CF_UNICODETEXT, h);
    }
    CloseClipboard();
}

char *ui3_host_get_clipboard_text(ui3_host *host)
{
    (void)host;
    static char *g = NULL;
    if (!OpenClipboard(NULL)) return NULL;
    char *r = NULL;

    /* Try UTF-16 first, fall back to ANSI */
    HANDLE h = GetClipboardData(CF_UNICODETEXT);
    if (h) {
        wchar_t *p = (wchar_t *)GlobalLock(h);
        if (p) {
            int need = WideCharToMultiByte(CP_UTF8, 0, p, -1, NULL, 0, NULL, NULL);
            if (need > 0) {
                char *n = (char *)malloc(need);
                if (n) {
                    WideCharToMultiByte(CP_UTF8, 0, p, -1, n, need, NULL, NULL);
                    r = n;
                }
            }
            GlobalUnlock(h);
        }
    }
    if (!r) {
        h = GetClipboardData(CF_TEXT);
        if (h) {
            char *p = (char *)GlobalLock(h);
            if (p) {
                size_t L = strlen(p);
                char *n = (char *)malloc(L + 1);
                if (n) {
                    memcpy(n, p, L + 1);
                    r = n;
                }
                GlobalUnlock(h);
            }
        }
    }

    CloseClipboard();
    free(g);
    g = r;
    return g;
}

/* ---- Clipboard multi-format (P-Native P2) ---- */

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
    if (!data || len <= 0) return;
    if (!OpenClipboard(NULL)) return;
    EmptyClipboard();
    /* Use a custom clipboard format so we round-trip PNG bytes
     * verbatim.  CF_DIB requires a bitmap header which we cannot
     * produce without a PNG decoder; our own format lets both ends
     * of a ui3 clipboard transfer pass raw PNG bytes. */
    UINT fmt = RegisterClipboardFormatA("UI3_IMAGE_PNG");
    HGLOBAL h = GlobalAlloc(GMEM_MOVABLE, len);
    if (h) {
        void *p = GlobalLock(h);
        if (p) {
            memcpy(p, data, len);
            GlobalUnlock(h);
            SetClipboardData(fmt, h);
        } else {
            GlobalFree(h);
        }
    }
    CloseClipboard();
}

const void *ui3_plat_clipboard_get_image(ui3_host *host, int *out_len)
{
    if (out_len) *out_len = 0;
    if (!OpenClipboard(NULL)) return NULL;
    UINT fmt = RegisterClipboardFormatA("UI3_IMAGE_PNG");
    HANDLE h = GetClipboardData(fmt);
    const void *r = NULL;
    if (h) {
        void *p = GlobalLock(h);
        SIZE_T sz = GlobalSize(h);
        if (p) {
            void *n = malloc((size_t)sz);
            if (n) {
                memcpy(n, p, (size_t)sz);
                r = n;
                if (out_len) *out_len = (int)sz;
            }
            GlobalUnlock(h);
        }
    }
    CloseClipboard();
    return r;
}

void ui3_plat_clipboard_set_uris(ui3_host *host, const char *uris)
{
    if (host && host->headless) {
        free(host->last_clip_uris);
        host->last_clip_uris = uris ? strdup(uris) : NULL;
        return;
    }
    if (!uris) return;
    if (!OpenClipboard(NULL)) return;
    EmptyClipboard();
    /* Store URIs in a custom format for round-trip fidelity.
     * CF_HDROP requires file paths (no protocol prefix) and only
     * conveys file paths — it cannot carry file:// URLs verbatim. */
    UINT fmt = RegisterClipboardFormatA("UI3_URIS");
    size_t L = strlen(uris) + 1;
    HGLOBAL h = GlobalAlloc(GMEM_MOVABLE, L);
    if (h) {
        char *p = (char *)GlobalLock(h);
        if (p) {
            memcpy(p, uris, L);
            GlobalUnlock(h);
            SetClipboardData(fmt, h);
        } else {
            GlobalFree(h);
        }
    }
    CloseClipboard();
}

const char *ui3_plat_clipboard_get_uris(ui3_host *host)
{
    (void)host;
    static char *g = NULL;
    if (!OpenClipboard(NULL)) { free(g); g = NULL; return ""; }
    UINT fmt = RegisterClipboardFormatA("UI3_URIS");
    HANDLE h = GetClipboardData(fmt);
    char *r = NULL;
    if (h) {
        char *p = (char *)GlobalLock(h);
        if (p) {
            size_t L = strlen(p);
            r = (char *)malloc(L + 1);
            if (r) memcpy(r, p, L + 1);
            GlobalUnlock(h);
        }
    }
    CloseClipboard();
    free(g);
    g = r;
    return g ? g : "";
}

void ui3_plat_clipboard_set_html(ui3_host *host, const char *html, const char *base_url)
{
    (void)host; (void)base_url;
    if (!html) return;
    if (!OpenClipboard(NULL)) return;
    EmptyClipboard();
    char preamble[128];
    int pl = snprintf(preamble, sizeof(preamble),
        "Version:0.9\r\nStartHTML:%08d\r\nEndHTML:%08d\r\nStartFragment:%08d\r\nEndFragment:%08d\r\n",
        0, (int)(42 + strlen(html) + 10), 42, (int)(42 + strlen(html) + 10));
    if (pl < 0) { CloseClipboard(); return; }
    int total = (int)strlen(preamble) + (int)strlen(html) + 32;
    HGLOBAL h = GlobalAlloc(GMEM_MOVABLE, total + 1);
    if (!h) { CloseClipboard(); return; }
    char *p = (char *)GlobalLock(h);
    if (!p) { GlobalFree(h); CloseClipboard(); return; }
    memcpy(p, preamble, strlen(preamble));
    memcpy(p + strlen(preamble), html, strlen(html));
    p[strlen(preamble) + strlen(html)] = '\0';
    GlobalUnlock(h);
    UINT fmt = RegisterClipboardFormatA("HTML Format");
    SetClipboardData(fmt, h);
    CloseClipboard();
}

const char *ui3_plat_clipboard_get_html(ui3_host *host)
{
    (void)host;
    if (!OpenClipboard(NULL)) return "";
    UINT fmt = RegisterClipboardFormatA("HTML Format");
    HANDLE h = GetClipboardData(fmt);
    if (!h) { CloseClipboard(); return ""; }
    char *raw = (char *)GlobalLock(h);
    if (!raw) { CloseClipboard(); return ""; }
    size_t L = strlen(raw);
    char *n = (char *)malloc(L + 1);
    if (n) memcpy(n, raw, L + 1);
    GlobalUnlock(h);
    CloseClipboard();
    static char *g = NULL;
    free(g); g = n;
    return g;
}

int ui3_plat_clipboard_formats(ui3_host *host)
{
    (void)host;
    int m = 0;
    if (!OpenClipboard(NULL)) return 0;
    UINT fmt = EnumClipboardFormats(0);
    while (fmt) {
        if (fmt == CF_TEXT || fmt == CF_UNICODETEXT) m |= UI3_CLIP_TEXT;
        if (fmt == CF_DIB || fmt == CF_DIBV5) m |= UI3_CLIP_IMAGE;
        if (fmt == CF_HDROP) m |= UI3_CLIP_FILES;
        fmt = EnumClipboardFormats(fmt);
    }
    CloseClipboard();
    return m;
}

/* ---- Modal file dialogs (P0.3) ---- */
char *ui3_host_open_file(ui3_host *host, const char *filters)
{
    (void)host;
    static char *g = NULL;
    ui3_filter_group groups[8];
    int ng = ui3_parse_filters(filters, groups, 8);

    static char fbuf[1024];
    fbuf[0] = '\0';
    int pos = 0;
    for (int i = 0; i < ng; i++) {
        int l = (int)strlen(groups[i].label);
        if (pos + l + 1 >= (int)sizeof(fbuf)) break;
        memcpy(fbuf + pos, groups[i].label, l); pos += l; fbuf[pos++] = '\0';
        int start = pos;
        for (int j = 0; j < groups[i].nexts; j++) {
            char pat[32];
            snprintf(pat, sizeof(pat), "*.%s", groups[i].exts[j]);
            int pl = (int)strlen(pat);
            if (pos + pl + 1 >= (int)sizeof(fbuf)) break;
            memcpy(fbuf + pos, pat, pl); pos += pl;
            if (j + 1 < groups[i].nexts) fbuf[pos++] = ';';
        }
        if (pos == start) { fbuf[pos++] = '*'; fbuf[pos++] = '.'; fbuf[pos++] = '*'; }
        fbuf[pos++] = '\0';
    }
    {
        const char *lbl = "All Files";
        int ll = (int)strlen(lbl);
        if (pos + ll + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, lbl, ll); pos += ll; fbuf[pos++] = '\0'; }
        const char *pat = "*.*";
        int pl = (int)strlen(pat);
        if (pos + pl + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, pat, pl); pos += pl; fbuf[pos++] = '\0'; }
    }
    fbuf[pos++] = '\0';

    OPENFILENAME ofn;
    char buf[1024];
    memset(&ofn, 0, sizeof(ofn));
    memset(buf, 0, sizeof(buf));
    ofn.lStructSize = sizeof(ofn);
    ofn.lpstrFile = buf;
    ofn.nMaxFile = sizeof(buf);
    ofn.lpstrFilter = fbuf;
    ofn.nFilterIndex = 1;
    ofn.Flags = OFN_PATHMUSTEXIST | OFN_FILEMUSTEXIST;
    if (GetOpenFileName(&ofn)) {
        free(g);
        g = strdup(buf);
        return g;
    }
    return NULL;
}

char *ui3_host_save_file(ui3_host *host, const char *defext)
{
    (void)host;
    static char *g = NULL;
    static char fbuf[512];
    fbuf[0] = '\0';
    int pos = 0;
    if (defext && *defext) {
        char name[64];
        int l = snprintf(name, sizeof(name), "*.%s", defext);
        if (pos + l + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, name, l); pos += l; fbuf[pos++] = '\0'; }
        if (pos + l + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, name, l); pos += l; fbuf[pos++] = '\0'; }
        const char *lbl = "All Files";
        int ll = (int)strlen(lbl);
        if (pos + ll + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, lbl, ll); pos += ll; fbuf[pos++] = '\0'; }
        memcpy(fbuf + pos, "*.*", 3); pos += 3; fbuf[pos++] = '\0';
    } else {
        const char *lbl = "All Files";
        int ll = (int)strlen(lbl);
        if (pos + ll + 1 < (int)sizeof(fbuf)) { memcpy(fbuf + pos, lbl, ll); pos += ll; fbuf[pos++] = '\0'; }
        memcpy(fbuf + pos, "*.*", 3); pos += 3; fbuf[pos++] = '\0';
    }
    fbuf[pos++] = '\0';

    OPENFILENAME ofn;
    char buf[1024];
    memset(&ofn, 0, sizeof(ofn));
    memset(buf, 0, sizeof(buf));
    if (defext && *defext) {
        snprintf(buf, sizeof(buf), "untitled.%s", defext);
    }
    ofn.lStructSize = sizeof(ofn);
    ofn.lpstrFile = buf;
    ofn.nMaxFile = sizeof(buf);
    ofn.lpstrFilter = fbuf;
    ofn.nFilterIndex = 1;
    ofn.lpstrDefExt = (defext && *defext) ? defext : NULL;
    ofn.Flags = OFN_OVERWRITEPROMPT;
    if (GetSaveFileName(&ofn)) {
        free(g);
        g = strdup(buf);
        return g;
    }
    return NULL;
}

void ui3_plat_destroy(ui3_host *host)
{
    win32_plat *p = host->plat;
    if (!p) return;
    if (p->uiaCallback) {
        IUIAutomationRegistrarCallback *cb = p->uiaCallback;
        cb->lpVtbl->Release(cb);
        p->uiaCallback = NULL;
    }
    if (p->uiaRegistrar) {
        p->uiaRegistrar->lpVtbl->Release(p->uiaRegistrar);
        p->uiaRegistrar = NULL;
    }
    if (p->com_inited) {
        CoUninitialize();
        p->com_inited = FALSE;
    }
    if (p->hwnd) DestroyWindow(p->hwnd);
    free(p);
    host->plat = NULL;
}

/* ---- Window management (P1) ---- */
void ui3_plat_set_title(ui3_host *host, const char *title)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    const char *t = title ? title : "App";
    int n = MultiByteToWideChar(CP_UTF8, 0, t, -1, NULL, 0);
    wchar_t *wt = (wchar_t *)malloc((size_t)n * sizeof(wchar_t));
    if (wt) {
        MultiByteToWideChar(CP_UTF8, 0, t, -1, wt, n);
        SetWindowTextW(p->hwnd, wt);
        free(wt);
    }
}

void ui3_plat_resize(ui3_host *host, int w, int h)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    SetWindowPos(p->hwnd, NULL, 0, 0, w, h, SWP_NOMOVE | SWP_NOZORDER);
}

void ui3_plat_minimize(ui3_host *host)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    ShowWindow(p->hwnd, SW_MINIMIZE);
}

void ui3_plat_close(ui3_host *host)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    PostMessage(p->hwnd, WM_CLOSE, 0, 0);
    p->hwnd = 0;
}

void ui3_plat_move(ui3_host *host, int x, int y)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    SetWindowPos(p->hwnd, NULL, x, y, 0, 0, SWP_NOSIZE | SWP_NOZORDER);
}

void ui3_plat_fullscreen(ui3_host *host)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;
    if (host->fullscreen) {
        LONG style = GetWindowLong(p->hwnd, GWL_STYLE);
        SetWindowLong(p->hwnd, GWL_STYLE, style | WS_MAXIMIZE);
        ShowWindow(p->hwnd, SW_MAXIMIZE);
    } else {
        ShowWindow(p->hwnd, SW_RESTORE);
    }
}

/* ---- Native modal dialogs (P-Native P1) ---- */
int ui3_plat_dialog(ui3_host *host, int kind, int style, const char *title,
                    const char *message, const char *buttons)
{
    (void)style; /* Win32 has no sheet concept; treats sheet as a modal box */
    if (!host || !host->plat) return -1;
    win32_plat *p = host->plat;
    HWND hwnd = p->hwnd;

    int n = 1;
    for (const char *b = buttons; b && *b; b++) if (*b == '|') n++;

    UINT flags = MB_APPLMODAL;
    if (kind == 1) flags |= MB_ICONWARNING;
    else if (kind == 2) flags |= MB_ICONERROR;
    else if (kind == 3) flags |= MB_ICONQUESTION;
    else flags |= MB_ICONINFORMATION;
    if (n >= 3) flags |= MB_YESNOCANCEL;
    else if (n == 2) flags |= MB_OKCANCEL;
    else flags |= MB_OK;

    wchar_t wtitle[256], wmsg[1024];
    MultiByteToWideChar(CP_UTF8, 0, title ? title : "", -1, wtitle, 256);
    MultiByteToWideChar(CP_UTF8, 0, message ? message : "", -1, wmsg, 1024);

    int r = MessageBoxW(hwnd, wmsg, wtitle, flags);
    if (r == IDOK) return 0;
    if (r == IDYES) return 0;
    if (r == IDNO) return 1;
    if (r == IDCANCEL) return (n >= 3) ? 2 : 1;
    return -1;
}

/* ---- Native notification / toast (P-Native P1) ---- */
/* Win32 native toasts require WinRT (RoActivateInstance + ToastNotifier),
 * which is disproportionately heavy for this ABI. Best-effort: no-op that
 * records the call (handled by common.c) and reports success; real WinRT
 * toast is a follow-up. */
int ui3_plat_notify(ui3_host *host, const char *title, const char *body)
{
    (void)host; (void)title; (void)body;
    return 0;
}

/* ---- Native menu bar (P-Native P1) ---- */
/* Parse the encoded menu text and build a Win32 menu bar. Item ids are 1-based
 * (0 is reserved); p->menu_msgs[id] holds the onClick message for WM_COMMAND.
 * Shortcuts are display-only here (real accelerators need an accelerator table). */
void ui3_plat_set_menu(ui3_host *host, const char *menu)
{
    if (!host || !host->plat || !menu) return;
    win32_plat *p = host->plat;
    if (!p->hwnd) return;

    for (int i = 0; i < p->menu_count; i++) free(p->menu_msgs[i]);
    free(p->menu_msgs);
    p->menu_msgs = NULL;
    p->menu_count = 0;
    int cap = 0;

    char *dup = strdup(menu);
    if (!dup) return;
    HMENU bar = CreateMenu();
    HMENU current = NULL;
    char *save_nl = NULL;
    for (char *line = strtok_r(dup, "\n", &save_nl); line; line = strtok_r(NULL, "\n", &save_nl)) {
        if (line[0] == '\t') {
            if (!current) continue;
            char *s1 = NULL;
            char *label = strtok_r(line + 1, "\t", &s1);
            char *onClick = strtok_r(NULL, "\t", &s1);
            if (!label) continue;
            if (strcmp(label, "-") == 0) {
                AppendMenuW(current, MF_SEPARATOR, 0, NULL);
                continue;
            }
            int id = ++p->menu_count;
            if (p->menu_count > cap) {
                cap = cap ? cap * 2 : 8;
                p->menu_msgs = realloc(p->menu_msgs, (cap + 1) * sizeof(char *));
            }
            p->menu_msgs[id] = onClick ? strdup(onClick) : strdup("");
            wchar_t wlabel[256];
            MultiByteToWideChar(CP_UTF8, 0, label, -1, wlabel, 256);
            AppendMenuW(current, MF_STRING, (UINT_PTR)id, wlabel);
        } else if (line[0] != '\0') {
            current = CreatePopupMenu();
            wchar_t wlabel[256];
            MultiByteToWideChar(CP_UTF8, 0, line, -1, wlabel, 256);
            AppendMenuW(bar, MF_POPUP | MF_STRING, (UINT_PTR)current, wlabel);
        }
    }
    free(dup);

    SetMenu(p->hwnd, bar);
    DrawMenuBar(p->hwnd);
}

/* ---- UI Automation accessibility (P-Native P1) ---- */
/*
 * Provides native Windows UIA elements for screen readers via:
 *   - COM STA init (IUIAutomation requires single-threaded apartment)
 *   - A registered IRawElementProviderSimple provider on the window
 *   - Window subclassing to handle WM_GETOBJECT and hand back our provider
 *   - The provider reads host->plat_a11y each time properties are queried
 *     (so the tree is always live without re-registration).
 *
 * Because UIA property getters are synchronous callbacks, the a11y tree
 * pointer (ui3_host_set_a11y_tree) must be current when queried.  The
 * provider stores the current root pointer and queries it on demand,
 *     freeing the previous tree only on explicit replacement.
 */

static void win32_free_a11y_tree(ui3_a11y_node *root)
{
    if (!root) return;
    for (int i = 0; i < root->child_count; i++)
        win32_free_a11y_tree(root->children[i]);
    free(root->children);
    free((void *)root->role);
    free((void *)root->label);
    free((void *)root->description);
    free(root);
}

/* ui3_a11y_node role → UIA ControlType ID. */
static INT32 win32_role_to_ctrltype(const char *role)
{
    if (!role) return UIA_PaneControlTypeId;
    if (strcmp(role, "button") == 0)      return UIA_ButtonControlTypeId;
    if (strcmp(role, "checkbox") == 0)    return UIA_CheckBoxControlTypeId;
    if (strcmp(role, "input") == 0 ||
        strcmp(role, "textarea") == 0 ||
        strcmp(role, "search") == 0)      return UIA_EditControlTypeId;
    if (strcmp(role, "label") == 0 ||
        strcmp(role, "text") == 0 ||
        strcmp(role, "heading") == 0 ||
        strcmp(role, "title") == 0)       return UIA_TextControlTypeId;
    if (strcmp(role, "list") == 0 ||
        strcmp(role, "combobox") == 0)    return UIA_ListControlTypeId;
    if (strcmp(role, "list_item") == 0)   return UIA_ListItemControlTypeId;
    if (strcmp(role, "slider") == 0)      return UIA_SliderControlTypeId;
    if (strcmp(role, "progress") == 0)    return UIA_ProgressBarControlTypeId;
    if (strcmp(role, "scroll") == 0)      return UIA_ScrollBarControlTypeId;
    if (strcmp(role, "panel") == 0 ||
        strcmp(role, "container") == 0 ||
        strcmp(role, "tab") == 0)        return UIA_GroupControlTypeId;
    if (strcmp(role, "tab_list") == 0)   return UIA_TabControlTypeId;
    if (strcmp(role, "menu") == 0)        return UIA_MenuControlTypeId;
    return UIA_PaneControlTypeId;
}

typedef struct {
    ULONG            refcount;
    HWND             hwnd;
    ui3_host        *host;
} win32_uia_provider;

static HRESULT STDMETHODCALLTYPE
win32_uia_QueryInterface(win32_uia_provider *p, REFIID riid, void **ppv)
{
    if (IsEqualIID(riid, &IID_IUnknown) ||
        IsEqualIID(riid, &IID_IRawElementProviderSimple)) {
        p->refcount++;
        *ppv = p;
        return S_OK;
    }
    *ppv = NULL;
    return E_NOINTERFACE;
}

static ULONG STDMETHODCALLTYPE win32_uia_AddRef(win32_uia_provider *p)
{
    return ++p->refcount;
}

static ULONG STDMETHODCALLTYPE win32_uia_Release(win32_uia_provider *p)
{
    ULONG rc = --p->refcount;
    if (rc == 0) free(p);
    return rc;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetPatternProvider(win32_uia_provider *p, ULONG patternId,
                              IInspectable **provider)
{
    (void)p; (void)patternId;
    *provider = NULL;
    return E_FAIL;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetPropertyValue(win32_uia_provider *p, PROPERTYID propId,
                             VARIANT *value)
{
    if (!value) return E_POINTER;
    VariantInit(value);

    if (propId == UIA_NamePropertyId) {
        /* Read root label live from the host a11y tree. */
        if (!p->host || !p->host->plat_a11y)
            return S_OK;
        const char *label = ((ui3_a11y_node *)p->host->plat_a11y)->label;
        if (label && label[0]) {
            int n = MultiByteToWideChar(CP_UTF8, 0, label, -1, NULL, 0);
            if (n > 0) {
                wchar_t *w = (wchar_t *)malloc((size_t)n * sizeof(wchar_t));
                if (w) {
                    MultiByteToWideChar(CP_UTF8, 0, label, -1, w, n);
                    value->vt = VT_BSTR;
                    value->bstrVal = SysAllocString(w);
                    free(w);
                    if (value->bstrVal)
                        return S_OK;
                }
            }
        }
    } else if (propId == UIA_ControlTypePropertyId) {
        value->vt = VT_I4;
        value->lVal = UIA_PaneControlTypeId;
        return S_OK;
    } else if (propId == UIA_NativeWindowHandlePropertyId) {
        value->vt = VT_I4;
        value->lVal = 0;
        return S_OK;
    }
    value->vt = VT_EMPTY;
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetBoundingRectangle(win32_uia_provider *p,
                                IRawElementProviderSimple **el,
                                RECT *rect)
{
    (void)el;
    if (!rect) return E_POINTER;
    if (GetWindowRect(p->hwnd, rect) == FALSE) {
        memset(rect, 0, sizeof(*rect));
        return S_FALSE;
    }
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetRuntimeId(win32_uia_provider *p,
                        IRawElementProviderSimple **el, UINT32 *len,
                        UINT **id)
{
    (void)el;
    if (!id) return E_POINTER;
    *id = (UINT *)malloc(sizeof(UINT) * 2);
    if (!*id) return E_OUTOFMEMORY;
    (*id)[0] = 1;
    (*id)[1] = (UINT)(ULONG_PTR)p->hwnd;
    if (len) *len = 2;
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetHostRawElementProvider(win32_uia_provider *p, HWND hwnd,
                                    IRawElementProviderSimple **el,
                                    IRawElementProviderSimple **provider)
{
    (void)p; (void)hwnd; (void)el;
    if (provider) *provider = NULL;
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetEmbeddedElementProviders(win32_uia_provider *p,
                                       IRawElementProviderSimple **el,
                                       UINT32 **providerCount,
                                       IRawElementProviderSimple ***providers)
{
    if (!p->host || !p->host->plat_a11y) {
        if (providerCount) *providerCount = 0;
        if (providers) *providers = NULL;
        return S_OK;
    }

    ui3_a11y_node *root = (ui3_a11y_node *)p->host->plat_a11y;
    if (root && root->child_count > 0) {
        if (providerCount) *providerCount = (UINT32)root->child_count;
        if (providers) {
            *providers = (IRawElementProviderSimple **)calloc(
                root->child_count, sizeof(IRawElementProviderSimple *));
        }
        /* We don't materialize per-child providers for now; children are
         * reported via the host text (ui3_host_last_a11y) for NVDA/JAWS. */
    } else {
        if (providerCount) *providerCount = 0;
        if (providers) *providers = NULL;
    }
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetFocus(win32_uia_provider *p, IRawElementProviderSimple **el,
                   IRawElementProviderSimple **provider)
{
    (void)p; (void)el;
    if (provider) *provider = NULL;
    return S_OK;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_GetParent(win32_uia_provider *p, IRawElementProviderSimple **el,
                    IRawElementProviderSimple **provider)
{
    (void)p; (void)el;
    if (provider) *provider = NULL;
    return S_OK;
}

static IRawElementProviderSimpleVtbl win32_uia_vtbl = {
    win32_uia_QueryInterface,
    win32_uia_AddRef,
    win32_uia_Release,
    win32_uia_GetPatternProvider,
    win32_uia_GetPropertyValue,
    win32_uia_GetBoundingRectangle,
    win32_uia_GetRuntimeId,
    win32_uia_GetHostRawElementProvider,
    win32_uia_GetEmbeddedElementProviders,
    win32_uia_GetFocus,
    win32_uia_GetParent
};

static HRESULT win32_make_provider(win32_plat *p,
                                    IRawElementProviderSimple **out)
{
    win32_uia_provider *prov = malloc(sizeof(*prov));
    if (!prov) return E_OUTOFMEMORY;
    memset(prov, 0, sizeof(*prov));
    prov->lpVtbl = &win32_uia_vtbl;
    prov->refcount = 1;
    prov->hwnd = p->hwnd;
    prov->host = p->host;
    *out = &prov->lpVtbl;
    return S_OK;
}

typedef struct {
    IUIAutomationRegistrarCallbackVtbl *lpVtbl;
    ULONG  refcount;
} win32_uia_callback;

/* UIARegistrar callback: when a provider is requested for hwnd, hand back ours. */
static HRESULT STDMETHODCALLTYPE
win32_uia_provider_callback(IUIAutomationRegistrarCallback *callback,
                              ULONG_PTR hwndPointer,
                              IRawElementProviderSimple **provider)
{
    win32_plat *p = (win32_plat *)GetWindowLongPtr((HWND)hwndPointer, GWLP_USERDATA);
    if (!p) {
        *provider = NULL;
        return S_FALSE;
    }
    win32_make_provider(p, provider);
    if (*provider) (*provider)->lpVtbl->AddRef(*provider);
    return *provider ? S_OK : E_FAIL;
}

static HRESULT STDMETHODCALLTYPE
win32_uia_cb_QueryInterface(IUIAutomationRegistrarCallback *cb, REFIID riid, void **ppv)
{
    if (IsEqualIID(riid, &IID_IUnknown) ||
        IsEqualIID(riid, &IID_IUIAutomationRegistrarCallback)) {
        win32_uia_callback *c = (win32_uia_callback *)cb;
        c->refcount++;
        *ppv = cb;
        return S_OK;
    }
    *ppv = NULL;
    return E_NOINTERFACE;
}

static ULONG STDMETHODCALLTYPE
win32_uia_cb_AddRef(IUIAutomationRegistrarCallback *cb)
{
    win32_uia_callback *c = (win32_uia_callback *)cb;
    return ++c->refcount;
}

static ULONG STDMETHODCALLTYPE
win32_uia_cb_Release(IUIAutomationRegistrarCallback *cb)
{
    win32_uia_callback *c = (win32_uia_callback *)cb;
    ULONG rc = --c->refcount;
    if (rc == 0) free(c);
    return rc;
}

static IUIAutomationRegistrarCallbackVtbl win32_reg_cb_vtbl = {
    win32_uia_cb_QueryInterface,
    win32_uia_cb_AddRef,
    win32_uia_cb_Release,
    win32_uia_provider_callback
};

static void win32_register_uia(win32_plat *p)
{
    if (!p->hwnd) return;

    /* COM STA init — required by UIA on the window thread. */
    HRESULT hr = CoInitializeEx(NULL, COINIT_APARTMENTTHREADED);
    if (FAILED(hr)) return;
    p->com_inited = TRUE;

    p->uiaRegistrar = NULL;
    CoCreateInstance(&CLSID_UIAutomationRegistrar, NULL, CLSCTX_INPROC_SERVER,
                     &IID_IUIAutomationRegistrar, (void **)&p->uiaRegistrar);
    if (!p->uiaRegistrar) {
        CoUninitialize();
        p->com_inited = FALSE;
        return;
    }

    /* Allocate a COM object that implements IUIAutomationRegistrarCallback. */
    win32_uia_callback *cb = malloc(sizeof(*cb));
    if (!cb) {
        p->uiaRegistrar->lpVtbl->Release(p->uiaRegistrar);
        p->uiaRegistrar = NULL;
        CoUninitialize();
        p->com_inited = FALSE;
        return;
    }
    cb->lpVtbl = &win32_reg_cb_vtbl;
    cb->refcount = 1;
    p->uiaCallback = cb;
    hr = p->uiaRegistrar->lpVtbl->RegisterProviderCallback(
        p->uiaRegistrar, p->hwnd, cb,
        (ULONG_PTR)p->hwnd);
    if (FAILED(hr)) {
        p->uiaCallback->lpVtbl->Release(p->uiaCallback);
        p->uiaCallback = NULL;
        p->uiaRegistrar->lpVtbl->Release(p->uiaRegistrar);
        p->uiaRegistrar = NULL;
        CoUninitialize();
        p->com_inited = FALSE;
    }
}

static LRESULT WINAPI
win32_uia_subclass(HWND hwnd, UINT msg, WPARAM wParam, LPARAM lParam,
                   UINT_PTR subclassId, DWORD_PTR refData)
{
    if (msg == WM_GETOBJECT) {
        /* Force Windows to use our registered UIA provider. */
        return DefWindowProcW(hwnd, msg, wParam, lParam);
    }
    return DefSubclassProc(hwnd, msg, wParam, lParam);
}

static void win32_update_uia(win32_plat *p, const ui3_a11y_node *root)
{
    if (!p || !p->uiaRegistrar) return;

    /* Update the a11y tree on the host.  The provider reads it live. */
    if (p->host) {
        ui3_host_set_a11y_tree(p->host, root);
    }

    /* Invalidate the window's accessible region so UIA refreshes. */
    if (p->hwnd) {
        SetWindowPos(p->hwnd, NULL, 0, 0, 0, 0,
                     SWP_NOMOVE | SWP_NOSIZE | SWP_NOZORDER | SWP_FRAMECHANGED);
        InvalidateRect(p->hwnd, NULL, FALSE);
    }
}

/* ---- Accessibility tree (P-Native P1) ---- */
void ui3_plat_accessibility(ui3_host *host, ui3_a11y_node *root)
{
    if (!host || !host->plat) return;
    win32_plat *p = host->plat;
    if (!p->com_inited) win32_register_uia(p);
    win32_update_uia(p, root);
}
