---
title: "Context switching"
summary: "What belongs to one request and what belongs to the worker."
order: 6
status: draft
---

Code outside the handler closure in `swerve.php` runs once, when the worker starts: the application, its configuration, its database connections. Code inside the closure runs once per request, in its own coroutine, and anything it keeps in a local variable or a property it allocated there belongs to that request alone — something shared between requests has to live in whatever survives the closure, such as `Swerve::cache()` or a service started from `swerve.php`. See [After the response](/guides/after-the-response/) and [Write sequential PHP](/guides/sequential-php/).
