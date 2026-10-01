---
status: draft
description: "Run a function as a coroutine, wait for it and every coroutine started inside it, and return its result."
see_also:
  - phasync::go
  - phasync::sleep
---

## Examples

```php run
$result = phasync::run(function () {
    return 'done';
});
echo $result;     // done
```

`run()` returns when the function and every coroutine started inside it have ended:

```php run
phasync::run(function () {
    phasync::go(function () {
        phasync::sleep(0.1);
        echo "second\n";
    });
    echo "first\n";
});
echo "third\n";
```

## Notes

- Inside Swerve, a request is already served in a `run()` of its own. You call `run()` in a library, a script or a test, where nothing has started the loop yet.
- Called from inside a coroutine, it runs the function as a coroutine in the same event loop.
- An exception thrown in the function is thrown from `run()`.
