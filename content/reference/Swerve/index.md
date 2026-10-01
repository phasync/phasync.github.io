---
status: draft
description: "What Swerve gives the application: its log, the cache and the messages shared by every worker."
see_also:
  - Subscription
  - OrderedChannel
  - Claim
  - WebSocket
  - phasync
---

## Examples

```php
use Swerve\Swerve;

Swerve::publish('news', 'a message');                 // to every subscriber, in every worker
foreach (Swerve::subscribe('news') as $message) { }   // receive them

Swerve::cache()->set('key', 'value', 60);             // a PSR-16 cache shared by the workers
Swerve::log()->info('served');                        // a PSR-3 logger, in Swerve's format
```

`Swerve::log()` is the logger to give to a library that takes a PSR-3 logger, such as Slim's error middleware:

```php
$app->addErrorMiddleware(true, true, false, Swerve::log());
```

A claim lets one worker at a time hold a name:

```php
if ($claim = Swerve::claim('nightly-report')->acquire()) {
    // this worker holds the claim until $claim goes out of scope
}
```

## Notes

- `Swerve` is a class of static methods; it has no instances.
- Without Swerve's command line (Swerve embedded in your own process), `log()` is a `NullLogger` until `setLog()` is called, and `publish()`, `cache()` and `claim()` work in that process only.
