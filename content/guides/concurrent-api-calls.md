---
title: "Concurrent API calls"
description: "Call several APIs at once and wait for all of them."
status: draft
order: 3
see_also:
  - HttpClient
  - phasync::go
project: phasync
topic: http-client
role: guide
---

`phasync/http-client` is a PSR-18 client built on cURL's multi interface: requests started in different coroutines run together, and the slowest one decides how long the batch takes.

```php
use phasync\HttpClient\HttpClient;
use phasync\Psr\Request;

phasync::run(function () {
    $client = new HttpClient();
    $urls   = [
        'users'  => 'https://api.example.com/users',
        'orders' => 'https://api.example.com/orders',
        'stock'  => 'https://api.example.com/stock',
    ];

    $jobs = [];
    foreach ($urls as $name => $url) {
        $jobs[$name] = phasync::go(function () use ($client, $url) {
            $response = $client->sendRequest(Request::create('GET', $url));

            return \json_decode((string) $response->getBody(), true);
        });
    }

    $results = [];
    foreach ($jobs as $name => $job) {
        $results[$name] = phasync::await($job);
    }
});
```

Three requests this way take as long as the slowest of them, not the sum of all three. `phasync/http-client` is `2.0.0-alpha1`: this package has not had a stable release yet.

## Timeouts and failures

A request that takes too long, or fails to connect, throws once you ask the response for anything: `getStatusCode()`, a header, or the body. `timeoutMs` bounds how long a single request may take, counted from when it starts fetching, not from when `sendRequest()` is called.

```php
$client = new HttpClient(['timeoutMs' => 2000]);   // 2 seconds per request

foreach ($jobs as $name => $job) {
    try {
        $response = phasync::await($job);
        $results[$name] = $response->getStatusCode();
    } catch (\Throwable $e) {
        $results[$name] = null;                     // this one failed; the others are unaffected
    }
}
```

One request failing, timing out, or being cancelled with `phasync::cancel()` does not affect the others in the same batch: catch around each `await()`, not around the whole loop, so one slow or broken endpoint does not lose the results you already have.

## POST and other methods

Build the request with `phasync\Psr\Request` and send it the same way:

```php
use phasync\Psr\Request;
use phasync\Psr\StringStream;

$request = Request::create('POST', 'https://api.example.com/orders')
    ->withHeader('Content-Type', 'application/json')
    ->withBody(new StringStream(\json_encode(['item' => 'widget'])));

$response = $client->sendRequest($request);
```

Requests built and sent this way go through the same client and run concurrently with `phasync::go()`, the same as the `GET`s above. <!-- TODO-verify: HttpClient::post()/put()/get() are documented in phasync/http-client's own README but currently throw (Class "phasync\Psr\StreamFactory" not found) against phasync 2.0.0 -- the alpha1 package predates a rename in phasync's PSR-7 factories. Confirmed with a local reproduction; not used on this page until a compatible release exists. -->
