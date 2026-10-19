---
title: "Write sequential PHP"
description: "Your handler stays sequential: sleep(), and the other requests are served meanwhile."
status: draft
order: 1
project: swerve
topic: without-the-extension
role: guide
---

A handler is ordinary PHP: read top to bottom, one statement after another. There is no callback to write, no promise to return.

```php
<?php // swerve.php

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(function (ClientRequest $request) {
    $order = place_order($request);           // your own code
    sleep(10);                                // wait for a slow payment provider
    $request->sendResponseHeaders(200, ['Content-Type' => 'application/json']);
    $request->write(\json_encode(['id' => $order->id]));
});
```

Every connection runs in its own coroutine, and a worker runs many of them at once: while this handler waits, the worker serves everyone else, then comes back to it. That `sleep(10)` waits this way — without blocking the worker — only with [the phasync extension](/library/phasync-ext/) loaded; the rest of this page says what to do about that.

## What lets other requests run

A call waits without blocking the worker when phasync knows about it. Inside `phasync::run()` (every handler runs in one), that is `phasync::sleep()`, `phasync::readable()`/`writable()`, waiting on a channel, and anything built on them, such as `Swerve::cache()` and `Swerve::subscribe()`.

The phasync extension widens that list to code that knows nothing about phasync: plain `sleep()` and `usleep()`, sockets and TLS, MySQL through mysqli or PDO, `curl_exec()` and Guzzle, pipes and child processes, DNS and filesystem calls. With it loaded (`vendor/bin/swerve --ext`, or installed with `pie install phasync/ext-phasync`), the handler above waits the same way `phasync::sleep()` does. Without it, plain `sleep()` blocks the worker: every other connection it serves waits too, for the full 10 seconds. Write `phasync::sleep()` instead when you cannot rely on the extension being loaded:

```php
phasync::sleep(10);   // waits without blocking the worker, with or without the extension
```

The same split applies to a database client: a query through mysqli or PDO waits cooperatively with the extension loaded, and blocks the worker without it. A client with its own network code instead of PHP's streams, such as phpredis or PostgreSQL's libpq (`pdo_pgsql`, `pgsql`), still blocks either way.

## What still blocks a worker

CPU-bound work blocks the worker whether or not the extension is loaded: a long loop, image processing, a large `json_decode()`. There is no coroutine to switch to while the CPU is busy; a loop that runs long can give other coroutines a turn now and then with `phasync::yield()`. A worker whose event loop is stuck for `--watchdog` seconds (30 by default) is killed and replaced, and the log says which request it was stuck in.

Short blocking calls are not a problem: a 1 ms SQLite query without the extension delays the other requests of that worker by 1 ms. Count them when there are many in one request; move anything long, or anything that must not delay other requests, to a call phasync (or the extension) knows how to wait on, or to another worker.
