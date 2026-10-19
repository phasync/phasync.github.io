---
title: "Blocking calls and the extension"
summary: "Which blocking calls become cooperative and the drop-in functions."
order: 12
status: draft
---

Without the extension, a call such as `sleep()` or `file_get_contents()` blocks the whole process: every other coroutine waits until it returns. phasync ships drop-in functions in the `phasync` namespace — [[sleep]], [[file_get_contents]], [[file_put_contents]] and [[flock]] — that do the same job but let the other coroutines run while they wait. Import them with `use function` and the rest of the code stays as it is.

The optional extension goes further: it makes the ordinary blocking calls cooperative, so code you did not write (a database driver, a framework, a library) also stops blocking the other coroutines. [[ensure_loaded]] tells you whether it is there. This topic lists which calls the extension covers and which ones it does not.
