<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The database keeps an issued invoice as it was issued (the fiscal guard). A test that must place one in the past, or
 * tamper with one so that a check notices, writes it through here and says why: the guard is opened for that statement
 * alone, through the door tests/bootstrap.php makes in a test database only, and closed again, so whatever the test
 * does next meets the guard as the application does.
 */
trait PastFiscalGuard
{
    /**
     * @param array<int<0, max>|string, mixed> $parameters
     *
     * @return int|string the rows the statement wrote
     */
    protected function pastFiscalGuard(string $why, string $statement, array $parameters = []): int|string
    {
        if ('' === trim($why)) {
            throw new \LogicException('A write past the fiscal guard says why the test needs it.');
        }
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        // A failing statement aborts the test's transaction, the door's row with it: the door is closed only after a write.
        $connection->executeStatement('SELECT fiscal_guard_test_release(true)');
        $written = $connection->executeStatement($statement, $parameters);
        $connection->executeStatement('SELECT fiscal_guard_test_release(false)');

        return $written;
    }
}
