<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Application\StoredContacts;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The customers' and their contacts' addresses and phones that today's rules refuse (row 208): kept from before a rule,
 * they stay until someone changes them, and this lists each so it is corrected. The detail names the customer and the
 * field, never the value: a customer may be a private person.
 */
final readonly class CustomerContactChecks implements DeclaresDataChecks
{
    public const string CONTACT_RULES = 'customers.contact_rules';

    public function __construct(private Connection $connection, private StoredContacts $contacts)
    {
    }

    public function dataChecks(): array
    {
        return [self::CONTACT_RULES];
    }

    public function findings(string $check): iterable
    {
        if (self::CONTACT_RULES !== $check) {
            throw new \LogicException(\sprintf('No customer data check is named %s.', $check));
        }
        // A contact is read by its customer's country, as its form reads it; an address with no country is the company's.
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT c.company_id, 'customer' AS holder, c.number, c.email, c.phone, COALESCE(c.billing_country_code, co.country_code) AS country
            FROM customer c JOIN company co ON co.id = c.company_id
            WHERE c.email IS NOT NULL OR c.phone IS NOT NULL
            UNION ALL
            SELECT k.company_id, 'contact', c.number, k.email, k.phone, COALESCE(c.billing_country_code, co.country_code)
            FROM contact k JOIN customer c ON c.id = k.customer_id JOIN company co ON co.id = k.company_id
            WHERE k.email IS NOT NULL OR k.phone IS NOT NULL
            ORDER BY 1, 3, 2
            SQL);
        foreach ($rows as $row) {
            $row = Rows::row($row);
            foreach ($this->contacts->refused(Rows::textOrNull($row['email']), Rows::textOrNull($row['phone']), Rows::textOrNull($row['country'])) as $field) {
                yield new DataFinding(self::CONTACT_RULES, Uuid::fromString(Rows::text($row['company_id'])), \sprintf('%s of customer %s: %s', Rows::text($row['holder']), Rows::text($row['number']), $field));
            }
        }
    }
}
