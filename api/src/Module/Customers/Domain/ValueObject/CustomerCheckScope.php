<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain\ValueObject;

/**
 * What « À vérifier » asks of a company's customers: a business in the company's own country carries every number its
 * preset requires of one, as the invoice it is issued will.
 */
final readonly class CustomerCheckScope
{
    /**
     * @param string       $countryCode        the seller's, which a customer's billing country is domestic to
     * @param list<string> $requiredOfBusiness the identifier keys the preset requires of a business customer
     */
    public function __construct(public string $countryCode, public array $requiredOfBusiness)
    {
    }
}
