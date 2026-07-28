# AGENTS.md

## Project Overview

PHP FFI GUI library (`yangweijie/ui3`) — early stage, `src/` is empty. Builds on `kingbes/phpc` for safe FFI operations with native GUI APIs.

## Requirements

- PHP >= 8.2
- `ext-ffi` extension enabled (`-d ffi.enable=true`)
- macOS/Linux/Windows (platform-specific GUI examples)

## Key Commands

```bash
# Install dependencies
composer install

# Run a single example (FFI must be enabled)
php -d ffi.enable=true -f vendor/kingbes/phpc/examples/01_cdata.php

# Run phpc regression tests (all examples)
php -d ffi.enable=true -f vendor/kingbes/phpc/tests/run_examples.php

# Run only non-GUI examples (CI-safe)
PHPC_SKIP_GUI=1 php -d ffi.enable=true -f vendor/kingbes/phpc/tests/run_examples.php

# Run specific example number
PHPC_ONLY=16 php -d ffi.enable=true -f vendor/kingbes/phpc/tests/run_examples.php
```

## Architecture

- Namespace: `Yangweijie\Ui3` → `src/`
- Core dependency: `kingbes/phpc` (safe FFI wrapper with RAII, whitelist loading, bounds checks)
- Platform GUI: Win32 (`user32.dll`), macOS Cocoa (`libobjc.dylib`), Linux X11/GTK

## Conventions

- Strict types: `declare(strict_types=1);`
- All FFI calls go through `SafeCall::invoke()` — never raw FFI calls
- Library loading requires whitelist: `Library::permit()` before `Library::load()`
- GUI examples use `PHPC_GUI_AUTO_EXIT=1` for CI (skips blocking dialogs)

## Agent Gotchas

- `src/` is empty — this is a greenfield project, not an existing codebase to modify
- No test framework configured yet (phpunit/phpstan absent)
- No CI workflows exist
- FFI crashes are process-level — always wrap in try/catch with `SafetyException`
