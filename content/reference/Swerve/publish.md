---
status: draft
description: "Send a message to every subscriber of a topic, in every worker process."
see_also:
  - Swerve::subscribe
  - OrderedChannel
  - Channel
  - /guides/realtime/
---

## Examples

A string is sent as it is. The subscriber gets the same string.

```php
use Swerve\Swerve;

Swerve::publish('room:lobby', json_encode(['user' => 'ann', 'text' => 'hi']));
```

An array is sent as JSON, and arrives decoded. An array with keys arrives as a read-only `SealedObject`.

```php
Swerve::publish('game', ['kill', $playerId]);       // subscribers receive the array ['kill', 17]
Swerve::publish('score', ['player' => 17, 'points' => 3]);

foreach (Swerve::subscribe('score') as $message) {
    echo $message->points;                          // a SealedObject: read, never changed
}
```

Publish the string that your browsers should get, when subscribers pass it on unchanged to Server-Sent Events or WebSockets: it is encoded once, by the publisher, and not by every subscriber.

```php
Swerve::publish('chat', json_encode(['name' => $name, 'text' => $text]));
```

## Notes

How the message travels:

- The master process keeps, for each topic, a bitmap of the workers that have a subscriber. The publishing worker asks for the bitmap and keeps it until the master says to forget it.
- The publishing worker writes a datagram into the inbox of each worker that has a subscriber. The master is not in the data path.
- Example: 1000 browsers are subscribed in worker 1, and a request in worker 2 publishes. One datagram goes from worker 2 to worker 1, and worker 1 hands the message to every one of the 1000 subscriptions.
- A worker without a subscriber to the topic receives nothing.

What it promises:

- Messages from one publisher arrive in the order it published them, in every worker. There is no order between messages from different publishers; for one, use [[OrderedChannel]].
- Delivery is at most once, to the subscriptions that exist when the message reaches their worker. There is no history.
- It returns once the message is on its way, not when it is delivered.
- A worker that does not read its inbox loses the messages sent to it after 0.1 seconds, and the publisher logs a warning.
- Swerve on several machines needs Redis, NATS or the like between them: messages stay on one machine.
- It works once the worker serves, not while `swerve.php` loads. Before that it throws `LogicException`.
