---
title: "Channels"
description: "Pass values between coroutines."
status: draft
order: 2
see_also:
  - phasync::channel
  - phasync\ReadChannelInterface
  - phasync\WriteChannelInterface
project: phasync
topic: channels-and-messaging
role: guide
---

`phasync::channel()` makes a pair: a `ReadChannelInterface` and a `WriteChannelInterface`, connected to each other. Any PHP value can be written; nothing is serialized, because both ends live in the same process.

```php
phasync::run(function () {
    phasync::channel($numbers, $numbersIn, 2);   // capacity 2
    phasync::channel($squares, $squaresIn, 2);

    phasync::go(function () use ($numbersIn) {
        foreach (range(1, 5) as $n) {
            $numbersIn->write($n);
        }
        $numbersIn->close();
    });

    phasync::go(function () use ($numbers, $squaresIn) {
        foreach ($numbers as $n) {
            $squaresIn->write($n * $n);          // 1 4 9 16 25
        }
        $squaresIn->close();
    });

    foreach ($squares as $square) {              // ends when the second stage closes
        echo $square, "\n";
    }
});
```

## Buffered and unbuffered

The third argument to `channel()` is the capacity. 0 (the default) is unbuffered: `write()` returns only once a reader has received the value, a rendezvous. A capacity above 0 lets that many values queue; `write()` blocks only once the queue is full.

## Delivery

Each value goes to exactly one reader, in the order it was written. When several coroutines read the same channel, as in a worker pool, a value goes to whichever is waiting:

```php
phasync::run(function () {
    phasync::channel($jobs, $submit);
    $done = [];

    foreach (['w1', 'w2', 'w3'] as $worker) {
        phasync::go(function () use ($jobs, $worker, &$done) {
            foreach ($jobs as $job) {
                $done[$worker][] = $job;         // each job lands on exactly one worker
            }
        });
    }
    foreach (range(1, 9) as $job) {
        $submit->write($job);
    }
    $submit->close();
});
```

If every reader should see every value instead, that is pub/sub, not a channel: see `phasync::publisher()` and `SubscriberInterface`.

## Closing

Closing either end closes the channel; so does dropping the last reference to either end. After close, buffered values already written are still delivered; once the channel is empty, `read()` and `foreach` end instead of blocking. Writing to a closed channel throws `ChannelException`, including for a writer that was blocked at the moment of close.

## No `select()`

phasync has no `select()`: waiting across several channels or streams at once, racing them and cancelling the losers, was removed in 2.0 because it needs async-aware reasoning that phasync exists to avoid. The idiomatic replacement is the pattern above: a coroutine per source, each with its own simple read loop, and the scheduler interleaves them. `phasync::await()` does accept a channel end (or anything implementing `SelectableInterface`) directly, with a timeout, when you only need to wait for one.
