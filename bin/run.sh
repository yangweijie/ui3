#!/usr/bin/env bash
# Sets the shared-library search path so the built libui3 can be dlopen()'d,
# then execs the given command. Used by composer scripts and CI so the
# PHP FFI layer can find build/libui3.* without an absolute path.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/build"

# Pick the platform-specific libui3 artifact name (mirrors ext/build.sh).
case "$(uname -s)" in
  Darwin) LIB="$DIR/libui3.dylib" ;;
  Linux)  LIB="$DIR/libui3.so" ;;
  MINGW* | MSYS* | CYGWIN* | Windows_NT) LIB="$DIR/libui3.dll" ;;
  *)      LIB="$DIR/libui3" ;;
esac

# Auto-build the native host if it is missing (skip with UI3_SKIP_BUILD=1,
# e.g. when CI builds it in a separate, cached step).
if [ "${UI3_SKIP_BUILD:-}" != "1" ] && [ ! -f "$LIB" ]; then
    echo "libui3 not found at $LIB — building via ext/build.sh" >&2
    bash "$ROOT/ext/build.sh"
fi

case "$(uname -s)" in
  Darwin) export DYLD_LIBRARY_PATH="$DIR:${DYLD_LIBRARY_PATH:-}" ;;
  Linux)  export LD_LIBRARY_PATH="$DIR:${LD_LIBRARY_PATH:-}" ;;
  MINGW* | MSYS* | CYGWIN* | Windows_NT) export PATH="$DIR:$PATH" ;;
esac

if [ "$#" -eq 0 ]; then
    echo "usage: bin/run.sh <command> [args...]" >&2
    echo "  builds libui3 if missing, sets its library path, then runs the command, e.g.:" >&2
    echo "    bin/run.sh php85 -d ffi.enable=true examples/counter.php" >&2
    echo "    bin/run.sh vendor/bin/pest" >&2
    exit 1
fi

exec "$@"
