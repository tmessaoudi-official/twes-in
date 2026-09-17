<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Licensing\Domain\BillingPeriod;
use App\Licensing\Domain\InvalidSubscription;
use App\Licensing\Domain\PeriodUnit;
use App\Licensing\Domain\Standing;
use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionTerms;
use App\Licensing\Domain\UnpaidMode;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A company's subscription as its operators set it (docs/SPEC.md § 7, 2026-09-17). Dates are days in the company's
 * timezone, each covered to its last second; the standing is read back with the terms. A company licensing does not
 * manage answers 404 until an operator puts terms on it, and again once one stops managing it.
 */
#[ApiResource(
    shortName: 'PlatformSubscription',
    operations: [
        new Get(
            uriTemplate: '/platform/companies/{companyId}/subscription',
            provider: PlatformSubscriptionProvider::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Put(
            uriTemplate: '/platform/companies/{companyId}/subscription',
            read: false,
            processor: SetSubscriptionProcessor::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/platform/companies/{companyId}/subscription',
            read: false,
            processor: StopSubscriptionProcessor::class,
            security: 'is_granted("'.self::PERMISSION.'")',
        ),
    ],
)]
final class PlatformSubscriptionResource
{
    public const string PERMISSION = 'platform.licensing.manage';
    public const string READ = 'platform_subscription:read';
    public const string WRITE = 'platform_subscription:write';
    private const string DAY = '/^\d{4}-\d{2}-\d{2}$/';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $companyId = '';

    #[ApiProperty(required: true, schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => BillingPeriod::MAX_COUNT])]
    #[Groups([self::READ, self::WRITE])]
    public int $periodCount = 1;

    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['day', 'month', 'year']])]
    #[Groups([self::READ, self::WRITE])]
    public string $periodUnit = 'month';

    /** The trial's last day, in the company's timezone. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $trialEndsOn = null;

    /** The last day paid for, in the company's timezone. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $paidThrough = null;

    /** The price of one period, a decimal string; with its currency or not at all. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9]\d{0,9})(\.\d{1,3})?$'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $price = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'pattern' => '^[A-Z]{3}$'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $currency = null;

    /** This company's grace days; null follows the platform's. */
    #[ApiProperty(schema: ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => SubscriptionTerms::MAX_GRACE_DAYS])]
    #[Groups([self::READ, self::WRITE])]
    public ?int $graceDays = null;

    /** What this company gets once grace ends unpaid; null follows the platform's. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'enum' => ['read_only', 'locked', null]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $unpaidMode = null;

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['trial', 'paid', 'grace', 'unpaid']])]
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

    #[ApiProperty(writable: false, schema: ['type' => ['integer', 'null']])]
    #[Groups([self::READ])]
    public ?int $daysLeft = null;

    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $updatedAt = '';

    public static function of(Subscription $subscription, Standing $standing): self
    {
        $company = $subscription->getCompany();
        $zone = new \DateTimeZone($company->getTimezone());
        $terms = $subscription->getTerms();

        $resource = new self();
        $resource->companyId = $company->getId()->toRfc4122();
        $resource->periodCount = $terms->period->count;
        $resource->periodUnit = $terms->period->unit->value;
        $resource->trialEndsOn = $terms->trialEndsAt?->setTimezone($zone)->format('Y-m-d');
        $resource->paidThrough = $terms->paidUntil?->setTimezone($zone)->format('Y-m-d');
        $resource->price = $terms->price;
        $resource->currency = $terms->currency;
        $resource->graceDays = $terms->graceDays;
        $resource->unpaidMode = $terms->unpaidMode?->value;
        $resource->stage = $standing->stage->value;
        $resource->access = $standing->access->value;
        $resource->coveredUntil = $standing->coveredUntil->format(\DATE_ATOM);
        $resource->graceEndsAt = $standing->graceEndsAt->format(\DATE_ATOM);
        $resource->daysLeft = $standing->daysLeft;
        $resource->updatedAt = $subscription->getUpdatedAt()->format(\DATE_ATOM);

        return $resource;
    }

    /** @throws InvalidSubscription for a day that is not one, or a unit or a mode the domain does not know */
    public function terms(string $timezone): SubscriptionTerms
    {
        $zone = new \DateTimeZone($timezone);
        $unit = PeriodUnit::tryFrom($this->periodUnit) ?? throw new InvalidSubscription('periodUnit: day, month or year.');
        $mode = null === $this->unpaidMode ? null : (UnpaidMode::tryFrom($this->unpaidMode) ?? throw new InvalidSubscription('unpaidMode: read_only or locked.'));

        return new SubscriptionTerms(
            new BillingPeriod($this->periodCount, $unit),
            self::endOf('trialEndsOn', $this->trialEndsOn, $zone),
            self::endOf('paidThrough', $this->paidThrough, $zone),
            $this->price,
            $this->currency,
            $this->graceDays,
            $mode,
        );
    }

    /** The last second of a day in the zone. */
    private static function endOf(string $field, ?string $day, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (null === $day) {
            return null;
        }
        $start = 1 === preg_match(self::DAY, $day) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $zone) : false;
        if (false === $start || $start->format('Y-m-d') !== $day) {
            throw new InvalidSubscription(\sprintf('%s: a day written YYYY-MM-DD.', $field));
        }

        return $start->modify('+1 day')->modify('-1 second');
    }
}
