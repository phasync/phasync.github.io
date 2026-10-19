---
title: "WaitGroup"
description: "Wait for a group of coroutines."
status: draft
order: 3
see_also:
  - WaitGroup
project: phasync
topic: coordination-and-limits
role: guide
---

Waiting for a batch of coroutines with `phasync::await()` in a loop needs a list of `Fiber` handles. That's awkward when you don't keep one, because the coroutines report their own completion instead: a batch of downloads, each `finally`-ing its own cleanup. `phasync\Util\WaitGroup` counts instead: `add()` before starting a piece of work, `done()` when it ends, `await()` to wait until the count is back to zero.

```php
phasync::run(function () {
    $group = new phasync\Util\WaitGroup();

    foreach (['small.txt' => 0.03, 'large.iso' => 0.09, 'medium.zip' => 0.06] as $file => $seconds) {
        $group->add();
        phasync::go(function () use ($group, $file, $seconds) {
            try {
                phasync::sleep($seconds);   // stands in for the download
                echo "downloaded $file\n";
            } finally {
                $group->done();             // also when the download fails
            }
        });
    }

    $group->await();
    echo "all three done\n";
});
// downloaded small.txt
// downloaded medium.zip
// downloaded large.iso
// all three done
```

The waiting coroutine never holds a `Fiber`, only the count. `done()` runs in `finally`, so a failed download still counts: `WaitGroup` tracks whether the work ended, not whether it succeeded. A failure nobody awaits still reaches the frame on its own, see [Coroutines](/advanced/coroutines/).

## Counting a batch at once

`add(int $delta = 1)` takes a size, so a batch whose length you already know needs one call, not one per item:

```php
$jobs  = ['resize', 'upload', 'notify'];
$group = new phasync\Util\WaitGroup();
$group->add(count($jobs));   // the whole batch at once, before any job can finish

foreach ($jobs as $i => $job) {
    phasync::go(function () use ($group, $job, $i) {
        phasync::sleep(0.01 * (3 - $i));
        echo "$job done\n";
        $group->done();
    });
}

$group->await();
echo "batch done\n";
```

`done()` without a matching `add()` throws `LogicException`: the counter cannot go negative.

## Timeouts, and reuse

`await(float $timeout = PHP_FLOAT_MAX)` throws `TimeoutException` if the counter isn't zero in time, and returns at once if it already is:

```php
$group = new phasync\Util\WaitGroup();
$group->add();
phasync::go(function () use ($group) {
    phasync::sleep(0.2);
    $group->done();
});

try {
    $group->await(0.05);
} catch (phasync\TimeoutException) {
    echo "not done within 0.05 s\n";
}

$group->await();             // no limit
echo "done\n";

$group->await();             // the counter is zero: returns at once
echo "still done\n";
```

The counter may rise again after it reaches zero, so one `WaitGroup` serves more than one batch, one after another, instead of being replaced each time.
