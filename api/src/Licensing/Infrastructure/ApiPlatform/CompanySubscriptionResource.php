<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\Standing;
use App\Licensing\Domain\Subscription;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A company's own view of its subscription (docs/SPEC.md § 7, 2026-09-17): where it stands, what it costs, and the
 * payments it declared. A locked company still reads this and still declares a payment — that is the way out, and
 * `Access::ALWAYS` is what keeps it open. A company licensing does not manage answers 404: it owes nothing.
 */
#[ApiResource(
    shortName: 'CompanySubscription',
    operations: [
        // skip_null_values off: the answer carries the same keys whether a payment waits or not, so a reader never
        // has to tell "no open payment" from "the key was left out" (docs/SPEC.md § 7; Me does the same).
        new Get(
            uriTemplate: '/companies/{companyId}/subscription',
            provider: CompanySubscriptionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
        ),
    ],
)]
final class CompanySubscriptionResource
{
    public const string READ = 'company_subscription:read';
    public const string READ_PERMISSION = 'subscription.read';
    public const string PAY_PERMISSION = 'subscription.pay';
    /** How many past declarations the page shows. */
    public const int HISTORY = 10;

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $companyId = '';

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['trial', 'paid', 'grace', 'held', 'unpaid']])]
    #[Groups([self::READ])]
    public string $stage = '';

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['full', 'read_only', 'locked']])]
    #[Groups([self::READ])]
    public string $access = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $coveredUntil = '';

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $graceEndsAt = '';

    /** Whole days before the stage changes; null once unpaid. */
    #[ApiProperty(writable: false, required: true, schema: ['type' => ['integer', 'null']])]
    #[Groups([self::READ])]
    public ?int $daysLeft = null;

    /** The trial's last day, in the company's timezone. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $trialEndsOn = null;

    /** The last day paid for, in the company's timezone. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $paidThrough = null;

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'integer'])]
    #[Groups([self::READ])]
    public int $periodCount = 1;

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['day', 'month', 'year']])]
    #[Groups([self::READ])]
    public string $periodUnit = 'month';

    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null']])]
    #[Groups([self::READ])]
    public ?string $price = null;

    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'pattern' => '^[A-Z]{3}$'])]
    #[Groups([self::READ])]
    public ?string $currency = null;

    /** The payment waiting for the operator's decision, if one is. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public ?PaymentDeclarationResource $openPayment = null;

    /**
     * What this company declared before, newest first.
     *
     * @var list<PaymentDeclarationResource>
     */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public array $payments = [];

    /** Whether the caller may declare a payment now: the page offers it, the POST enforces it. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public bool $canDeclare = false;

    /**
     * @param list<PaymentDeclaration> $payments newest first
     */
    public static function of(Subscription $subscription, Standing $standing, ?PaymentDeclaration $open, array $payments, bool $mayPay): self
    {
        $company = $subscription->getCompany();
        $zone = new \DateTimeZone($company->getTimezone());
        $terms = $subscription->getTerms();

        $resource = new self();
        $resource->companyId = $company->getId()->toRfc4122();
        $resource->stage = $standing->stage->value;
        $resource->access = $standing->access->value;
        $resource->coveredUntil = $standing->coveredUntil->format(\DATE_ATOM);
        $resource->graceEndsAt = $standing->graceEndsAt->format(\DATE_ATOM);
        $resource->daysLeft = $standing->daysLeft;
        $resource->trialEndsOn = $terms->trialEndsAt?->setTimezone($zone)->format('Y-m-d');
        $resource->paidThrough = $terms->paidUntil?->setTimezone($zone)->format('Y-m-d');
        $resource->periodCount = $terms->period->count;
        $resource->periodUnit = $terms->period->unit->value;
        $resource->price = $terms->price;
        $resource->currency = $terms->currency;
        $resource->openPayment = null === $open ? null : PaymentDeclarationResource::of($open);
        $resource->payments = array_map(PaymentDeclarationResource::of(...), $payments);
        $resource->canDeclare = $mayPay && null === $open;

        return $resource;
    }
}
