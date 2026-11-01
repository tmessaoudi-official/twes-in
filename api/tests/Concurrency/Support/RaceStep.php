<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

/**
 * What one racer does once the barrier lets every racer go at once. It runs in a process of its own, on a booted test
 * kernel of the stress database, with a connection that refuses to wait on a lock beyond the harness's lock timeout.
 * The class is named on the racer's command line, so it has no constructor arguments; what differs between racers is
 * their index and the arguments the test hands every racer. What it returns is JSON-encoded back to the test.
 */
interface RaceStep
{
    /** @param array<string, mixed> $arguments */
    public function run(TestContainer $container, int $index, array $arguments): mixed;
}
