---
title: "After the response"
description: "Keep working after the response is sent: send 10,000 e-mails without the client waiting."
status: draft
order: 2
see_also:
  - phasync::go
  - phasync::run
project: swerve
topic: request-handlers
role: guide
---

A handler returns once it has sent everything the client needs; the client has the whole response by the time it does. `phasync::go()` started inside the handler keeps running after that, so work the response does not depend on can happen once the client is gone.

```php
<?php // swerve.php

use Swerve\ClientRequest;
use Swerve\RequestHandler;

return new RequestHandler(function (ClientRequest $request) {
    $recipients = import_signups($request);          // your own code; reads the body

    $request->sendResponseHeaders(200, ['Content-Type' => 'text/plain']);
    $request->write(\count($recipients) . " signed up\n");
    $request->end();

    phasync::go(function () use ($recipients) {
        foreach ($recipients as $to) {
            send_welcome_email($to);                  // your own code
        }
    });
});
```

The coroutine closes over whatever the handler already has: the parsed body, a user loaded from the database, a connection opened earlier in the handler. None of that needs to be rebuilt.

## What it does not do

The coroutine still belongs to this request's own frame. The connection does not read a next request from the same client until the handler and every coroutine it started have ended, so a client that pipelines another request right behind this one waits for the e-mails to go out; every other connection, in this worker and every other, is unaffected. Keep after-the-response work short, or move it off this connection entirely:

- For work that must survive past this one request, such as one shared SMTP connection serving every request in the worker, use `phasync::service()`. A service starts on first use, outlives the request that started it, and is cancelled only when the worker itself stops.

```php
final class Mailer
{
    private static ?phasync\WriteChannelInterface $queue = null;

    public static function send(string $to, string $subject): void
    {
        if (null === self::$queue) {
            phasync::channel($read, self::$queue, 100);
            phasync::service(function () use ($read) {
                foreach ($read as $job) {
                    [$to, $subject] = $job;
                    smtp_send($to, $subject);          // one connection, reused by every request
                }
            });
        }
        self::$queue->write([$to, $subject]);
    }
}
```

`Mailer::send()` returns as soon as the job is queued: the handler above can call it instead of `send_welcome_email()` and return at once, without starting a `phasync::go()` of its own.

## On shutdown

A reload or a stop ends every coroutine of the worker, including ones started with `phasync::go()` after the response: each gets a `Swerve\WorkerStoppingException` at its next wait. Work that must not be cut short (a write that must finish) belongs in `phasync::finally()` or `phasync::shielded()`, not left running loose in a `go()`. A service is cancelled the same way when the worker stops, so write it to pick up where it left off, or to finish quickly, rather than to guarantee delivery by itself.
