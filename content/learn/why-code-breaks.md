---
title: Why code breaks when requests overlap
description: "Under PHP-FPM a request owns its process. A Swerve worker serves many requests, and what they reach through a global is shared."
status: draft
order: 1
see_also:
  - /learn/per-request-state/
project: swerve
topic: context-switching
role: concept
---

Under PHP-FPM a process serves one request and starts it with nothing in memory. Code written for that keeps request data wherever it is convenient: a global, a static property, a `static` variable in a function, a singleton that a service locator hands out. None of it is shared with another request, because the process belongs to one.

A Swerve worker keeps its memory between requests and serves several at once. A request that waits (for a query or an HTTP call; with the phasync extension also `sleep()`) lets the worker run another. Whatever both reach through a global is now shared.

```php
final class CurrentUser
{
    public static ?string $name = null;
}

function handle(string $name): string
{
    CurrentUser::$name = $name;      // log the user in
    phasync::sleep(0.01);            // the request waits, for a query say
    return "hello " . CurrentUser::$name;
}

phasync::run(function () {
    // Two overlapping requests; Swerve runs each one in a Scope of its own, like these
    $ann = (new Scope())->run(fn () => phasync::go(fn () => handle('ann')));
    $bob = (new Scope())->run(fn () => phasync::go(fn () => handle('bob')));
    echo phasync::await($ann), "\n", phasync::await($bob), "\n";
});
```

```
hello bob
hello bob
```

Ann's request answers as Bob. Code between two waits runs without being interrupted, so the bug shows only when a request waits between writing the state and reading it. A test that sends one request at a time passes.

## What is safe

- Local variables, arguments, and objects the request creates and passes along.
- Constants, and state that nothing changes after the worker has booted.
- State that is keyed by the request: see [per-request state](/learn/per-request-state/).

## Finding it

The checklist in [making your application safe for concurrent requests](/guides/concurrent-requests-in-your-application/) goes through these patterns one by one, with a fix for each. [phasync/doctor](https://github.com/phasync/doctor) finds them in a codebase: `composer require --dev phasync/doctor`, then `vendor/bin/phasync-doctor`.

Previous: [Learn](/learn/) · Next: [Per-request state](/learn/per-request-state/)
