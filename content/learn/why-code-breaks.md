---
title: Why code breaks when requests overlap
description: "Under PHP-FPM a request owns its process. A Swerve worker serves many requests, and what they reach through a global is shared."
status: draft
order: 1
see_also:
  - /learn/per-request-state/
---

Under PHP-FPM a process serves one request and starts it with nothing in memory. Code written for that keeps request data wherever it is convenient: a global, a static property, a `static` variable in a function, a singleton that a service locator hands out. None of it is shared with another request, because the process belongs to one.

A Swerve worker keeps its memory between requests and serves several at once. A request that waits (for a query or an HTTP call; with phasync-ext also `sleep()`) lets the worker run another. Whatever both reach through a global is now shared.

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
    // Two overlapping requests; Swerve gives each one a context like these
    $ann = phasync::go(fn () => handle('ann'), [], new stdClass());
    $bob = phasync::go(fn () => handle('bob'), [], new stdClass());
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

`phasync/doctor` scans a codebase for the patterns that misbehave when requests overlap: static properties and variables, `global`, `ob_*`, `exit`, `ini_set` and similar, and calls that block. It reports `file:line` and does not change code. <!-- TODO-verify: phasync/doctor is not published yet; the install command is composer require --dev phasync/doctor (its README), and it was not run here -->

Previous: [Learn](/learn/) · Next: [Per-request state](/learn/per-request-state/)
