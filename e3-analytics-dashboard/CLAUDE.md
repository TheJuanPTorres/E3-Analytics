# Project: E3 Analytics Dashboard

## Stack
- PHP dashboard application
- MySQL/MariaDB backend

## Token Efficiency Rules

### RTK (installed globally)
- RTK hook auto-rewrites Bash commands. All git, test, build, ls, grep commands go through rtk automatically.
- For file reading, use shell commands (cat/head/tail) instead of Read tool when possible — RTK compresses them.
- For search, use rg/grep via shell instead of Grep tool — RTK groups matches by file.

### Caveman (installed globally via plugin)
- Activated by default from session start (`/caveman` to toggle).
- Be concise: lead with answer, use fragments, drop filler.
- Never compromise code, commands, or error messages.
- Use `/caveman-stats` to track savings.

### Efficient Workflow
- Prefer shell commands (`git status`, `cat file.php`, `rg pattern`) over built-in tools when RTK can compress.
- Run `/compact` when conversation gets long instead of starting fresh.
- Keep this CLAUDE.md lean — every line costs tokens.
