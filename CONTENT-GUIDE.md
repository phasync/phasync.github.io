# Content guide

How to write a page for this site. The model is php.net: direct, structured, with an example on every page.

## Voice

- Say what it does, then show it. One idea per sentence. Facts, with numbers where we have measured them.
- Address the reader as "you". Use the present tense and active verbs.
- Short pages. If a paragraph persuades instead of informing, cut it.
- Do not answer objections nobody asked. Put the fact in the place where the doubt would arise.

### Words we don't use

Blazing, lightning, revolutionary, powerful, seamless, effortless, supercharge, "game-changer", "best-in-class", "enterprise-grade", "production-ready" (say what was tested), "simply" and "just" (if it were simple, you would not have to say so), and any adverb that stands in for a number. No testimonials, no stock illustrations, no stat tiles without a link to the measurement.

## Positioning: what the front pages say

- Front-facing pages (landing, get started, frameworks, guides) never explain coroutines, fibers, blocking or streams. They say: **your code stays sequential**. You write ordinary PHP, and Swerve runs it. `sleep(10);` and then respond is a normal handler.
- Plain `sleep()` waits without blocking a worker only with phasync-ext loaded. Say so in one sentence where `sleep()` is shown, and give `phasync::sleep()` for the case without the extension.
- Coroutines, channels, wait groups, rate limiters, claims, `OrderedChannel` and the internals live under **Advanced**. A front page may show a line of code that uses them (the SSE example needs `phasync::go()`), but does not explain them.
- Reference pages say what a function does. They link to Advanced from See also, not from the first paragraph.

### Claims

Any comparison with Node.js, Go or PHP-FPM, and any capacity number (connections, requests per second, memory, milliseconds), must come from a logged measurement on the [Performance](/performance/) page. Until it does:

- it is a placeholder with a visible marker: `<p class="callout placeholder"><strong>TODO-measure.</strong> ...</p>`, which links to `/performance/`;
- the number is not stated as fact anywhere else on the site;
- the build counts `TODO-measure` markers, so they cannot be forgotten.

A number that is in a source docblock (for example "about 0.1 ms") is not a measurement we can link. Leave it out.

## Page types

| Type | Where | Shape |
| --- | --- | --- |
| Section index | `content/<section>/index.md` | Title, one sentence, then the build lists the children. |
| Guide | `content/guides/` | A task. Working code first, then the facts it relies on. |
| Reference | `content/reference/<Class>/index.md`, `<method>.md` | The signature, Parameters, Return values and Errors come from the code. You write Examples, Notes and See also. |
| Topic | `content/reference/<Name>/index.md` with `symbol:` in the front matter | A reference page for something without a reflectable class (`HttpClient`, `Channel`). |

### Example first

Every page starts with, or soon reaches, code that works if copied. Explanation follows the code, and refers to it. In reference pages the order is fixed: Description, Parameters, Return values, Errors, Examples, Notes, See also.

Fence PHP examples as ` ```php `. An example that is complete and can be executed is fenced ` ```php run `: CI will run those. An example that is a fragment stays ` ```php `. Shell sessions are ` ```terminal `, with `$ ` before each command.

## URLs

php.net's scheme, with the short class name:

| Symbol | URL |
| --- | --- |
| `Swerve\Swerve` | `/Swerve/` |
| `Swerve::publish` | `/Swerve/publish/` (and `/Swerve::publish` redirects there) |
| `phasync::run` | `/phasync/run/` |

Two documented classes with one short name fail the build. The 404 page looks the path up without regard to case, so `/swerve/publish` lands on `/Swerve/publish/`. Other pages are `/<section>/<slug>/`. Links in content are root-relative (`/guides/realtime/`); relative links fail the build. Link to a symbol with `[[Swerve::publish]]`.

## Front matter

```yaml
---
title: Realtime
description: One sentence, used under the title and in search.
status: placeholder | draft | stable
order: 10            # position among siblings; default 50
see_also:
  - Swerve::subscribe  # a symbol, or a page: /guides/realtime/
---
```

Reference pages for a class or method take their title and description from the code; `description` in front matter overrides it.

### See also

Every page links to what a reader needs next. A reference page lists at least the matching half (`publish` and `subscribe`) and the concept it belongs to. A `see_also` that does not resolve fails the build.

## Status

- `placeholder`: the title and a list of what the page will contain. The page shows a Placeholder badge, and the build counts it. Nothing invented: no API that does not exist.
- `draft`: written and example-checked, not reviewed. Shows a Draft badge.
- `stable`: reviewed against the code. No badge.

A page moves one step at a time. Pages for a symbol without a markdown file are generated from the docblock as `draft`, with a note that examples are still to come.

## Checking an example

Before an example is committed, run it. If it cannot be run (it needs a server), mark the place with `<!-- TODO-verify: why -->` in the page, which the build counts, and say what was not checked.
