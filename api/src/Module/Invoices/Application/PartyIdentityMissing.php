<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * An invoice cannot name one of its parties as the law asks, so issuing is refused before it takes a number: a numbered
 * invoice cannot be corrected but by a credit note. `party` is `seller` (the company's profile or the issuing
 * establishment) or `customer`; `missing` the fields as that party's form names them.
 */
final class PartyIdentityMissing extends \DomainException
{
    public const string SELLER = 'seller';
    public const string CUSTOMER = 'customer';

    /** @param list<string> $missing */
    public function __construct(public readonly string $party, public readonly array $missing)
    {
        parent::__construct(\sprintf('An invoice cannot name its %s without %s.', $party, implode(', ', $missing)));
    }
}
