<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\DataHealth;

use App\Shared\Application\StoredContacts;
use App\Shared\Domain\ValueObject\Refusal;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Every vendor's e-mail or phone that today's rules refuse (row 208), read the way the night's check lists them and
 * the night's marks name them. A phone is read by the vendor's address's country, as its form reads it, else by its
 * company's. A vendor merged away is left out: it is no longer revised.
 */
final readonly class KeptVendorContacts
{
    public function __construct(private Connection $connection, private StoredContacts $contacts)
    {
    }

    /** @return iterable<array{company: Uuid, vendor: Uuid, number: string, field: 'email'|'phone', value: string, refusal: Refusal}> */
    public function all(): iterable
    {
        $rows = $this->connection->iterateAssociative(\sprintf(<<<'SQL'
            SELECT v.company_id, v.id, v.number, v.email, v.phone, COALESCE(v.address_country_code, co.country_code) AS country
            FROM vendor v JOIN company co ON co.id = v.company_id
            WHERE (v.email IS NOT NULL OR v.phone IS NOT NULL) AND NOT (%1$s)
            ORDER BY v.company_id, v.number
            SQL, KeptRecords::mergedAway('v')));
        foreach ($rows as $row) {
            $row = Rows::row($row);
            $values = ['email' => Rows::textOrNull($row['email']), 'phone' => Rows::textOrNull($row['phone'])];
            foreach ($this->contacts->refused($values['email'], $values['phone'], Rows::textOrNull($row['country'])) as $field => $refusal) {
                yield [
                    'company' => Uuid::fromString(Rows::text($row['company_id'])),
                    'vendor' => Uuid::fromString(Rows::text($row['id'])),
                    'number' => Rows::text($row['number']),
                    'field' => $field,
                    'value' => (string) $values[$field],
                    'refusal' => $refusal,
                ];
            }
        }
    }
}
