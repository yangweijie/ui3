#!/usr/bin/env bash
# Sets the shared-library search path so the built libui3 can be dlopen()'d,
# then execs the given command. Used by composer scripts and CI so the
# PHP FFI layer can find build/libui3.* without an absolute path.
set -euo pipefail
DIR="$(cd "$(dirname "$0")/.." && pwd)/build"

case "$(uname -s)" in
  Darwin) export DYLD_LIBRARY_PATH="$DIR:${DYLD_LIBRARY_PATH:-}" ;;
  Linux)  export LD_LIBRARY_PATH="$DIR:${LD_LIBRARY_PATH:-}" ;;
  MINGW* | MSYS* | CYGWIN*) export PATH="$DIR:$PATH" ;;
esac

if [ "$#" -eq 0 ]; then
    echo "usage: bin/run.sh <command> [args...]" >&2
    echo "  sets the libui3 library path, then runs the command, e.g.:" >&2
    echo "    bin/run.sh php85 -d ffi.enable=true examples/counter.php" >&2
    echo "    bin/run.sh vendor/bin/pest" >&2
    exit 1
fi

exec "$@"
