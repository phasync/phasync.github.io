---
title: "OrderedChannel"
description: "One order for every subscriber, from many publishers."
status: draft
order: 5
see_also:
  - OrderedChannel
project: swerve
topic: coordination-across-workers
role: concept
---

Plain `Swerve::publish()` already delivers one publisher's messages in order, to every subscriber: enough for a stream between two coroutines or two workers, see [Realtime: SSE and WebSockets](/guides/realtime/). What it does not give you is an order across *several* publishers: if worker 1 and worker 2 both publish at nearly the same time, each subscriber may see them in a different order. `Swerve\OrderedChannel` gives every subscriber, in every worker, the same order, with each publisher's own messages still keeping theirs.

```php
$ledger = new Swerve\OrderedChannel('ledger');

// Two subscribers, which in a server would be in different workers
foreach (['audit', 'report'] as $name) {
    $subscription = $ledger->subscribe();
    phasync::go(function () use ($subscription, $name) {
        $entries = [];
        foreach ($subscription as $entry) {
            $entries[] = implode(' ', $entry);
            if (3 === count($entries)) {
                break;
            }
        }
        echo "$name: ", implode(', ', $entries), "\n";   // the same order for both
    });
}

// Several writers; every subscriber sees one common order
phasync::go(fn () => $ledger->write(['debit', 12]));
phasync::go(fn () => $ledger->write(['credit', 5]));
phasync::go(fn () => $ledger->write(['debit', 3]));
// audit: debit 12, credit 5, debit 3
// report: debit 12, credit 5, debit 3
```

Use it where the order between publishers is the point: a ledger, an audit trail, anything a client would notice arrived in a different sequence from what another client saw.

## What it costs

An `OrderedChannel` is a broadcast, not a queue: it is a separate thing from a plain topic of the same name, and nothing is written while nobody subscribes anywhere.

```php
$events = new Swerve\OrderedChannel('events');

$events->write('lost');                  // a broadcast: nobody subscribes, nothing is kept

$subscription = $events->subscribe();    // receives what is written from here on
$events->write('seen');

foreach ($subscription as $event) {
    echo "$event\n";
    break;
}
// seen
```

Retention is bounded, about 30 seconds: a subscriber that falls further behind than that gets a `SubscriberLagException`, the same as a plain `Swerve::subscribe()`. Each message costs one extra file append to keep the shared order, and a worker is woken by a datagram, best effort, checking the log at least once a second even if that datagram is lost. That is more than a plain topic costs: reach for `OrderedChannel` only when the order between publishers is something your subscribers would actually notice.

`getIterator()` subscribes with the defaults, so `foreach ($channel as $message)` subscribes at the `foreach`:

```php
$alerts = new Swerve\OrderedChannel('alerts');

phasync::go(function () use ($alerts) {
    phasync::sleep(0.1);                 // after the foreach below has subscribed
    $alerts->write('disk 91% full');
    $alerts->write('disk 95% full');
});

// foreach subscribes with the defaults, at the foreach: what was written before is not seen
foreach ($alerts as $n => $alert) {
    echo "$alert\n";
    if (1 === $n) {
        break;
    }
}
// disk 91% full
// disk 95% full
```
