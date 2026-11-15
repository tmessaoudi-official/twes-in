<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;

/**
 * Docker stops a container by sending its process SIGTERM, then kills it once the grace runs out (compose.yaml,
 * `stop_grace_period`): an update, `twes stop` and a shutdown all go through it. A consumer that does not hear the signal
 * is killed in the middle of whatever message it holds, and the Doctrine transport hands that message out again only an
 * hour later, a reset mail or an invitation with it. Hearing it needs PHP's pcntl (infra/api/Dockerfile): Messenger then
 * finishes the message it holds and leaves, which this asks of a consumer started as the worker starts one.
 */
final class WorkerStopsOnSignalTest extends KernelTestCase
{
    /**
     * The consumer finishes its message only when the command itself hears the signal: Console otherwise catches it for
     * the command and exits at once, with 0 all the same, so the exit alone cannot tell the two apart.
     */
    public function testTheConsumeCommandItselfHearsTheStopSignal(): void
    {
        $command = self::getContainer()->get('console.command.messenger_consume_messages');

        self::assertInstanceOf(ConsumeMessagesCommand::class, $command);
        self::assertContains(\SIGTERM, $command->getSubscribedSignals(), 'the command stops its worker after the message it holds');
    }

    public function testAConsumerSentTheStopSignalLeavesByItselfWellWithinTheGrace(): void
    {
        self::assertTrue(\extension_loaded('pcntl'), 'the PHP the consumers run on has pcntl, without which no signal is heard');

        $out = (string) tempnam(sys_get_temp_dir(), 'consumer-out-');
        try {
            $handle = proc_open(
                [\PHP_BINARY, \dirname(__DIR__, 3).'/bin/console', 'messenger:consume', 'async', '--env=test', '--time-limit=120', '-vv'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $out, 'a']],
                $pipes,
                \dirname(__DIR__, 3),
            );
            self::assertIsResource($handle, 'the consumer starts');

            $deadline = microtime(true) + 60;
            while (!str_contains((string) file_get_contents($out), 'Consuming messages') && microtime(true) < $deadline) {
                self::assertTrue(proc_get_status($handle)['running'], 'the consumer is still running before it is stopped: '.file_get_contents($out));
                usleep(100_000);
            }
            self::assertStringContainsString('Consuming messages', (string) file_get_contents($out), 'the consumer said it was consuming');

            // The command prints « Consuming messages » before it builds its worker, and a signal heard in between is
            // dropped (it has no worker to stop yet): a loaded machine once landed the signal there. So the signal is
            // sent again each second until the worker leaves; each one finds either no worker yet or the running one.
            // Half the grace compose gives a worker (stop_grace_period, a minute): it leaves well before Docker would
            // kill it, even on a machine a gate loads; idle, it leaves within a second of hearing it.
            $stopped = microtime(true) + 30;
            $nextSignal = 0.0;
            do {
                if (microtime(true) >= $nextSignal) {
                    proc_terminate($handle, \SIGTERM);
                    $nextSignal = microtime(true) + 1;
                }
                usleep(50_000);
                $status = proc_get_status($handle);
            } while ($status['running'] && microtime(true) < $stopped);

            if ($status['running']) {
                proc_terminate($handle, \SIGKILL);
            }
            proc_close($handle);
            self::assertFalse($status['running'], 'the consumer left well within the grace Docker gives it: '.file_get_contents($out));
            self::assertFalse($status['signaled'], 'the consumer left by itself, not killed by the signal');
            self::assertSame(0, $status['exitcode'], 'the consumer left as a stopped worker does: '.file_get_contents($out));
        } finally {
            @unlink($out);
        }
    }
}
