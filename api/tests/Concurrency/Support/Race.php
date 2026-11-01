<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;

/**
 * Runs one step in K processes at once on the stress database: each racer boots its own kernel and connection, waits
 * at a barrier (an advisory lock this race holds exclusively and every racer asks for shared), and all of them go the
 * moment every racer is waiting there, so their transactions overlap as two cashiers' would, not one after the other.
 * Process start-up skew never decides the result: the barrier is released only once PostgreSQL shows K waiters on it.
 */
final class Race
{
    /** The barrier's class id; its object id is drawn per race, so two races never share a barrier. */
    private const int BARRIER = 717701;

    /**
     * @param class-string<RaceStep> $step
     * @param array<string, mixed>   $arguments handed to every racer
     * @param int                    $staggerMs racer i waits i times this long before the barrier, as requests arrive
     *                                          apart: the barrier, not luck, must line them up
     *
     * @return list<Outcome> one per racer, by index
     */
    public static function run(string $step, int $racers, array $arguments = [], int $staggerMs = 0, float $readyWithin = 120.0, float $doneWithin = 120.0): array
    {
        if ($racers < 2) {
            throw new \InvalidArgumentException('A race needs at least two racers.');
        }
        $barrier = random_int(1, 2_147_483_647);
        $connection = StressDatabase::connect();
        $connection->executeQuery('SELECT pg_advisory_lock(?, ?)', [self::BARRIER, $barrier]);

        $processes = [];
        try {
            for ($index = 0; $index < $racers; ++$index) {
                $processes[$index] = self::start($step, $index, $barrier, $arguments, $index * $staggerMs);
            }
            self::waitForAll($connection, $barrier, $processes, $readyWithin);
        } catch (\Throwable $failure) {
            // No racer is left running behind a race that failed: it would still write once the barrier goes.
            foreach ($processes as $process) {
                proc_terminate($process['handle'], 9);
                proc_close($process['handle']);
                @unlink($process['out']);
                @unlink($process['err']);
            }
            throw $failure;
        } finally {
            $connection->executeQuery('SELECT pg_advisory_unlock(?, ?)', [self::BARRIER, $barrier]);
        }

        $outcomes = [];
        $deadline = microtime(true) + $doneWithin;
        foreach ($processes as $index => $process) {
            $outcomes[] = self::finish($index, $process, $deadline);
        }
        $connection->close();

        return $outcomes;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{handle: resource, out: string, err: string}
     */
    private static function start(string $step, int $index, int $barrier, array $arguments, int $delayMs): array
    {
        // Into files, not pipes: a racer writing more than a pipe holds would block until read, and nothing reads yet.
        $out = (string) tempnam(sys_get_temp_dir(), 'racer-out-');
        $err = (string) tempnam(sys_get_temp_dir(), 'racer-err-');
        $handle = proc_open(
            [\PHP_BINARY, __DIR__.'/racer.php', $step, (string) $index, (string) self::BARRIER, (string) $barrier, base64_encode(json_encode($arguments, \JSON_THROW_ON_ERROR)), (string) $delayMs],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
            $pipes,
            \dirname(__DIR__, 3),
            ['TEST_TOKEN' => StressDatabase::TOKEN] + getenv(),
        );
        if (!\is_resource($handle)) {
            throw new \RuntimeException("Racer $index could not be started.");
        }

        return ['handle' => $handle, 'out' => $out, 'err' => $err];
    }

    /** @param array<int, array{handle: resource, out: string, err: string}> $processes */
    private static function waitForAll(Connection $connection, int $barrier, array $processes, float $within): void
    {
        $deadline = microtime(true) + $within;
        while (true) {
            $waiting = Rows::int($connection->fetchOne(
                "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = ? AND objid = ? AND objsubid = 2 AND NOT granted
                   AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
                [self::BARRIER, $barrier],
            ));
            if ($waiting === \count($processes)) {
                return;
            }
            foreach ($processes as $index => $process) {
                if (!proc_get_status($process['handle'])['running']) {
                    throw new \RuntimeException(\sprintf('Racer %d ended before the barrier: %s', $index, self::tail($process['err']).self::tail($process['out'])));
                }
            }
            if (microtime(true) > $deadline) {
                throw new \RuntimeException(\sprintf('%d of %d racers reached the barrier within %.0f s.', $waiting, \count($processes), $within));
            }
            usleep(20_000);
        }
    }

    /** @param array{handle: resource, out: string, err: string} $process */
    private static function finish(int $index, array $process, float $deadline): Outcome
    {
        while (proc_get_status($process['handle'])['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process['handle'], 9);
                break;
            }
            usleep(20_000);
        }
        proc_close($process['handle']);
        $said = null;
        foreach (file($process['out'], \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, 'RACE-OUTCOME ')) {
                $said = json_decode(substr($line, 13), true);
            }
        }
        $stderr = self::tail($process['err']);
        @unlink($process['out']);
        @unlink($process['err']);
        if (!\is_array($said) || !\is_string($said['kind'] ?? null)) {
            return new Outcome($index, Outcome::CRASHED, message: '' === $stderr ? 'no outcome printed' : $stderr);
        }
        $state = $said['sqlState'] ?? null;
        $message = $said['message'] ?? '';

        return new Outcome($index, $said['kind'], $said['value'] ?? null, \is_string($state) ? $state : null, \is_string($message) ? $message : '');
    }

    private static function tail(string $file): string
    {
        $text = trim((string) @file_get_contents($file));

        return '' === $text ? '' : substr($text, -2000);
    }
}
