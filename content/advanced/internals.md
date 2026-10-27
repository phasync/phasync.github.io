---
title: "Internals"
description: "The event loop, the pollers and the scheduler."
status: draft
order: 8
project: phasync
topic: the-event-loop
role: concept
---

`phasync\EventLoop` is `@internal`: one instance drives every `phasync::run()` in a process, and its methods may change in any release, except the four documented for `phasync::getLoop()`: `getSlot()`, `park()`, `unpark()` and `tick()`. Application code does not need any of them — the rest of the `phasync` facade is the loop's API — but they exist for code that waits on its own terms: an adapter driving the loop in place from a `Fiber` phasync did not start, or a primitive that wants a lighter wait than a flag.

## How the loop picks the next coroutine

`go()` runs its closure immediately, synchronously, up to its first suspension, then returns control to the caller — a new coroutine is never merely queued the way a new goroutine is in Go:

```php
phasync::run(function () {
    echo "main starts\n";
    phasync::go(function () {
        echo "x runs to its first suspension immediately\n";
        phasync::sleep(0);
        echo "x resumes\n";
    });
    echo "main continues: go() already returned\n";
    phasync::go(function () {
        echo "y runs to its first suspension immediately\n";
        phasync::sleep(0);
        echo "y resumes\n";
    });
});
// main starts
// x runs to its first suspension immediately
// main continues: go() already returned
// y runs to its first suspension immediately
// x resumes
// y resumes
```

Once `main` ends, the loop runs what's left in its ready queue: a plain FIFO. `x` became runnable (its `sleep(0)` resolved) before `y` did, in the same tick, so `x` resumes first. Scheduling is cooperative throughout: a coroutine keeps the process until it suspends on a wait the loop understands (`sleep()`, `await()`, a stream, a channel, `awaitFlag()`), never in the middle of a statement.

## Waiting on streams: the poller

`readable()`, `writable()` and `stream()` suspend the calling coroutine, and hand the wait to whatever implements `phasync\PollerInterface` for this loop. The loop calls the poller's `poll()` when it has nothing else to run, and `poll()` resumes whichever waiters became ready. Without the phasync extension, that's `phasync\StreamSelectPoller`, built on `stream_select()`, with the same ceiling `stream_select()` has: it cannot wait on a file descriptor numbered 1024 (`FD_SETSIZE`) or higher. With the extension loaded, the loop's poller waits through the extension (`phasync\ext\Suspension`), which has no such ceiling — see [The phasync extension](/library/ext-phasync/) for what that changes in practice. One coroutine at a time may wait to read a given stream, and one to write; a second one waiting in a direction that's already taken gets `LogicException` immediately, not a wait that would silently steal data meant for the first.

```php
use phasync\PollerInterface;

phasync::run(function () {
    $poller = phasync::getLoop()->getPoller();
    echo 'implements PollerInterface: ', var_export($poller instanceof PollerInterface, true), "\n";

    [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($a, false);
    phasync::go(function () use ($b) {
        phasync::sleep(0.01);
        fwrite($b, 'ready');
    });

    $poller->readable($a, 1.0);   // parks until the loop's poll() finds $a readable
    echo fread($a, 100), "\n";
});
// implements PollerInterface: true
// ready
```

## Preemption

Without the extension, a coroutine that never suspends blocks every other coroutine of the process until it returns: there is no preemption at all. With it, a coroutine that runs through a whole `PREEMPT_INTERVAL` (20 ms by default) of a PHP loop is suspended between two iterations of that loop, so other coroutines get a turn. This only ever switches between coroutines of different scopes, and only between loop iterations, never inside one PHP statement, inside a C callback, or inside the loop's own code — so code within one scope still runs atomically between its own suspension points, exactly as without the extension. See [Threads and concurrency](/advanced/threads-and-concurrency/).

## Driving the loop yourself

`getSlot()`, `park()` and `unpark()` are a lighter wait than a flag, for code that owns both ends of it, such as a mailbox:

```php
final class Mailbox
{
    private ?int $slot = null;
    private mixed $value = null;

    public function receive(): mixed
    {
        $loop       = phasync::getLoop();
        $this->slot = $loop->getSlot();
        $loop->park($this->slot);

        return $this->value;
    }

    public function send(mixed $value): void
    {
        $this->value = $value;
        phasync::getLoop()->unpark($this->slot);
    }
}

phasync::run(function () {
    $mailbox = new Mailbox();
    phasync::go(function () use ($mailbox) {
        echo 'received: ', $mailbox->receive(), "\n";
    });
    $mailbox->send('hello');
});
// received: hello
```

Unlike a flag, nothing notices a slot its owner forgets to unpark: the parked coroutine waits until its timeout and no further. `tick()` is for the other direction — a `Fiber` phasync did not start, inside an adapter's own wait, driving the loop in place for one round and putting the running coroutine and its frame back when it returns.
