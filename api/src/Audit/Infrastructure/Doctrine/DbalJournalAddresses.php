<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Doctrine;

use App\Audit\Application\JournalAddresses;
use Doctrine\DBAL\Connection;

/** The two functions the schema's owner made for it (migration Version20261013104100), called as the application. */
final readonly class DbalJournalAddresses implements JournalAddresses
{
    public function __construct(private Connection $connection)
    {
    }

    public function erase(string $address): int
    {
        return self::count($this->connection->fetchOne('SELECT public.audit_erase_address(?)', [$address]));
    }

    public function purgeFailedSignIns(int $months): int
    {
        return self::count($this->connection->fetchOne('SELECT public.audit_purge_failed_sign_in_addresses(?)', [$months]));
    }

    private static function count(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('A journal function answered %s, not a count.', get_debug_type($value)));
        }

        return $value;
    }
}
