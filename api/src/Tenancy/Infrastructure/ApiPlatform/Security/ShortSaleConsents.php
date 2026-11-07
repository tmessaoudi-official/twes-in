<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Security;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Application\ShortSales\ShortSaleConsent;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Who sells, for « Sortie sans stock enregistré », as a request validating or issuing a document says it: the seller's
 * role in the company, whether they may confirm a sale beyond the stock, and whether the request confirms it
 * (`?shortOfStock=confirmed`, sent again once the person has read the short lines).
 */
final readonly class ShortSaleConsents
{
    public const string QUERY = 'shortOfStock';
    public const string CONFIRMED = 'confirmed';
    public const string DESCRIPTION = 'Where « Sortie sans stock enregistré » asks a sale beyond the stock recorded to be confirmed (422 `stock_short_to_confirm` on each short line\'s quantity), `confirmed` confirms it, for someone who holds `stock.sell_short`. Ignored where the sale is within the stock, allowed or refused (422 `stock_short`).';

    public function __construct(private CompanyGuard $guard, private MembershipRepository $memberships)
    {
    }

    /**
     * @param array<string, mixed> $context the operation's, which carries the request
     *
     * @throws ValidationException for a value other than `confirmed`
     */
    public function of(Company $company, array $context): ShortSaleConsent
    {
        $request = $context['request'] ?? null;
        $asked = $request instanceof Request ? $request->query->get(self::QUERY) : null;
        if (null !== $asked && self::CONFIRMED !== $asked) {
            throw DomainRule::refused(self::QUERY, \sprintf('%s, or left out.', self::CONFIRMED), 'not_one_of', ['choices' => self::CONFIRMED]);
        }
        $role = $this->memberships->ofUserInCompany($this->guard->account()->getId(), $company->getId())?->getRole()->getId();

        return new ShortSaleConsent($role, $this->guard->may($company, ShortSaleConsent::PERMISSION), self::CONFIRMED === $asked);
    }
}
