---
status: draft
description: "A topic where every subscriber, in every worker, receives the messages in one and the same order."
see_also:
  - Swerve::publish
  - Swerve::subscribe
  - /advanced/ordered-channel/
---

## Examples

```php
use Swerve\OrderedChannel;

$ledger = new OrderedChannel('ledger');
$ledger->write(['debit', 12]);

foreach ($ledger as $entry) {
    // every subscriber, in every worker, sees the entries in the same order
}
```

## Notes

- Plain `Swerve::publish()` from one publisher is already ordered for its subscribers. `OrderedChannel` is for many publishers that need one common order.
- It is a broadcast, not a queue: nothing is written while nobody subscribes, and a subscriber sees what is written after it subscribed.
- Retention is about 30 seconds. A subscriber lagging beyond that gets a `SubscriberLagException`.
- Each message costs one append to a file in the master's temporary directory.
- It is separate from the plain topic of the same name.
