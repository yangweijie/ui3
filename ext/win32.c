/* win32 backend: real Win32 window + Cairo Win32 surface.
 * Keyboard events are translated through the SAME ui3_key_text() the Cocoa
 * and headless paths use, so the canonical key text (Tab / Shift+Tab / arrows
 * / Return / Backspace / printable) is identical across every platform. */
#include "internal.h"
#include <windows.h>
#include <cairo/cairo.h>
#include <cairo/cairo-win32.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    ui3_host *host;
    HWND hwnd;
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
        default:        return 0;
    }
}

static LRESULT CALLBACK win32_wndproc(HWND hwnd, UINT msg, WPARAM wParam, LPARAM lParam)
{
    win32_plat *p = (win32_plat *)GetWindowLongPtr(hwnd, GWLP_USERDATA);
    if (!p) return DefWindowProc(hwnd, msg, wParam, lParam);
    ui3_host *host = p->host;

    switch (msg) {
        case WM_DESTROY:
            host->running = 0;
            PostQuitMessage(0);
            return 0;

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
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 0, NULL);
            return 0;
        case WM_LBUTTONUP:
            if (host->event_cb)
                host->event_cb(host->event_ctx, UI3_EVENT_POINTER_UP,
                               (double)GET_X_LPARAM(lParam), (double)GET_Y_LPARAM(lParam), 0, NULL);
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
            int shift = (GetKeyState(VK_SHIFT) & 0x8000) ? 1 : 0;
            int id = win32_key_id(vk);
            if (id != 0) {
                char *text = ui3_key_text(id, shift, "");
                if (text) {
                    if (host->event_cb)
                        host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, 0, text);
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
            if (ch < 0x20) return 0;              /* control chars handled via WM_KEYDOWN */
            int shift = (GetKeyState(VK_SHIFT) & 0x8000) ? 1 : 0;
            WCHAR wch = (WCHAR)ch;
            char utf8[8];
            int n = WideCharToMultiByte(CP_UTF8, 0, &wch, 1, utf8, sizeof(utf8) - 1, NULL, NULL);
            if (n <= 0) return 0;
            utf8[n] = '\0';
            char *text = ui3_key_text(0, shift, utf8);
            if (text) {
                if (host->event_cb)
                    host->event_cb(host->event_ctx, UI3_EVENT_KEY, 0, 0, 0, text);
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
    p->host = host;
    p->hwnd = hwnd;
    host->plat = p;
    host->scale = 1.0;
    SetWindowLongPtr(hwnd, GWLP_USERDATA, (LONG_PTR)p);

    ShowWindow(hwnd, SW_SHOW);
    UpdateWindow(hwnd);
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

void ui3_plat_destroy(ui3_host *host)
{
    win32_plat *p = host->plat;
    if (!p) return;
    if (p->hwnd) DestroyWindow(p->hwnd);
    free(p);
    host->plat = NULL;
}
