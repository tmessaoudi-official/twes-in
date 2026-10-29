<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Application\StoredContacts;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The vendors' addresses and phones that today's rules refuse: kept from before a rule, they stay until someone changes
 * them, and this lists each so it is corrected. The detail names the vendor and the field, never the value.
 */
final readonly class VendorContactChecks implements DeclaresDataChecks
{
    public const string CONTACT_RULES = 'vendors.contact_rules';

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
            throw new \LogicException(\sprintf('No vendor data check is named %s.', $check));
        }
        // A vendor's phone is read by its address's country, as its form reads it, else by its company's.
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT v.company_id, v.number, v.email, v.phone, COALESCE(v.address_country_code, co.country_code) AS country
            FROM vendor v JOIN company co ON co.id = v.company_id
            WHERE v.email IS NOT NULL OR v.phone IS NOT NULL
            ORDER BY v.company_id, v.number
            SQL);
        foreach ($rows as $row) {
            $row = Rows::row($row);
            foreach ($this->contacts->refused(Rows::textOrNull($row['email']), Rows::textOrNull($row['phone']), Rows::textOrNull($row['country'])) as $field) {
                yield new DataFinding(self::CONTACT_RULES, Uuid::fromString(Rows::text($row['company_id'])), \sprintf('vendor %s: %s', Rows::text($row['number']), $field));
            }
        }
    }
}
