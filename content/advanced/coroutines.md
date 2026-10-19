---
title: "Coroutines"
description: "How coroutines, frames and cancellation work."
status: draft
order: 1
see_also:
  - phasync::go
  - phasync::cancel
  - phasync\Frame
project: phasync
topic: coroutines
role: concept
---

A coroutine is a PHP `Fiber`, started and scheduled by phasync's event loop. `phasync::go()` starts one; the code inside it reads top to bottom, and a wait (`phasync::sleep()`, a stream, a channel) suspends only that coroutine, letting the loop run another one.

```php
phasync::run(function () {
    phasync::go(function () {
        echo "a\n";
        phasync::sleep();     // yields: b gets to run
        echo "c\n";
    });
    phasync::go(function () {
        echo "b\n";
    });
});
// a, b, c
```

## Frames

`phasync::run()` creates a frame: it returns only once every coroutine started inside it (directly, or by `go()`) has ended. `go()` always joins the frame of the coroutine that calls it; only `run()` (and `phasync::service()`, below) start a coroutine outside the current frame. A `run()` called inside a coroutine nests its frame in the caller's, runs its callback inline (no new coroutine), and still waits for everything it started before returning.

A coroutine that fails and is never awaited is not silently dropped: once every coroutine of the frame has ended, `run()` throws it. Several unawaited failures come back together, in a `phasync\AggregateException`.

## Cancellation

`phasync::cancel($fiber)` throws `CancelledException` into that coroutine at its current or next suspension point; it never throws in the caller, and cancelling an already-finished coroutine is a no-op. A coroutine that catches the exception and keeps waiting gets cancelled again at that next wait: cancellation is sticky until the coroutine ends.

```php
$worker = phasync::go(function () {
    try {
        for ($i = 1; ; ++$i) {
            echo "tick $i\n";
            phasync::sleep(0.03);
        }
    } finally {
        echo "worker cleans up\n";   // finally always runs, cancellation included
    }
});

phasync::sleep(0.075);
phasync::cancel($worker, 'no longer needed');
```

`phasync\Frame::cancel()` cancels every coroutine of the frame, and of the frames nested in it, deepest first. `Frame::requestCancellation()` only sets a flag (`Frame::isCancellationRequested()`), for code that wants to end itself cleanly instead of being interrupted mid-wait.

A `finally` block's own waits are shielded from the cancellation that triggered it, so cleanup that needs I/O can finish; `phasync::shielded()` does the same for cleanup outside a `finally`.

## `phasync::service()`

A coroutine started with `go()` lives only as long as its frame. `phasync::service()` starts one that outlives it: no `run()` waits for it, and it keeps running until the outermost `run()` has no coroutines of its own left, at which point phasync cancels every remaining service and waits for it to end. Use it for work that must survive the request that started it, such as a Swerve worker's background subscribers.

## What `throw()` is for

`phasync::throw($fiber, $exception)` delivers an arbitrary exception into a suspended coroutine, the same mechanism `cancel()` uses to deliver a `CancelledException`. Most code reaches for `cancel()`; `throw()` is there for the cases that need a different exception.
