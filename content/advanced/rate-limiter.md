---
title: "RateLimiter"
description: "Limit how often something happens."
status: draft
order: 4
see_also:
  - RateLimiter
project: phasync
topic: coordination-and-limits
role: guide
---

`phasync\Util\RateLimiter` lets events pass at a fixed rate, shared by every coroutine that calls `wait()`. The constructor starts a coroutine that issues one permit every `1 / $eventsPerSecond` seconds; `wait()` takes one, waiting if none is available yet. The first `wait()` always returns at once.

```php
phasync::run(function () {
    $limiter = new phasync\Util\RateLimiter(20); // at most 20 calls a second, shared by every caller
    $start   = microtime(true);

    foreach (['a', 'b', 'c'] as $worker) {
        phasync::go(function () use ($limiter, $worker, $start) {
            for ($i = 1; $i <= 2; ++$i) {
                $limiter->wait();
                printf("%s: call %d at %.2f\n", $worker, $i, microtime(true) - $start);
            }
        });
    }
});
// a: call 1 at 0.00
// b: call 1 at 0.05
// c: call 1 at 0.10
// a: call 2 at 0.15
// b: call 2 at 0.20
// c: call 2 at 0.25
```

Six calls from three coroutines, one permit every 0.05 s: the limiter does not care which coroutine is asking, only that permits go out at the rate you gave it. This is the case a single coroutine looping over `sleep()` can't cover by itself: limit calls to one API from everywhere that calls it, by sharing one `RateLimiter` instance.

## Burst

A `$burst` above 0 lets permits accumulate while nobody waits, so a limiter that has been idle lets several calls through at once:

```php
phasync::run(function () {
    $limiter = new phasync\Util\RateLimiter(10, burst: 3);
    phasync::sleep(0.5);                // nobody waits: permits accumulate, up to the burst

    $start  = microtime(true);
    $passed = 0;
    while ($limiter->isReady()) {       // the burst passes at once
        $limiter->wait();
        ++$passed;
    }
    printf("%d passed at once\n", $passed);
    printf("without waiting: %s\n", microtime(true) - $start < 0.05 ? 'yes' : 'no');
});
// 4 passed at once
// without waiting: yes
```

`$burst + 1` is the most that can ever pass at once: the one permit that was already waiting, plus up to `$burst` saved up. `isReady()` checks for a permit without taking it or waiting for one.

## Timeouts

`wait()` waits forever; `await(float $timeout)` throws `TimeoutException` if no permit comes in time:

```php
phasync::run(function () {
    $limiter = new phasync\Util\RateLimiter(2); // one permit every 0.5 s
    $limiter->wait();                           // takes the immediate first permit

    try {
        $limiter->await(0.1);                   // the next permit is about 0.5 s away
    } catch (phasync\TimeoutException) {
        echo "no permit within 0.1 s\n";
    }
});
// no permit within 0.1 s
```

The limiter's own coroutine ends when the limiter is dropped: nothing to stop or close.
