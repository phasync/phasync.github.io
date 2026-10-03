---
title: Designing a framework for concurrent requests
description: "A boot phase that ends, a container that closes, a scope per request, and reset hooks only as a last resort."
status: draft
order: 10
---

A framework that serves many requests in one process needs four things. They follow from the pages before this one.

1. **A boot phase that ends.** Everything shared is registered while the worker starts: services, routes, listeners, macros. After boot, registering is an error, not a way to change the worker.
2. **An immutable container.** Singletons are built from what boot registered and hold no request data.
3. **A scope per request.** Request-scoped services live in a store that belongs to the request, found through `phasync::getContext()`, and are freed with it.
4. **Reset hooks as a last resort.** A reset puts state back between requests. It cannot protect a request from another one that runs at the same time, because the state it resets is the other's too.

A container with the first three, in a short class:

```php
final class Container
{
    /** @var array<string, array{Closure, bool}> */
    private array $definitions = [];
    private array $shared = [];
    /** @var WeakMap<object, ArrayObject> */
    private WeakMap $scopes;
    private bool $booted = false;

    public function __construct() { $this->scopes = new WeakMap(); }

    public function singleton(string $id, Closure $make): void { $this->define($id, $make, false); }
    public function scoped(string $id, Closure $make): void { $this->define($id, $make, true); }

    private function define(string $id, Closure $make, bool $scoped): void
    {
        $this->booted && throw new LogicException("Register $id at boot: the container is closed");
        $this->definitions[$id] = [$make, $scoped];
    }

    /** Ends the boot phase: nothing is registered after this */
    public function boot(): void { $this->booted = true; }

    public function get(string $id): object
    {
        [$make, $scoped] = $this->definitions[$id];
        if (!$scoped) {
            return $this->shared[$id] ??= $make($this);
        }
        $scope = $this->scopes[phasync::getContext()] ??= new ArrayObject();

        return $scope[$id] ??= $make($this);
    }
}
```

Two overlapping requests that each set the scoped `user` and read it after waiting:

```
ann on demo | bob on demo
Register late at boot: the container is closed
```

The second line is the exception from registering a singleton after `boot()`. `demo` is the singleton `config`, shared by both.

A scoped service that needs a singleton gets it from the container; a singleton never gets a scoped one ([captive dependencies](/learn/captive-dependencies/)).

## When a reset is needed

Some state lives where a framework cannot scope it: a static property in a library you do not own. A reset hook (Octane's listeners, Symfony's `kernel.reset`, Yii's `reset` callbacks) puts it back after each request. That is sound when requests are served one after another, and it is what the serializing adapters rely on. Each such hook is a place where a request can leave something behind, or see another's.

Previous: [Long-lived connections](/learn/long-lived-connections/) · Next: [Case study: Laravel](/learn/laravel/)
