---
title: Services that can be shared
description: "A service the whole worker uses is stateless after boot. Request data travels with the request, and per-request variants come from a factory."
status: draft
order: 5
---

A service is safe to share when no request can change it. Build it at boot, and from then on only read it.

## Request data travels with the request

Pass it as an argument, put it in the PSR-7 request's attributes, or keep it in a scoped object that you reach through `phasync::getContext()` ([per-request state](/learn/per-request-state/)). Do not set it on the service.

## Per-request variants come from a factory

A service that is configured per request (a sender address, a tenant, a locale) hands out a new object that shares the expensive parts:

```php
// Shared, but changed by every request that calls from()
final class Mailer
{
    public function __construct(private Transport $transport, private string $from = 'noreply@example.com') {}

    public function from(string $address): static
    {
        $this->from = $address;

        return $this;
    }
}
```

With two overlapping requests, both mails leave from Bob. Returning a new `Mailer` over the same transport gives each request its own:

```php
    public function from(string $address): static
    {
        return new static($this->transport, $address);
    }
```

```
ann@example.com -> x@example.org | bob@example.com -> y@example.org
```

Other DI containers call this a service scope: shared parts underneath, a small object per request on top.

## Toggles belong to the request

A static that selects behaviour (the current locale, a test clock, a "debug" switch) is a toggle. Keep its default static and its value scoped:

```php
final class CurrentLocale
{
    /** @var WeakMap<object, string> */
    private static WeakMap $current;

    public static function set(string $locale): void
    {
        self::$current ??= new WeakMap();
        self::$current[phasync::getContext()] = $locale;
    }

    public static function get(): string
    {
        self::$current ??= new WeakMap();

        return self::$current[phasync::getContext()] ?? 'en';
    }
}
```

A request that sets `nb` and waits sees `nb`; a request that never set one sees `en`.

## Registration happens at boot

Adding a route, a listener, a macro, a view composer or a service while handling a request changes the worker for everyone, and again for every request after it. Register in the code that runs once, when the worker starts: `swerve.php` or a provider's boot method.

## Checklist

- Does the object change after boot? Then it is not shared.
- Is request data a property of it? Pass the data in instead.
- Is it registered while a request runs? Move it to boot.
- Is a static written by a request? Scope it.

## For framework authors

[Designing a framework for concurrent requests](/learn/designing-frameworks/) puts these rules into a container.

Previous: [Captive dependencies](/learn/captive-dependencies/) · Next: [Caching: memoization](/learn/memoization/)
