<?php

/**
 * Named groups of symbols that belong together: each member lists the others under See also, with the group title
 * as the source. At most 8 members; a symbol in at most 2 groups; a group of one class's own methods only is an
 * error (the class page already lists them). Names as in @see: `phasync::sleep`, `Channel`, `Swerve\OrderedChannel`.
 */
return [
    'waiting' => ['title' => 'Waiting for something', 'members' => ['phasync::sleep', 'phasync::readable', 'phasync::writable', 'phasync::awaitFlag', 'SelectableInterface', 'TimeoutException']],
    'ending-coroutines' => ['title' => 'Ending a coroutine', 'members' => ['phasync::go', 'phasync::cancel', 'phasync::finally', 'CancelledException']],
    'channels' => ['title' => 'Passing values between coroutines', 'members' => ['phasync::channel', 'Channel', 'ReadChannelInterface', 'WriteChannelInterface', 'Queue']],
    'publish-subscribe' => ['title' => 'Messages between workers', 'members' => ['Swerve::publish', 'Swerve::subscribe', 'OrderedChannel', 'Subscription', 'SubscriberLagException']],
];
