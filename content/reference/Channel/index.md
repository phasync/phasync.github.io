---
title: Channel
description: "Pass values between coroutines with phasync::channel(): a read waits for a writer, a write into a full channel waits for a reader."
symbol: Channel
status: draft
see_also:
  - ReadChannelInterface
  - WriteChannelInterface
  - phasync::go
---

phasync has no public `Channel` class. `phasync::channel()` creates a pair: a `ReadChannelInterface` and a `WriteChannelInterface` for the same channel.

## Examples

```php run
phasync::run(function () {
    phasync::channel($reader, $writer, 10);   // a buffer of 10 values

    phasync::go(function () use ($writer) {
        foreach (['a.txt', 'b.txt', 'c.txt'] as $file) {
            $writer->write($file);
        }
        $writer->close();
    });

    foreach ($reader as $file) {              // ends when the writer closes
        echo "processing $file\n";
    }
});
```

## Notes

A buffer size of 0 is an unbuffered channel: a write waits until a reader takes the value. Pass values that serialize, so that the channel can be handed to a worker process.
