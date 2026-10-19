---
title: "Use phasync in a library"
description: "Write concurrent library code that runs anywhere PHP runs."
status: draft
order: 1
see_also:
  - phasync::run
  - phasync::go
project: phasync
topic: the-event-loop
role: guide
---

A library needs no setup step and no object to hold on to: call `phasync::run()` where your public
method starts, and `phasync::go()` for the work inside it that can overlap.

```php
final class WeatherClient
{
    public function temperatures(array $cities): array
    {
        return phasync::run(function () use ($cities) {
            $jobs = array_map(fn ($city) => phasync::go(fn () => $this->fetchOne($city)), $cities);

            return array_map(phasync::await(...), $jobs);
        });
    }

    private function fetchOne(string $city): string
    {
        phasync::sleep(0.05);               // stands in for a request to a weather API

        return "$city: 12C";
    }
}
```

Called on its own, `temperatures()` starts phasync's event loop and the three fetches run
together; the method returns once they all have. Called from code that already runs inside a
`phasync::run()` of its own — a Swerve request handler, or another library doing the same thing —
`run()` nests instead of starting a second loop: it runs the closure in the caller's own
coroutine and waits only for the jobs `temperatures()` itself started, while the caller's other
coroutines go on running around it. Nothing in `WeatherClient` has to know which case it is in.

## Nothing global

There is no event loop object to create, pass down, or close, and no global registry your
library has to opt into: `phasync::run()` is always either starting the loop or joining the one
that is already running. The one thing to avoid is the opposite kind of global: a static property
or a global used to pass state between two calls into your library, because several callers may
now be running at once, interleaved on the same process. Keep call-local state in local variables
and closures, as reentrant code always has.

`phasync::go()` throws a `LogicException` when it is called outside a coroutine — outside any
`phasync::run()` — telling the caller to start one. A library's own public methods are the usual
place to do that; they don't need their caller to have started one already.

## What to document for your users

- Say which of your public methods call `phasync::run()` themselves (safe to call from plain
  PHP-FPM code, under Swerve, or from inside another library's coroutine) versus which only use
  `phasync::go()` and must be called from inside one already.
- Say whether a method may run other coroutines while it waits for I/O. That is what lets a
  caller use several of them at once with `phasync::go()`, instead of one after another.
- If your library wraps blocking calls you did not write — curl, PDO, a socket client — say
  whether you waited on them with `phasync::readable()`/`writable()` or an `Awaiter` (works
  without the phasync extension, cooperating with other coroutines either way) or whether full
  concurrency depends on the extension being loaded.
