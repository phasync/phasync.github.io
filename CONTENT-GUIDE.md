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

Any comparison with Node.js, Go or PHP-FPM, and any capacity number (connections, requests per second, memory, milliseconds), must come from a logged measurement on the [Performance](/performance/) page before it is written anywhere. Until then it is not written at all: not as a placeholder, not as an expectation, not in a comment that ships. The Performance pages say what will be measured, and nothing more.

### Working notes never ship

Notes about what is unconfirmed or still to do are written as `<!-- TODO-verify: why -->` in the source. The build removes every `<!-- TODO ... -->` comment from the output, so they never reach `public/`. `php build.php --todo` prints each one as `file:line`. Public text is for the reader: a placeholder page says "This page will cover ...", never what the author wants or intends.

A page moves one step at a time. Pages for a symbol without a markdown file are generated from the docblock as `draft`, with a note that examples will be added.

## Checking an example

Before an example is committed, run it. If it cannot be run (it needs a server), mark the place with `<!-- TODO-verify: why -->` in the page, which `--todo` lists, and say what was not checked.
