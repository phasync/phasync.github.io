---
status: draft
description: "The facade of phasync: run(), go(), sleep(), await(), cancel() and the rest."
see_also:
  - phasync::run
  - Channel
  - WaitGroup
---

## Examples

```php run
phasync::run(function () {
    $a = phasync::go(function () { phasync::sleep(0.2); return 'a'; });
    $b = phasync::go(function () { phasync::sleep(0.1); return 'b'; });

    echo phasync::await($a), phasync::await($b), "\n";   // ab, after 0.2 seconds
});
```

## Notes

- `phasync` is a final class of static methods in the global namespace.
- The package is `phasync/phasync`. It behaves the same with and without the `phasync-ext` extension, which lets more code wait without blocking and makes waiting cheaper.
