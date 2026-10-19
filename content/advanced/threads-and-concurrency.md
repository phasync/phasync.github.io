---
title: "Threads and concurrency"
description: "What runs at the same time, and what only looks like it."
status: draft
order: 7
project: phasync
topic: coroutines
role: concept
---

phasync has no threads. Everything inside one `phasync::run()` is one PHP process, running one coroutine at a time: real parallelism comes from running several such processes, each with its own event loop, not from anything inside one of them running simultaneously.

```php
phasync::run(function () {
    phasync::go(function () {
        echo "a starts\n";
        phasync::sleep(0.02);
        echo "a resumes\n";
    });
    phasync::go(function () {
        echo "b starts\n";
        phasync::sleep(0.01);
        echo "b resumes\n";
    });
});
// a starts
// b starts
// b resumes
// a resumes
```

`a` and `b` never run at the same instant. `a` runs until it suspends on `sleep(0.02)`, then control passes to `b`, which suspends on its own, shorter `sleep()`; the loop resumes whichever timer is due first. This is why phasync code needs no locks around shared PHP variables: between two suspension points, a sequence of statements cannot be interleaved by another coroutine of the same scope, so test-and-set or compare-and-swap logic in plain PHP is already correct. The scheduling is cooperative: a coroutine keeps running until it suspends, at a `sleep()`, an `await()`, a stream wait or a channel operation, never in the middle of a statement, so code that looks sequential behaves sequentially unless it says otherwise.

## Parallelism is processes, not fibers

A coroutine is a PHP `Fiber`; several of them share one process, one PHP memory space, one core. To use more than one core, run more than one process: under Swerve, one worker process per CPU core by default, each with its own event loop and its own coroutines, sharing nothing. Two workers running at once is real parallelism; the coroutines inside one worker are concurrency, taking turns.

With the phasync extension loaded, a coroutine that runs too long without suspending is preempted between two iterations of its own PHP loop (every `PREEMPT_INTERVAL`, 20 ms by default), so one busy coroutine does not starve the others of a worker. This only switches which coroutine runs next; it still never runs two at once. Without the extension there is no preemption at all: a coroutine that never suspends blocks every other coroutine of its process until it returns.

## The rule: never block a worker

A worker that blocks (waits without going through phasync) stops every coroutine in it, not only the one that is waiting. Without the phasync extension, this includes `sleep()`, database queries over PDO or mysqli, `curl_exec()`, `file_get_contents('https://...')`, and any CPU-heavy loop:

```php
// Blocks the whole worker for 0.5 s: every other coroutine waits too
sleep(0.5);

// Lets the other coroutines run during the wait
phasync::sleep(0.5);
```

With the extension, most of that list (sockets and TLS, MySQL through PDO or mysqli, curl, files, DNS, `sleep()` itself) suspends the coroutine instead of the process, so plain `sleep()` becomes safe to call directly; see [The phasync extension](/library/phasync-ext/) for the full list and what stays blocking regardless (CPU-bound code, and clients with their own network code such as phpredis). A short blocking call is not fatal: it delays the other coroutines of that worker by its own duration, and that is additive, not per request. Fifty 1 ms queries in one request cost every other coroutine in the worker 50 ms.

## Letting others run in a tight loop

CPU-heavy code (a large loop, image processing) blocks the worker for as long as it runs, extension or not, because there is no I/O for phasync to suspend on. `phasync::yield()` gives the other coroutines a turn without waiting for anything:

```php
foreach ($rows as $row) {
    process($row);
    phasync::yield();   // let another coroutine's turn come, then continue here
}
```

Call it often enough that no single coroutine holds the worker for long, and rarely enough that the switching itself isn't the bottleneck.
