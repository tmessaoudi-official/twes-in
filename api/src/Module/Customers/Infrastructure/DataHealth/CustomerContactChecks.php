<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;

/**
 * The customers' and their contacts' addresses and phones that today's rules refuse (row 208): kept from before a rule,
 * they stay until someone changes them, and this lists each so it is corrected. The detail names the customer and the
 * field, never the value, since the operators read it and a customer may be a private person; the « À vérifier » line
 * the night leaves on the customer, read by its own company, does name it.
 */
final readonly class CustomerContactChecks implements DeclaresDataChecks
{
    public const string CONTACT_RULES = 'customers.contact_rules';

    public function __construct(private KeptCustomerContacts $kept)
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
        foreach ($this->kept->all() as $kept) {
            yield new DataFinding(self::CONTACT_RULES, $kept['company'], \sprintf(null === $kept['contact'] ? 'customer %s: %s' : 'contact of customer %s: %s', $kept['number'], $kept['field']));
        }
    }
}
