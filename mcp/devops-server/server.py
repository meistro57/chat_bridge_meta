# filename: server.py
"""
Chat Bridge DevOps MCP Server
------------------------------
Gives an MCP client (Claude Desktop, Claude Code, Crush, etc.) real CLI control
over the Chat Bridge Laravel project: tests, code style, git introspection,
log tailing, queue/health checks, and a guarded artisan runner.

This is intentionally NOT a raw shell-exec tool. Every capability is a named,
scoped tool. A short blocklist stops the truly destructive artisan commands
outright, and a confirm-list requires an explicit confirm=True for anything
schema- or availability-affecting. Everything else runs freely — this is a
local, single-user dev box, not a multi-tenant service.

Run with:
    python server.py

Requires:
    pip install "mcp[cli]"
"""

from __future__ import annotations

import json
import os
import subprocess
import urllib.request
import urllib.error
from typing import Optional

from mcp.server.fastmcp import FastMCP

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

PROJECT_ROOT = os.environ.get(
    "CHATBRIDGE_ROOT",
    "/home/mark/cb_dev/chat_bridge",
)

# This project has no host-side vendor/ — the Dockerfile bakes it into the
# image and docker-compose.yml only bind-mounts storage/app/config/etc, not
# vendor. Every artisan/pint/test call has to go through `docker compose exec`,
# not a bare host `php` binary. Set CHATBRIDGE_EXEC_MODE=host only if you move
# to a checkout that actually has `composer install` run on the host.
EXEC_MODE = os.environ.get("CHATBRIDGE_EXEC_MODE", "docker")  # "docker" | "host"
DOCKER_SERVICE = os.environ.get("CHATBRIDGE_DOCKER_SERVICE", "app")

PHP_BIN = os.environ.get("PHP_BIN", "php")
DEFAULT_TIMEOUT = 60
TEST_TIMEOUT = 300
MAX_OUTPUT_CHARS = 20_000


def _app_port() -> str:
    """Read APP_PORT out of .env so health_check doesn't have to guess.
    Falls back to 8000 if .env is missing or unset."""
    env_path = os.path.join(PROJECT_ROOT, ".env")
    try:
        with open(env_path) as f:
            for line in f:
                if line.startswith("APP_PORT="):
                    return line.split("=", 1)[1].strip()
    except FileNotFoundError:
        pass
    return "8000"

# Artisan commands that must never run through this server.
BLOCKED_ARTISAN = {
    "migrate:fresh",
    "migrate:reset",
    "db:wipe",
    "key:generate",
    "tinker",
}

# Artisan commands that run only when confirm=True is passed explicitly.
CONFIRM_ARTISAN = {
    "migrate",
    "migrate:rollback",
    "queue:restart",
    "horizon:terminate",
    "down",
    "up",
    "optimize",
}

mcp = FastMCP("chat-bridge-devops")


# ---------------------------------------------------------------------------
# Internal helpers
# ---------------------------------------------------------------------------

def _run(cmd: list[str], cwd: str = PROJECT_ROOT, timeout: int = DEFAULT_TIMEOUT) -> dict:
    """Run a command and return a structured result. Never raises on non-zero exit."""
    try:
        proc = subprocess.run(
            cmd,
            cwd=cwd,
            capture_output=True,
            text=True,
            timeout=timeout,
        )
        stdout = proc.stdout or ""
        stderr = proc.stderr or ""
        truncated = False
        if len(stdout) > MAX_OUTPUT_CHARS:
            stdout = stdout[:MAX_OUTPUT_CHARS] + "\n...[truncated]"
            truncated = True
        if len(stderr) > MAX_OUTPUT_CHARS:
            stderr = stderr[:MAX_OUTPUT_CHARS] + "\n...[truncated]"
            truncated = True
        return {
            "command": " ".join(cmd),
            "exit_code": proc.returncode,
            "stdout": stdout,
            "stderr": stderr,
            "truncated": truncated,
        }
    except FileNotFoundError as e:
        return {"command": " ".join(cmd), "exit_code": -1, "stdout": "", "stderr": f"binary not found: {e}", "truncated": False}
    except subprocess.TimeoutExpired:
        return {"command": " ".join(cmd), "exit_code": -1, "stdout": "", "stderr": f"timed out after {timeout}s", "truncated": False}


def _docker_exec(args: list[str], timeout: int = DEFAULT_TIMEOUT) -> dict:
    """Run a command inside the running app container via docker compose exec.
    -T disables TTY allocation so this works non-interactively."""
    return _run(["docker", "compose", "exec", "-T", DOCKER_SERVICE, *args], timeout=timeout)


def _artisan(args: list[str], timeout: int = DEFAULT_TIMEOUT) -> dict:
    if EXEC_MODE == "docker":
        return _docker_exec(["php", "artisan", *args], timeout=timeout)
    return _run([PHP_BIN, "artisan", *args], timeout=timeout)


def _pint(args: list[str], timeout: int = DEFAULT_TIMEOUT) -> dict:
    if EXEC_MODE == "docker":
        return _docker_exec(["vendor/bin/pint", *args], timeout=timeout)
    return _run(["vendor/bin/pint", *args], timeout=timeout)


def _fmt(result: dict) -> str:
    return json.dumps(result, indent=2)


# ---------------------------------------------------------------------------
# Tools: project / health
# ---------------------------------------------------------------------------

@mcp.tool()
def project_info() -> str:
    """Quick snapshot of project state: current git branch, last commit,
    working-tree dirty status, and PHP/artisan version. Read-only."""
    branch = _run(["git", "rev-parse", "--abbrev-ref", "HEAD"])
    last_commit = _run(["git", "log", "-1", "--pretty=%h %ad %s", "--date=short"])
    dirty = _run(["git", "status", "--porcelain"])
    if EXEC_MODE == "docker":
        php_version = _docker_exec(["php", "-v"])
    else:
        php_version = _run([PHP_BIN, "-v"])
    artisan_version = _artisan(["--version"])
    return _fmt({
        "exec_mode": EXEC_MODE,
        "branch": branch["stdout"].strip(),
        "last_commit": last_commit["stdout"].strip(),
        "dirty_files": len([l for l in dirty["stdout"].splitlines() if l.strip()]),
        "php_version": (php_version["stdout"].splitlines()[0] if php_version["stdout"] else php_version["stderr"].strip()),
        "artisan_version": (artisan_version["stdout"].strip() or artisan_version["stderr"].strip()),
    })


@mcp.tool()
def docker_status() -> str:
    """Show docker compose container status (docker compose ps). Read-only.
    Check this first if artisan/pint/test tools fail — they all run inside
    the 'app' container, so if it's not up they'll all fail the same way."""
    return _fmt(_run(["docker", "compose", "ps"]))


@mcp.tool()
def health_check(base_url: str = "") -> str:
    """Hit the app's /api/ready endpoint and report database/cache health.
    If base_url is left blank, reads APP_PORT out of .env (this project
    defaults to a non-standard port, not 8000)."""
    if not base_url:
        base_url = f"http://localhost:{_app_port()}"
    url = base_url.rstrip("/") + "/api/ready"
    try:
        with urllib.request.urlopen(url, timeout=10) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            return _fmt({"url": url, "status": resp.status, "body": json.loads(body)})
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        return _fmt({"url": url, "status": e.code, "body": body})
    except Exception as e:
        return _fmt({"url": url, "error": str(e)})


# ---------------------------------------------------------------------------
# Tools: git
# ---------------------------------------------------------------------------

@mcp.tool()
def git_status() -> str:
    """Show working tree status (git status --porcelain=v1, human-friendly)."""
    return _fmt(_run(["git", "status"]))


@mcp.tool()
def git_diff(path: str = "", staged: bool = False) -> str:
    """Show a diff. Optionally scoped to a path, optionally staged-only."""
    cmd = ["git", "diff"]
    if staged:
        cmd.append("--staged")
    if path:
        cmd.extend(["--", path])
    return _fmt(_run(cmd))


@mcp.tool()
def git_log(count: int = 10) -> str:
    """Show the last N commits (default 10), one line each."""
    count = max(1, min(count, 100))
    return _fmt(_run(["git", "log", f"-{count}", "--pretty=%h  %ad  %an  %s", "--date=short"]))


# ---------------------------------------------------------------------------
# Tools: tests & code style
# ---------------------------------------------------------------------------

@mcp.tool()
def run_tests(filter: str = "", path: str = "") -> str:
    """Run the PHPUnit suite via `php artisan test --compact`.
    Optionally scope by --filter=<name> or a specific test file/path."""
    args = ["test", "--compact"]
    if filter:
        args.append(f"--filter={filter}")
    if path:
        args.append(path)
    return _fmt(_artisan(args, timeout=TEST_TIMEOUT))


@mcp.tool()
def pint_check(dirty: bool = True) -> str:
    """Check code style with Laravel Pint. dirty=True (default) only checks
    files changed vs git HEAD, matching this project's CLAUDE.md convention.
    This is a dry run (--test); use pint_fix to actually apply fixes."""
    args = ["--test"]
    if dirty:
        args.append("--dirty")
    return _fmt(_pint(args))


@mcp.tool()
def pint_fix(dirty: bool = True) -> str:
    """Apply Laravel Pint code style fixes. dirty=True (default) only touches
    files changed vs git HEAD."""
    args = []
    if dirty:
        args.append("--dirty")
    return _fmt(_pint(args))


# ---------------------------------------------------------------------------
# Tools: logs & queue
# ---------------------------------------------------------------------------

@mcp.tool()
def tail_log(lines: int = 100, file: str = "laravel.log") -> str:
    """Tail a storage/logs file (default laravel.log). Rejects any path
    outside storage/logs to prevent reading arbitrary files."""
    if "/" in file or ".." in file:
        return _fmt({"error": "file must be a bare filename inside storage/logs"})
    lines = max(1, min(lines, 2000))
    log_path = os.path.join(PROJECT_ROOT, "storage", "logs", file)
    if not os.path.isfile(log_path):
        return _fmt({"error": f"no such log file: {file}"})
    return _fmt(_run(["tail", "-n", str(lines), log_path]))


@mcp.tool()
def queue_failed() -> str:
    """List failed queue jobs (php artisan queue:failed). Read-only."""
    return _fmt(_artisan(["queue:failed"]))


# ---------------------------------------------------------------------------
# Tools: artisan (guarded)
# ---------------------------------------------------------------------------

@mcp.tool()
def artisan_list() -> str:
    """List all available artisan commands with descriptions. Read-only."""
    return _fmt(_artisan(["list", "--format=json"]))


@mcp.tool()
def artisan_run(command: str, args: str = "", confirm: bool = False) -> str:
    """Run an artisan command.

    Blocked outright (never runs): migrate:fresh, migrate:reset, db:wipe,
    key:generate, tinker.

    Requires confirm=True: migrate, migrate:rollback, queue:restart,
    horizon:terminate, down, up, optimize.

    Everything else (route:list, config:clear, cache:clear, migrate:status,
    about, schedule:list, etc.) runs immediately.

    `args` is a raw string of additional arguments/options, e.g. "--seed"
    or "--path=database/migrations/foo.php".
    """
    if command in BLOCKED_ARTISAN:
        return _fmt({
            "error": f"'{command}' is blocked on this server — too destructive to run unattended.",
            "hint": "Run it yourself in a terminal if you really mean it.",
        })
    if command in CONFIRM_ARTISAN and not confirm:
        return _fmt({
            "error": f"'{command}' requires confirm=True — it changes schema, availability, or running workers.",
        })
    full_args = [command, *args.split()] if args else [command]
    return _fmt(_artisan(full_args, timeout=TEST_TIMEOUT if command == "test" else DEFAULT_TIMEOUT))


# ---------------------------------------------------------------------------
# Entrypoint
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    mcp.run()
