<?php

/**
 * The classes that have reference pages. Each gets /<ShortName>/ and /<ShortName>/<method>/,
 * generated from its docblocks by reflection; a markdown file in content/reference/ adds the
 * hand-written parts. Two classes with one short name fail the build.
 */
return [
    Swerve\Swerve::class,
    Swerve\Subscription::class,
    Swerve\Claim::class,
    Swerve\OrderedChannel::class,
    Swerve\SubscriberLagException::class,
    Swerve\Http\WebSocket::class,
    phasync::class,
    phasync\ReadChannelInterface::class,
    phasync\WriteChannelInterface::class,
    phasync\TimeoutException::class,
    phasync\CancelledException::class,
    phasync\Util\WaitGroup::class,
    phasync\Util\RateLimiter::class,
    phasync\Util\ProcessRunner::class,
];
