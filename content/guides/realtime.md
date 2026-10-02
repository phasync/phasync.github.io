---
title: "Realtime: SSE and WebSockets"
description: "Push to the browser with Server-Sent Events or WebSockets, across all workers."
status: draft
order: 2
see_also:
  - Swerve::publish
  - Swerve::subscribe
  - WebSocket
---

A request may stay open as long as it likes, and a message published in any worker reaches the subscribers in every worker. Both examples are complete chat rooms, taken from `examples/` in the Swerve repository, where the tests run them.

## Server-Sent Events

The browser's `EventSource` reads a `text/event-stream` response and reconnects by itself when it ends.

```php
<?php // swerve.php

use phasync\Psr\Response;
use phasync\Psr\UnbufferedStream;
use phasync\TimeoutException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swerve\Swerve;

return new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' === $request->getMethod()) {
            Swerve::publish('chat', (string) $request->getBody());

            return new Response(204);
        }

        $subscription = Swerve::subscribe('chat', heartbeat: 15);
        $out = new UnbufferedStream(1, 60);
        phasync::go(static function () use ($subscription, $out) {
            try {
                foreach ($subscription as $message) {
                    $out->append(null === $message ? ": keep-alive\n\n" : "data: $message\n\n");
                }
            } catch (TimeoutException) {
                // the client left
            } finally {
                $out->end();
            }
        });

        return new Response(200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache'], $out);
    }
};
```

```js
const events = new EventSource('/');
events.onmessage = (e) => console.log(e.data);
fetch('/', {method: 'POST', body: 'hello'});
```

- `subscribe()` is called before the response is returned: a message published after that is not missed.
- Subscribe before you read the current state, so that no change falls between the two. A message may then be older than the state you read: give state a version and let the client ignore an older one.
- `heartbeat: 15` yields `null` after 15 seconds without a message. The keep-alive comment keeps proxies from closing the connection, and is how the write notices a client that left.
- An SSE event is `data: <one line>`: JSON-encode messages so that they stay on one line.
- There is no history. A client that reconnects catches up from your storage.

## WebSockets

`WebSocket::from()` answers the upgrade and runs the callback once the connection is open. Messages from the browser are events; what goes back is plain sequential code in the callback. The connection closes when the callback returns (1000) or throws (1011, logged).

```php
use Swerve\Http\WebSocket;

return WebSocket::from($request, static function (WebSocket $ws) {
    $ws->onMessage->listen(static function (string $data, bool $binary) {
        Swerve::publish('chat', $data);                // validate it first, see examples/websocket-chat
    });

    foreach (Swerve::subscribe('chat') as $message) {  // ends when the browser leaves
        $ws->send($message);
    }
});
```

- The connection is read all the time: pings are answered, and when the browser leaves the callback is cancelled, so the loop above ends with it.
- `onMessage` listeners get `(string $data, bool $binary)` one at a time, in the order the messages arrived, so a slow listener holds back the reading.
- `onClose` listeners get `(int $code, string $reason)` once, whichever side ended the connection: the browser's code (1005 when it sent none), the one given to `end()`, 1011 after an exception, or 1006 when no close frame came.
- `$ws->end(int $code = 1000, string $reason = '')` closes the connection early; `send()` and `end()` may be called from any coroutine. `sendBinary()` sends a binary message.
- A subscriber that falls too far behind makes the loop throw `SubscriberLagException`, which closes the socket with 1011. Catch it and call `$ws->end(1008)` to choose the code; the browser reconnects.
- `$ws->receive()`, or `foreach ($ws as $message)`, is the pull alternative to `onMessage`. It throws a `LogicException` while `onMessage` has listeners.

Write the callback as a `static` function. A plain closure keeps `$this`, and with it the controller and often the whole application, in memory for as long as the socket is open.

## How a message travels

Workers share no memory. Say 1000 browsers are subscribed in worker 1 and a request in worker 2 calls `Swerve::publish('chat', $message)`:

1. The master process keeps, for each topic, a bitmap of the workers that have a subscriber. Worker 2 asks for the bitmap of `chat` and remembers it until the master says to forget it.
2. Worker 2 writes one datagram into the inbox of worker 1. Only workers that have a subscriber receive one. The master is not in the data path.
3. Worker 1 decodes the message once and hands it to each of its 1000 subscriptions.

Messages from one publisher arrive in the order it published them, for every subscriber, in every worker. There is no order between messages from different publishers: if you need one, put a version in the message and let the client ignore an older one, or see `OrderedChannel` in [Advanced](/advanced/ordered-channel/).

## Limits

- Delivery is at most once, to the subscriptions that exist when the message reaches their worker. Keep what must not be lost in storage, and use publish and subscribe to say that it changed.
- A subscriber that falls more than `maxLag` seconds behind (30 by default) gets a `SubscriberLagException`: disconnect it, and let it reconnect.
- Messages are at most 128 KiB. Swerve on several machines needs Redis, NATS or the like between them.
