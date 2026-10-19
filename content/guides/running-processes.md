---
title: "Running processes"
description: "Run external programs without blocking a worker."
status: draft
order: 4
see_also:
  - ProcessRunner
project: phasync
topic: processes
role: guide
---

`phasync\Util\Process::run()` starts a child process and returns a `ProcessInterface` whose standard streams you read and write from a coroutine, without blocking the others.

```php
phasync::run(function () {
    $process = phasync\Util\Process::run('echo', ['hello']);

    echo $process->read();                 // hello
    while ($process->isRunning()) {
        phasync::sleep(0.01);
    }
    var_dump($process->getExitCode());      // int(0)
});
```

Nothing goes through a shell: `$command` is searched for in `PATH` (or taken as a path if it contains a slash), and the arguments are passed as they are, so no quoting is needed even for arguments with spaces or special characters in them.

## Several at once

Each process waits in its own coroutine, so starting a few runs them side by side instead of one after another.

```php
phasync::run(function () {
    $jobs = [];
    foreach (['a.csv', 'b.csv', 'c.csv'] as $file) {
        $jobs[$file] = phasync::go(function () use ($file) {
            $process = phasync\Util\Process::run('gzip', ['-c', $file]);
            $output  = '';
            while ('' !== ($chunk = $process->read())) {
                $output .= $chunk;
            }

            return $output;
        });
    }
    foreach ($jobs as $file => $job) {
        \file_put_contents("$file.gz", phasync::await($job));
    }
});
```

## Streaming and talking back

`read()` waits until the process has written something and returns it; it returns `''` only once, at the end of the stream. `write()` sends to standard input the same way. A process that answers each line it is given:

```php
$child = phasync\Util\Process::run(PHP_BINARY, ['-r', 'while (false !== ($l = fgets(STDIN))) { echo strtoupper($l); }']);

$child->write("hello\n");
echo $child->read();    // HELLO
$child->stop();
```

Standard error is read the same way, with `ProcessInterface::STDERR`:

```php
echo $process->read(phasync\Util\ProcessInterface::STDERR);
```

`getStream(int $fd)` returns the underlying stream, for `stream_select()` or `phasync::readable()`/`writable()` directly, when `read()`'s own waiting does not fit.

## Timing out and killing it

`read()` and `write()` wait as long as it takes; there is no timeout argument on them. To give a process a deadline, run it in a coroutine and await that coroutine with a timeout, then stop the process if it is reached:

```php
phasync::run(function () {
    $process = phasync\Util\Process::run('sleep', ['10']);

    $job = phasync::go(function () use ($process) {
        return $process->read();     // sleep never writes anything
    });

    try {
        phasync::await($job, 0.2);   // give it 200 ms
    } catch (phasync\TimeoutException) {
        $process->stop();            // SIGTERM, then SIGKILL after one second
    }
});
```

`stop()` returns once the process is no longer running, or `false` if it still is after its five seconds. `sendSignal(int $signal = 15)` sends any signal directly and returns `false` without sending it once the process has already ended; on Windows the signal cannot be chosen, and any call ends the process at once. `getExitCode()` is the exit code, or `false` while it still runs; a process ended by a signal reports `-1`.

A `read()` or `write()` on a stream whose process has ended, once that has been noticed by an `isRunning()` or another status call, throws `phasync\IOException`: read what you need before asking.
