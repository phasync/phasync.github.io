---
title: "Run your application"
description: "Write swerve.php and run your application with vendor/bin/swerve."
status: draft
order: 2
see_also:
  - Swerve::log
---

Swerve serves a PHP file that returns a `Swerve\RequestHandler` wrapping a closure. By default
it is `swerve.php` in the current directory, and the closure runs once for each HTTP exchange,
with a `Swerve\ClientRequest` to read and respond to:

```php
<?php // swerve.php

return new Swerve\RequestHandler(function (Swerve\ClientRequest $request) {
    parse_str((string) parse_url($request->getTarget(), PHP_URL_QUERY), $query);

    $request->sendResponseHeaders(200, ['content-type' => 'text/plain']);
    $request->write('Hello, ' . ($query['name'] ?? 'World') . "\n");
});
```

```
> vendor/bin/swerve
2026-10-19 15:48:53.19   swerve dev-main serving ./swerve.php on http://127.0.0.1:8080 with 8 workers
2026-10-19 15:48:54.04 4 GET /?name=Frode 200 0.3ms
```

Every log line has the time, the worker's slot number (blank for the master process), and the
message. A request line has its status and how long the handler took; anything else worth
knowing — a worker that crashed, a reload — is logged the same way. Ctrl+C stops swerve, letting
requests already in flight finish.

The file is loaded once in each worker process, when the worker starts: code outside the closure
(building the application, reading configuration, connecting to a database) runs once per worker,
not once per request, and stays in memory for every request the worker goes on to serve.

`Swerve\Swerve::log()` is swerve's own log as a PSR-3 `LoggerInterface`: give it to your
application, or call it directly, and your lines land in the same place as the request lines
above, with the time and the worker's slot:

```php
Swerve\Swerve::log()->warning('Payment {id} declined', ['id' => 42]);
```

```
2026-10-19 15:55:35.79 0 warning   Payment 42 declined
```

## Options you will want first

```bash
vendor/bin/swerve --http=:8080            # listen on every interface, not only 127.0.0.1
vendor/bin/swerve --public=public         # serve public/ (CSS, JavaScript, images) in front of swerve.php
vendor/bin/swerve -w 4                    # 4 workers, instead of one per CPU core
```

- `--http=<address>` takes a bare port (`8080`, this machine only), `:8080` (every interface), a
  host and port, or `unix:/path` for a Unix domain socket. Repeat it to listen on several
  addresses.
- `--public=<dir>` serves the files in that directory and passes every other request to
  `swerve.php`: `/css/site.css` is `public/css/site.css`, `/` is `public/index.html`. This is
  deliberately small (no directory listing, PHP files are never served from it); for a lot of
  static content, put nginx or a CDN in front of swerve instead.
- `-w, --workers=<n>` sets the number of worker processes; `auto` (the default) is one per CPU
  core. Each worker holds its own copy of the application in memory.

`vendor/bin/swerve --help` lists every option; [the dev server](/get-started/dev-server/) covers
`--watch`, for development.

Next: [Deploy](/get-started/deploy/).
