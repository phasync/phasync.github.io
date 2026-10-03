---
title: "Service lifetimes: Singleton, Scoped, Ephemeral"
description: "A service lives for the worker, for one request, or for one use. Choose by whose data it holds."
status: draft
order: 3
---

Dependency-injection containers name three lifetimes. Under PHP-FPM a singleton lived as long as the request, so the first two were the same. In a worker they are not.

| Lifetime | Lives for | In PHP | Typical services |
|---|---|---|---|
| Singleton | the worker | a static, a container singleton, an object built in `swerve.php` | configuration, the router's table, a logger, a template engine, a connection pool |
| Scoped | one request | a `WeakMap` keyed by `phasync::getContext()` | the current user, the request, the session, a unit of work, a per-request cache |
| Ephemeral | one use | `new`, a factory | value objects, query builders, DTOs |

Choose by whose data the service holds. If it holds nothing but what it was built with, it is a singleton. If it holds anything that belongs to a request, it is scoped.

## A scope helper

phasync has no scope container. The pattern from [per-request state](/learn/per-request-state/) is short enough to write once for your own services:

```php
final class Scope
{
    /** @var WeakMap<object, ArrayObject> */
    private static WeakMap $scopes;

    /** The instance of $class that belongs to the current request; $make creates it on first use. */
    public static function get(string $class, ?Closure $make = null): object
    {
        self::$scopes ??= new WeakMap();
        $scope          = self::$scopes[phasync::getContext()] ??= new ArrayObject();

        return $scope[$class] ??= ($make ?? throw new LogicException("No $class in this request"))();
    }
}

Scope::get(CurrentUser::class, fn () => new CurrentUser('ann'));   // at the start of the request
Scope::get(CurrentUser::class)->name;                              // anywhere later in it
```

## In the frameworks

Each framework spells the lifetimes its own way: Laravel's `scoped()`, Symfony's services and `kernel.reset`, Yii's `reset` callbacks. What the adapter does with them is on the framework's page: [Laravel](/frameworks/laravel/), [Symfony](/frameworks/symfony/), [Yii](/frameworks/yii/). <!-- TODO-verify: the Laravel adapter's tests pin that a scoped() service is new for every request (tests/Fixtures/routes/swerve-tests.php, the 'scoped' probe); not run here -->

Previous: [Per-request state](/learn/per-request-state/) · Next: [Captive dependencies](/learn/captive-dependencies/)
