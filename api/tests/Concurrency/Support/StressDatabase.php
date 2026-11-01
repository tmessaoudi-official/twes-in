<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * The race tests' own database, twes_test_stress (TEST_TOKEN `_stress`, doctrine.yaml's suffix), never the gate's test
 * database nor the live one: racers commit for real, which the suite's per-test transactions would hide, and what they
 * leave behind would reach whatever else reads that database. Any other name is refused before a connection is opened.
 *
 * @phpstan-import-type Params from DriverManager
 */
final class StressDatabase
{
    public const string TOKEN = '_stress';

    /** The database the race tests run on, as doctrine.yaml names it for the test environment and this token. */
    public static function name(): string
    {
        $token = $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
        $name = (self::owner()['dbname'] ?? 'twes').'_test'.(\is_string($token) ? $token : '');
        if (!str_ends_with($name, self::TOKEN)) {
            throw new \LogicException(\sprintf('The race tests run only on a database of their own ending %s, never on %s: run them through make stress.', self::TOKEN, $name));
        }

        return $name;
    }

    /** A connection as the database's owner, outside any kernel and so outside DAMA's per-test transaction. */
    public static function connect(): Connection
    {
        return DriverManager::getConnection(['dbname' => self::name()] + self::owner());
    }

    /**
     * The owner's connection parameters, the database name left as the URL gives it.
     *
     * @return Params
     */
    public static function owner(): array
    {
        $url = $_SERVER['DATABASE_MIGRATION_URL'] ?? getenv('DATABASE_MIGRATION_URL');
        if (!\is_string($url) || '' === $url) {
            throw new \LogicException('DATABASE_MIGRATION_URL names no database server for the race tests.');
        }

        return (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url);
    }
}
