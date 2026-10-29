<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\DataHealth;

use App\Shared\Application\StoredContacts;
use App\Shared\Domain\ValueObject\Refusal;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Every customer's and contact's e-mail or phone that today's rules refuse (row 208), read the way the night's check
 * lists them and the night's marks name them. A contact is read by its customer's country, as its form reads it; an
 * address with no country is the company's. A customer merged away is left out: it is no longer revised.
 */
final readonly class KeptCustomerContacts
{
    public function __construct(private Connection $connection, private StoredContacts $contacts)
    {
    }

    /**
     * Each value its rule refuses; `contact` is null for the customer's own e-mail or phone.
     *
     * @return iterable<array{company: Uuid, customer: Uuid, number: string, contact: ?Uuid, contactName: ?string, field: 'email'|'phone', value: string, refusal: Refusal}>
     */
    public function all(): iterable
    {
        $rows = $this->connection->iterateAssociative(\sprintf(<<<'SQL'
            SELECT c.company_id, c.id AS customer_id, c.number, NULL AS contact_id, NULL AS contact, c.email, c.phone, COALESCE(c.billing_country_code, co.country_code) AS country
            FROM customer c JOIN company co ON co.id = c.company_id
            WHERE (c.email IS NOT NULL OR c.phone IS NOT NULL) AND NOT (%1$s)
            UNION ALL
            SELECT k.company_id, c.id, c.number, k.id, NULLIF(TRIM(CONCAT_WS(' ', k.first_name, k.last_name)), ''), k.email, k.phone, COALESCE(c.billing_country_code, co.country_code)
            FROM contact k JOIN customer c ON c.id = k.customer_id JOIN company co ON co.id = k.company_id
            WHERE (k.email IS NOT NULL OR k.phone IS NOT NULL) AND NOT (%1$s)
            ORDER BY 1, 3, 4 NULLS FIRST
            SQL, KeptRecords::mergedAway('c')));
        foreach ($rows as $row) {
            $row = Rows::row($row);
            $values = ['email' => Rows::textOrNull($row['email']), 'phone' => Rows::textOrNull($row['phone'])];
            foreach ($this->contacts->refused($values['email'], $values['phone'], Rows::textOrNull($row['country'])) as $field => $refusal) {
                yield [
                    'company' => Uuid::fromString(Rows::text($row['company_id'])),
                    'customer' => Uuid::fromString(Rows::text($row['customer_id'])),
                    'number' => Rows::text($row['number']),
                    'contact' => null === $row['contact_id'] ? null : Uuid::fromString(Rows::text($row['contact_id'])),
                    'contactName' => Rows::textOrNull($row['contact']),
                    'field' => $field,
                    'value' => (string) $values[$field],
                    'refusal' => $refusal,
                ];
            }
        }
    }
}
