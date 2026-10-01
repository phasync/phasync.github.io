---
status: draft
description: "Receive what is published to a topic from now on."
see_also:
  - Swerve::publish
  - Subscription
  - /guides/realtime/
---

## Examples

```php
use Swerve\Swerve;

foreach (Swerve::subscribe('room:lobby') as $message) {
    // every message published to room:lobby since subscribe() returned
}
```

Subscribe before you read the current state, so that no change falls between the two. A message may then be older than the state you read; give state a version and let the client ignore an older one.

```php
$subscription = Swerve::subscribe('poll');
$state        = $db->query('SELECT version, counts FROM poll')->fetch();
echo 'data: ' . json_encode($state) . "\n\n";

foreach ($subscription as $message) {
    echo "data: $message\n\n";
}
```

With `heartbeat`, the loop yields `null` when nothing came for that many seconds, so that a producer can send a keep-alive and notice a client that left.

```php
foreach (Swerve::subscribe('chat', heartbeat: 15) as $message) {
    echo null === $message ? ": keep-alive\n\n" : "data: $message\n\n";
}
```

## Notes

- The subscription receives from the moment `subscribe()` returns, not from the first iteration.
- It ends when its last reference goes: a `break` out of the loop, the variable going out of scope, or the code holding it ending. There is no unsubscribe.
- The loop ends when the worker drains (a shutdown, reload or recycle), so that responses fed by it end too.
- A subscriber that falls more than `$maxLag` seconds behind gets a `SubscriberLagException` from its loop. A client that cannot keep up is better disconnected, and catches up from storage when it reconnects.
- A topic costs nothing in a worker without subscribers.
