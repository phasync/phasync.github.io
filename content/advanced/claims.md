---
title: "Claims"
description: "One holder at a time across all workers."
status: draft
order: 6
see_also:
  - Claim
  - Swerve::claim
project: swerve
topic: coordination-across-workers
role: concept
---

`Swerve::claim($name)` returns a handle on `$name`, a name one worker at a time can hold across the whole server. Nothing is claimed until `acquire()`; the claim is held until it is released, the handle is destroyed, or the worker that held it exits or dies.

```php
foreach (['a', 'b', 'c'] as $who) {
    phasync::go(function () use ($who) {
        if ($claim = Swerve::claim('nightly-report')->acquire()) {
            echo "$who runs the report\n";
            phasync::sleep(0.1);
        } else {
            echo "$who: someone else is on it\n";
        }
    });                                   // $claim is released when it goes out of scope
}

echo Swerve::claim('nightly-report')->available() ? "free again\n" : "still held\n";
// a runs the report
// b: someone else is on it
// c: someone else is on it
// free again
```

Use it for a job that must run once, not once per worker: a nightly report, a cache warm-up, a cron-style task a config file would otherwise have to pin to one worker by hand.

## How it works

A claim is a file in a temporary directory the master creates before it forks the workers: a hard link to a file holding the pid of whoever holds the name. Linking is atomic and fails while the name is already held, so whichever worker tries first when the name is free gets it; there is no queue. A waiting `acquire()` retries every 20 ms. Without a master, a claim works the same way in a directory scoped to the one process: two handles still conflict, even inside it.

## Waiting for it

`acquire(float $timeout = 0.0)` tries once by default; a timeout keeps retrying while another worker holds the name:

```php
phasync::go(function () {
    $claim = Swerve::claim('import')->acquire();
    echo "first: importing\n";
    phasync::sleep(0.2);
    echo "first: done\n";
});                                      // released as $claim goes

phasync::go(function () {
    echo "second: waiting\n";
    if ($claim = Swerve::claim('import')->acquire(timeout: 5.0)) {   // tries every 20 ms
        echo "second: importing\n";
    }
});
// first: importing
// second: waiting
// first: done
// second: importing
```

## Releasing

`release()` gives the name up while the handle stays alive, and does nothing if the handle holds nothing; the destructor releases it too, so a claim held only by a local variable is freed when that variable goes out of scope.

```php
$claim = Swerve::claim('leader');
$claim->acquire();
echo 'held: ', var_export($claim->held(), true), "\n";

$claim->release();                       // give it up while the handle lives on
echo 'held: ', var_export($claim->held(), true), "\n";
echo 'available: ', var_export($claim->available(), true), "\n";

$claim->release();                       // releasing what is not held does nothing
// held: true
// held: false
// available: true
```

Draining does not release a claim by itself: a long-lived holder that should let go during a reload checks `Swerve::draining()` and calls `release()` on its own, so the reload is not held up waiting for it.

## Limits

A claim is local to one server: its directory lives under `$SWERVE_TMPDIR` or the system's temporary directory, on one machine. Swerve on several machines needs a lock service of its own between them. Claim names are a namespace apart from `Swerve::cache()`'s keys, so the same string in both does not collide.
