---
title: "Production"
summary: "Sizing, reloads, logging and shutdown."
order: 10
status: draft
---

`--workers`, `--max-memory` and `--max-requests` size and recycle worker processes; `--watchdog` replaces one whose event loop has stopped turning. A reload (`SIGHUP`) restarts every worker on the current code without dropping the process; a stop drains requests within `--grace` seconds. See [Production](/guides/production/) for the defaults and what each one is for.
