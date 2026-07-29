#!/usr/bin/env bash
# Build the libui3 canvas host (libui-free).
# macOS: AppKit window + Cairo Quartz; Linux: X11 window + Cairo Xlib;
# Windows: Win32 window + Cairo Win32. All share ui3_key_text() for key routing.
set -euo pipefail

cd "$(dirname "$0")/.."
mkdir -p build

UNAME="$(uname)"
INC="/opt/homebrew/include /usr/local/include /usr/include"
LIB="/opt/homebrew/lib /usr/local/lib /usr/lib"

cflags=()
libs=()
platform_src=""

if [ "$UNAME" = "Darwin" ]; then
  SDK="$(xcrun --show-sdk-path 2>/dev/null || echo /Library/Developer/CommandLineTools/SDKs/MacOSX.sdk)"
  cflags+=(-isysroot "$SDK" -F"$SDK/System/Library/Frameworks")
fi

case "$UNAME" in
  Darwin)
    platform_src="ext/cocoa.m"
    libs+=(-framework Cocoa -framework CoreFoundation -framework UniformTypeIdentifiers)
    ;;
  Linux)
    platform_src="ext/x11.c"
    gtk_cflags="$(pkg-config --cflags gtk+-3.0 2>/dev/null || true)"
    gtk_libs="$(pkg-config --libs gtk+-3.0 2>/dev/null || true)"
    [ -n "$gtk_cflags" ] && cflags+=($gtk_cflags)
    [ -n "$gtk_libs" ] && libs+=($gtk_libs)
    libs+=(-lX11)
    ;;
  MINGW* | MSYS* | Windows_NT)
    platform_src="ext/win32.c"
    libs+=(-luser32 -lgdi32)
    ;;
  *)
    echo "unsupported platform: $UNAME" >&2
    exit 1
    ;;
esac

# Locate cairo headers/libs (Homebrew / system).
for d in $INC; do [ -f "$d/cairo/cairo.h" ] && cflags+=(-I"$d"); done
for d in $LIB; do [ -f "$d/libcairo.dylib" ] || [ -f "$d/libcairo.so" ] && libs+=(-L"$d"); done
libs+=(-lcairo)

out="build/libui3"
[ "$UNAME" = "Darwin" ] && out="build/libui3.dylib"
[ "$UNAME" = "Linux" ] && out="build/libui3.so"
case "$UNAME" in
  MINGW* | MSYS* | Windows_NT) out="build/libui3.dll" ;;
esac

echo "building $out"
clang -shared -fobjc-arc -O2 \
  $platform_src ext/common.c \
  ${cflags[@]} ${libs[@]} \
  -o "$out"

echo "ok: $out"
