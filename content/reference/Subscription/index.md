---
status: draft
description: "Messages published to a topic, from Swerve::subscribe(): iterate over it to receive them."
see_also:
  - Swerve::subscribe
  - Swerve::publish
  - SubscriberLagException
---

## Examples

```php
$subscription = Swerve::subscribe('chat', maxLag: 10, heartbeat: 15);

foreach ($subscription as $message) {
    if (null === $message) {
        continue;       // 15 seconds without a message
    }
    // ...
}
```

## Notes

- Create it with `Swerve::subscribe()`; the constructor is the same call.
- `topic`, `maxLag` and `heartbeat` are public and read-only.
