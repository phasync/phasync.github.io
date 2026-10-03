---
title: Connections, pools and limits
description: "A worker needs few database connections. Borrow one for a query or a transaction and give it back."
status: draft
order: 8
see_also:
  - phasync\Util\Pool
---

Each worker is a process with its own connections. The database server sees all of them:

```
connections on the server  =  workers  x  connections per worker
```

`--workers` defaults to one per CPU core. Keep the product below the server's limit (`max_connections` in MySQL), and leave room for the other clients: queue workers, cron jobs, a console.

## Use a pool

[[phasync\Util\Pool]] makes connections when they are needed, up to a size, and makes a request wait while all are out:

```php
use phasync\Util\Pool;

$db = new Pool(fn () => new PDO($dsn, $user, $password), 10);   // at most 10 in this worker

$rows = $db->use(fn (PDO $pdo) => $pdo->query('SELECT ...')->fetchAll());
```

`use()` borrows, runs the closure, and releases, also when it throws. `borrow()` and `release()` do the same by hand. When a connection breaks, give it back with `discard()` and the pool makes a new one. `window:` shrinks an idle pool to the most that were lent at once within that many seconds, and `dispose:` closes what the pool lets go of. Create the pool at boot, once per worker.

A hundred requests that each need a connection for ten milliseconds share three:

```php
$made = 0;
$db   = new Pool(function () use (&$made) {   // stands in for new PDO(...)
    $made++;

    return new stdClass();
}, 3);

phasync::run(function () use ($db) {
    $requests = [];
    for ($i = 0; $i < 100; $i++) {
        $requests[] = phasync::go(fn () => $db->use(fn (stdClass $connection) => phasync::sleep(0.01)));
    }
    foreach ($requests as $request) {
        phasync::await($request);
    }
});

echo "100 requests, $made connections made, ", count($db), " open\n";
```

```
100 requests, 3 connections made, 3 open
```

## Borrow per query or transaction

Borrow for as long as the work needs the connection, and no longer: one query, or one transaction. Do not borrow at the start of the request and hold the connection to the end, through the HTTP call and the template rendering: the pool is then as large as the number of requests in flight, not the number of queries.

Without phasync-ext a query blocks the whole worker while it runs. With it, a query waits as a coroutine, other requests run, and the pool is what limits them.

## Other clients

- **Redis:** phpredis blocks the worker even with phasync-ext, a client written on PHP's streams waits as a coroutine with it ([how it runs](https://github.com/phasync/swerve/blob/main/docs/how-it-runs.md)). <!-- TODO-verify: a Redis client on PHP's streams with phasync-ext was not run here; the statement is swerve's how-it-runs.md -->
- **HTTP APIs:** a call waits as a coroutine. A service that limits its callers sees each worker as one more, so bound the rate with [[phasync\Util\RateLimiter]].

Previous: [Caching: whose data is it?](/learn/cache-keys/) · Next: [Long-lived connections](/learn/long-lived-connections/)
