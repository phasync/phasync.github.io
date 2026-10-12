---
title: "The event loop"
summary: "The loop beneath the coroutines, for library authors."
order: 13
status: draft
---

Every coroutine runs on one event loop per process. Application code rarely touches it: `run()`, `go()` and `await()` are enough. A library that integrates its own I/O or timers needs more: [[phasync::getLoop]] returns the loop, [[PollerInterface]] is what waits for streams and sockets, [[phasync::service]] starts a background coroutine that does not keep the program alive, and [[phasync::isRunning]] and [[phasync::getFiber]] tell the library whether it is inside a coroutine and which one.
