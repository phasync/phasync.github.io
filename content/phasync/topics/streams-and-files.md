---
title: "Streams and files"
summary: "PSR-7 streams, multipart bodies, and file functions that let others run."
order: 8
status: draft
---

phasync's PSR-7 implementation ([[Request]], [[Response]], [[ServerRequest]], [[Uri]]) is the same shape as any other, with [[StringStream]], [[BufferedStream]] and [[UnbufferedStream]] for the body and [[MultipartStream]] for a `multipart/form-data` upload. A file function such as `fread()` or `fwrite()` blocks the process on its own; see [Waiting and timeouts](/topics/phasync/waiting-and-timeouts/) for what lets it wait without doing that instead.
