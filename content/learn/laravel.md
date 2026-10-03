---
title: "Case study: Laravel"
description: "What Laravel keeps in shared state, how the Swerve adapter handles it, and what the same state needs from your code."
status: draft
order: 11
---

Laravel Octane boots one application and serves each request in a sandbox cloned from it, then runs listeners that put the state back. That is sound when requests are served one after another, and Laravel was built for it. The pages in this case study look at what changes when requests overlap, which they do in a Swerve worker whenever one waits.

## What the adapter does

[swerve-laravel](/frameworks/laravel/) keeps a pool of booted applications and gives each request in flight one of its own. After the request, Octane's listeners (the `listeners` of `config/octane.php`) and a restore of the container to what it held after boot make the application ready for the next. Laravel's process-wide pointers to "the" application (`Container::$instance`, `Facade::$app`, Eloquent's resolvers, and more) are proxies that forward to the application of the request that is running.

The adapter's tests pin that sessions, authentication, cookies, the request, the container's resolved instances and the log context stay with their own request (`tests/IsolationTest.php`).

## What is left

The adapter's `docs/shared-state.md` sorts what a request can still change for others into three groups: <!-- TODO-verify: docs/shared-state.md is in the adapter's working tree on 2026-10-03 and not yet pushed; check that https://github.com/phasync/swerve-laravel/blob/main/docs/shared-state.md exists before this ships -->

1. **The same on stock Octane.** The sandbox is a clone that shares the singletons and statics of the booted application, so a request that changes them changes them for every request after it. Octane's advice applies: register things at boot. [Container and facades](/learn/laravel-container/), [views, translator, router and paginator](/learn/laravel-views/) and [toggles and macros](/learn/laravel-toggles/) cover them.
2. **Only with overlapping requests.** Switches that a request flips around a callback and flips back: `Model::unguarded()`, `Carbon::withTestNow()`, `Number::withLocale()`. A request that waits inside the callback lets another run with the switch on.
3. **Process-level.** `ini_set()`, `setlocale()`, `date_default_timezone_set()`, `mt_srand()`, and `exit()`, which PHP keeps per process and an adapter cannot split.

Four places differ from Octane: state that Octane resets by replacing the application per request, which a pooled application needs to be told about. They were found by an audit of about eighty probes and filed as issues; the fixes were under way when this page was written:

| Issue | State that leaks |
|---|---|
| [#8](https://github.com/phasync/swerve-laravel/issues/8) | container registrations made in a request stay on the pooled application |
| [#9](https://github.com/phasync/swerve-laravel/issues/9) | facade fakes (`Mail::fake()`) reach concurrent and later requests |
| [#10](https://github.com/phasync/swerve-laravel/issues/10) | Octane's paginator listener replaces the adapter's proxy |
| [#11](https://github.com/phasync/swerve-laravel/issues/11) | the view provider's terminating callback undoes the proxy for `Component::$factory` |

<!-- TODO-verify: the audit lives on the audit branch of swerve-laravel (tests/AuditRequestStateTest.php, AuditStaticsTest.php, run against commit 8340ff4 on 2026-10-03), not merged; the issues were open when this page was written and a fix was in progress. Some probes ended in a socket error instead of a result. Update the table from the issue tracker -->

## How to read the rest

Each page names the state, what happens when a request changes it, and how it should be designed. The designs are not Laravel-specific: they are the ones in [Services that can be shared](/learn/shareable-services/) and [Designing a framework](/learn/designing-frameworks/).

Previous: [Designing a framework for concurrent requests](/learn/designing-frameworks/) · Next: [Laravel: the container and facades](/learn/laravel-container/)
