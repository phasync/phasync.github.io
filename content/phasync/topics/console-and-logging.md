---
title: "Console and logging"
summary: "Styled terminal output and a PSR-3 logger on top of it."
order: 11
status: draft
---

[[Console]] writes styled, width-aware terminal output (columns, padding, colour) without blocking the other coroutines while the terminal catches up. [[ConsoleLogger]] is a PSR-3 `LoggerInterface` built on it, for application code that logs through the standard interface rather than calling `Console` directly.
