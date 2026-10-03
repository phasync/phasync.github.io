---
title: Long-lived connections
description: "A WebSocket, an SSE stream or a long poll must not hold a pooled connection between messages."
status: draft
order: 9
see_also:
  - phasync\Util\Pool
  - Swerve::subscribe
---

A WebSocket stays open for minutes or hours and sends a message now and then. If its callback holds a database connection for that time, the pool is as large as the number of open sockets. A worker can hold many sockets; a pool cannot.

## Wrong: holding a connection

```php
WebSocket::from($request, function (WebSocket $ws) use ($db) {
    $pdo = $db->borrow();                      // held until the socket closes
    foreach ($ws as $message) {
        $pdo->prepare('INSERT INTO messages (text) VALUES (?)')->execute([$message]);
    }
    $db->release($pdo);
});
```

With a pool of two, two open sockets use it up and every ordinary request after them waits. Opening a `new PDO` per socket moves the problem to the server's `max_connections`.

## Right: borrow per message

```php
WebSocket::from($request, function (WebSocket $ws) use ($db) {
    foreach ($ws as $message) {
        $db->use(fn (PDO $pdo) => $pdo->prepare('INSERT INTO messages (text) VALUES (?)')->execute([$message]));
    }
});
```

The connection is out for one insert. Fifty sockets sending two messages each ran on a pool of two (the same loop, with the database replaced by a short wait):

```
50 sockets served, 2 connections
```

<!-- TODO-verify: the two WebSocket snippets were not run: they need a Swerve server and a WebSocket client. The pool behaviour was run with coroutines standing in for sockets -->

The same applies to a Server-Sent Events stream or a long poll: read what you need, release, then wait for the next event.

## What the callback may take

The callback runs after the request that opened it has ended. Take the user and anything else you need from the request first, and pass it in; do not reach for request-bound services from inside it. The framework pages say what each adapter allows: [Laravel](/frameworks/laravel/), [Symfony](/frameworks/symfony/).

Previous: [Connections, pools and limits](/learn/connections-and-pools/) · Next: [Designing a framework for concurrent requests](/learn/designing-frameworks/)
