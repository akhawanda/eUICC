#!/usr/bin/env python3
"""
Patch Laravel 12's bootstrap/app.php to:
  1. Register routes/api.php with prefix 'api'
  2. Register the 'sim.token' middleware alias

Idempotent — safe to run multiple times.
"""
import re
import sys
from pathlib import Path

if len(sys.argv) != 2:
    sys.exit("usage: patch_bootstrap.py <path to bootstrap/app.php>")

path = Path(sys.argv[1])
src = path.read_text()

# ---- 1. add api route
if "api: __DIR__" not in src:
    src = re.sub(
        r"(web:\s*__DIR__\.'\/\.\./routes\/web\.php',)",
        r"\1\n        api: __DIR__.'/../routes/api.php',\n        apiPrefix: 'api',",
        src,
        count=1,
    )

# ---- 2. add middleware alias
if "'sim.token'" not in src:
    if "->withMiddleware(" in src and "->alias(" in src:
        src = re.sub(
            r"(->alias\(\s*\[)",
            r"\1\n            'sim.token' => \\App\\Http\\Middleware\\VerifySimulatorToken::class,",
            src,
            count=1,
        )
    else:
        # Inject a fresh ->withMiddleware block if Laravel scaffold didn't include one
        src = re.sub(
            r"(->withMiddleware\(\s*function\s*\(Middleware\s*\$middleware\)\s*\{)",
            r"\1\n        $middleware->alias(['sim.token' => \\App\\Http\\Middleware\\VerifySimulatorToken::class]);",
            src,
            count=1,
        )
        # If there was no withMiddleware block at all, add one before ->withExceptions
        if "'sim.token'" not in src:
            src = src.replace(
                "->withExceptions(",
                "->withMiddleware(function (Middleware $middleware) {\n"
                "        $middleware->alias(['sim.token' => \\App\\Http\\Middleware\\VerifySimulatorToken::class]);\n"
                "    })\n    ->withExceptions(",
                1,
            )

path.write_text(src)
print(f"patched {path}")
