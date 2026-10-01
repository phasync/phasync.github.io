---
status: draft
description: "A name that one holder at a time can hold across the whole server."
see_also:
  - Swerve::claim
---

## Examples

```php
use Swerve\Swerve;

if ($claim = Swerve::claim('nightly-report')->acquire()) {
    run_report();
}                                    // released when $claim goes out of scope
```

## Notes

- A claim is held until it is released, its handle is destroyed, or its worker exits or dies.
- Draining does not release a claim: a long-lived holder should release it itself when `Swerve::draining()` is true, so that a reload is not held up.
- A waiting `acquire()` tries again every 20 ms. There is no queue.
