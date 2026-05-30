# agykit — Antigravity (agy) CLI for this project

agykit manages agy multi-account, model selection, and quota-aware runs.
Tool: https://github.com/bmdersleri/agykit

## Setup (once per machine)
```bash
git clone https://github.com/bmdersleri/agykit.git ~/projects/agykit
cd ~/projects/agykit && ./install.sh
```

## This project's config (.agykit.conf, gitignored)
```
AGYKIT_VERIFY="composer lint && composer test"
```

## Usage
```bash
agykit whoami                  # active Google account
agykit account-list            # saved accounts (3 shared across all projects)
agykit switch <email>          # switch account
agykit model "Gemini 3.1 Pro (High)"   # change model+effort
agykit run "explain this code"         # quota-aware run (auto-rotate accounts)
agykit do-escalate "implement X"       # model ladder Flash→Pro→Opus on verify fail
```

Accounts and model state are shared globally (`~/.gemini/`). Quota on one
account auto-rotates to the next. Set `AGYKIT_TERSE=ultra` for terse output.
