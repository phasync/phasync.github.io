---
title: Captive dependencies
description: "A singleton must never hold a scoped object: it keeps the first request's, for every request after it."
status: draft
order: 4
---

A service may depend only on services that live at least as long as it does. A singleton that takes a scoped object in its constructor holds the one it was given for the rest of the worker's life.

```php
final class ReportService   // a singleton, created by whichever request asks first
{
    public function __construct(private CurrentUser $user)   // a scoped object
    {
    }

    public function title(): string
    {
        return 'Report for ' . $this->user->name;
    }
}

function reports(): ReportService
{
    static $service;

    return $service ??= new ReportService(Scope::get(CurrentUser::class));
}
```

Two requests, ann first:

```
Report for ann
Report for ann
```

Bob's report is titled with Ann's name. Nothing fails; the service is correct for one request and wrong for all the others.

## Fix: resolve at use

The singleton holds no user. It asks for the current one when it needs it:

```php
final class ReportService
{
    public function title(): string
    {
        return 'Report for ' . Scope::get(CurrentUser::class)->name;   // the current request's
    }
}
```

```
Report for ann
Report for bob
```

`Scope` is the helper from [Service lifetimes](/learn/service-lifetimes/). Two other fixes work where they fit: pass the user as an argument (`title(CurrentUser $user)`), or make the service scoped too. An ephemeral service may hold a scoped one: it is gone before the request is.

The same mistake takes other forms: a closure that `use`s the request and is stored in a singleton, a request saved in a static property, an event listener registered during a request. Each keeps the first request's data.

Previous: [Service lifetimes](/learn/service-lifetimes/) · Next: [Services that can be shared](/learn/shareable-services/)
