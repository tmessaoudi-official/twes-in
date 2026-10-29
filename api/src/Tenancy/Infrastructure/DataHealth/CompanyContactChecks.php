<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Application\StoredContacts;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The company's and its establishments' address and phone that today's rules refuse: kept from before a rule, they
 * stay until someone changes them, and this lists each so it is corrected. The detail names the establishment by its
 * code and the field, never the value. Both are read by the company's country, as their forms read them.
 */
final readonly class CompanyContactChecks implements DeclaresDataChecks
{
    public const string CONTACT_RULES = 'tenancy.contact_rules';

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
            throw new \LogicException(\sprintf('No company data check is named %s.', $check));
        }
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT co.id AS company_id, 'company' AS holder, NULL AS code, co.email, co.phone, co.country_code AS country
            FROM company co
            WHERE co.email IS NOT NULL OR co.phone IS NOT NULL
            UNION ALL
            SELECT e.company_id, 'establishment', e.code, e.email, e.phone, co.country_code
            FROM establishment e JOIN company co ON co.id = e.company_id
            WHERE e.email IS NOT NULL OR e.phone IS NOT NULL
            ORDER BY 1, 2, 3
            SQL);
        foreach ($rows as $row) {
            $row = Rows::row($row);
            $code = Rows::textOrNull($row['code']);
            foreach (array_keys($this->contacts->refused(Rows::textOrNull($row['email']), Rows::textOrNull($row['phone']), Rows::textOrNull($row['country']))) as $field) {
                yield new DataFinding(self::CONTACT_RULES, Uuid::fromString(Rows::text($row['company_id'])), null === $code ? \sprintf('company: %s', $field) : \sprintf('establishment %s: %s', $code, $field));
            }
        }
    }
}
