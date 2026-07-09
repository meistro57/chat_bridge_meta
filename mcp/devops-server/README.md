# Chat Bridge DevOps MCP Server

A local stdio MCP server that gives an MCP client (Claude Desktop, Claude Code,
Crush) real CLI control over this repo: tests, Pint, git introspection, log
tailing, queue status, health checks, and a guarded `artisan` runner.

This is separate from the app's own `/api/mcp` endpoint (see `MCP.md`), which
exposes read-only conversation-data tools to AI personas *inside* Chat Bridge.
This one is for a coding assistant working *on* Chat Bridge.

## Tools

| Tool | What it does | Risk |
|---|---|---|
| `project_info` | branch, last commit, dirty file count, php/artisan version | read-only |
| `health_check` | hits `/api/ready` | read-only |
| `git_status` / `git_diff` / `git_log` | standard git introspection | read-only |
| `run_tests` | `php artisan test --compact`, optional `--filter`/path | read-only |
| `pint_check` | `vendor/bin/pint --test --dirty` | read-only |
| `pint_fix` | `vendor/bin/pint --dirty` | writes formatting only |
| `tail_log` | tails a file in `storage/logs/` | read-only |
| `queue_failed` | `php artisan queue:failed` | read-only |
| `artisan_list` | lists all artisan commands as JSON | read-only |
| `artisan_run` | runs any artisan command, guarded (see below) | varies |

**`artisan_run` guardrails** — this is the only tool that can meaningfully
change things, so it's the one with limits:

- **Blocked outright**: `migrate:fresh`, `migrate:reset`, `db:wipe`,
  `key:generate`, `tinker`. These never run through this server no matter what.
- **Requires `confirm=True`**: `migrate`, `migrate:rollback`, `queue:restart`,
  `horizon:terminate`, `down`, `up`, `optimize`.
- Everything else (`route:list`, `config:clear`, `cache:clear`,
  `migrate:status`, `about`, `schedule:list`, ...) runs immediately.

## Setup

```bash
cd ~/cb_dev/chat_bridge/mcp/devops-server
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
```

Sanity check it starts (Ctrl+C to stop — it just waits on stdio):

```bash
python server.py
```

## Wiring into Claude Desktop

Add an entry to your `claude_desktop_config.json`. Since your other WSL-backed
servers (Filesystem, frontpocket, qdrant, redis) are already reaching into
`\\wsl.localhost\Ubuntu\...`, mirror however those are invoking WSL — this is
the plain form if you're launching straight from within WSL:

```json
{
  "mcpServers": {
    "chat-bridge-devops": {
      "command": "/home/mark/cb_dev/chat_bridge/mcp/devops-server/venv/bin/python",
      "args": ["/home/mark/cb_dev/chat_bridge/mcp/devops-server/server.py"],
      "env": {
        "CHATBRIDGE_ROOT": "/home/mark/cb_dev/chat_bridge"
      }
    }
  }
}
```

If Claude Desktop is running on Windows and needs to reach into WSL, wrap it:

```json
{
  "mcpServers": {
    "chat-bridge-devops": {
      "command": "wsl.exe",
      "args": [
        "-e",
        "/home/mark/cb_dev/chat_bridge/mcp/devops-server/venv/bin/python",
        "/home/mark/cb_dev/chat_bridge/mcp/devops-server/server.py"
      ]
    }
  }
}
```

Restart Claude Desktop after editing the config. The tools will show up
prefixed with `chat-bridge-devops:` alongside your other connected servers.

## Notes

- `PHP_BIN` and `CHATBRIDGE_ROOT` env vars override the defaults if your path
  or PHP binary ever changes.
- Output is capped at ~20k chars per call and truncated with a marker so a
  runaway log or test dump can't blow up the context window.
- No raw shell-exec tool on purpose — every capability is a named, scoped
  function. If you want another one (composer, docker compose, horizon
  status), it's a 10-line addition, ask and I'll add it.
