<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;

/**
 * The vendors' addresses and phones that today's rules refuse: kept from before a rule, they stay until someone changes
 * them, and this lists each so it is corrected. The detail names the vendor and the field, never the value, since the
 * operators read it; the « À vérifier » line the night leaves on the vendor, read by its own company, does name it.
 */
final readonly class VendorContactChecks implements DeclaresDataChecks
{
    public const string CONTACT_RULES = 'vendors.contact_rules';

    public function __construct(private KeptVendorContacts $kept)
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
        foreach ($this->kept->all() as $kept) {
            yield new DataFinding(self::CONTACT_RULES, $kept['company'], \sprintf('vendor %s: %s', $kept['number'], $kept['field']));
        }
    }
}
