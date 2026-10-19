---
title: "Design"
description: "Why phasync and Swerve are built the way they are."
status: draft
order: 9
project: phasync
topic: coroutines
role: concept
---

phasync's own contract, `docs/SEMANTICS.md`, opens with what the library is for: "an async IO and concurrency library for one PHP process." Everything it keeps or refuses follows from reading a coroutine as if it were synchronous code, not from adding features because they were possible.

## What stays in core

In scope: the scheduler, coroutines, frames and scopes, cancellation, timeouts, channels, `WaitGroup`, `RateLimiter`, flags, non-blocking stream I/O, `Process` pipes, and `StringBuffer`. Out of scope, left to other packages: worker pools and cross-process proxies, serialization, PSR-7 implementations, FastCGI and WebSocket protocol code, database drivers, HTTP servers — that is what Swerve, the PSR-7 layer and the adapters are for. `StringBuffer` looks like an exception (a byte buffer belongs in a protocol library, not a concurrency one) but it stays, on purpose: it is fast enough for protocol code to build on directly, and a decoupled package could not take the same shortcuts without losing that.

## The principles behind it

- **A coroutine reads top to bottom.** Suspension is hidden inside blocking-looking calls (`sleep()`, `await()`, I/O, channel operations); nothing in application code mentions the event loop.
- **Higher-level tools share one mechanism.** `WaitGroup`, `RateLimiter` and the channels all suspend and wake through the same flag primitive, so cancellation and exceptions propagate the same way everywhere, instead of each tool reinventing its own wait.
- **Refcounting does the common case; cycle collection is a safety net, not a guarantee to design around.** A channel end's destructor runs the instant the last reference drops; cyclic garbage is freed only when the loop has a spare moment, so correct behaviour never depends on exactly when that happens.
- **A loop beats a callback.** One coroutine with a loop is easier to read and reason about than a callback registered per event.
- **No global side effects.** phasync does not change process-wide PHP state (GC, error handlers, ini settings) without restoring it.
- **Deadlocks are prevented where that is free, detected exactly where it is possible, and otherwise left to the developer.** Never guessed with a timer.
- **Simple over clever.** Native PHP resources (streams, fibers) are used directly, not wrapped in an abstraction layer for cases nobody has yet.

## A decision made by this rule: `select()` is gone

2.0.0 removed `phasync::select()` and the `Selector` machinery behind it, rather than fix the real bugs an audit found in it (a race between detecting readiness and acting on it; a wrong branch; leaked helper coroutines). The fix was designed and would have worked. It shipped anyway as a removal, because `select()` is the one primitive that asks the developer to think about racing, per-candidate timeouts and cancelling losers — exactly the async-aware reasoning the rest of phasync exists to make unnecessary. Checked against real usage before the decision: phasync's own README never gave it a section the way `Channel` and `WaitGroup` got one, Swerve never called it, and the one real consumer only ever needed "wait across a few raw sockets," which is the same pattern this section warns about from the other side. The idiomatic replacement, used throughout this site, is a coroutine per source, each with its own read loop, letting the scheduler interleave them — see [Channels](/advanced/channels/#no-select):

```php
// One coroutine per source, instead of racing both with a removed select()
phasync::go(function () use ($a) {
    phasync::readable($a, 1.0);
    echo fread($a, 100), "\n";
});
phasync::go(function () use ($b) {
    phasync::readable($b, 1.0);
    echo fread($b, 100), "\n";
});
```

`SelectableInterface` itself (`isReady()`/`await()`) was never coupled to `select()` and stays: `Channel`, `StringBuffer`, `WaitGroup` and `RateLimiter` all implement it directly.

## Why no dependencies

Beyond PHP itself, PSR interfaces and `fiber-interop/scope`, phasync, the extension and Swerve depend only on each other. That is a deliberate trade against convenience: no dependency tree to audit, no upstream release to wait for, and no third-party churn an application did not choose. It is also what makes "read it whole" realistic: phasync's `src/` is about 12,900 lines of PHP, Swerve's about 9,000 — a size a developer, or a coding agent, can read in full and change with confidence, which is the point of owning the code at all instead of depending on it. MIT licensed, including the extension.
