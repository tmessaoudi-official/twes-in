<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A company's subscription (docs/SPEC.md § 7, 2026-09-17): its terms and, derived from them at any instant, where it
 * stands. Nothing flips a flag when a date passes: the standing is computed from the dates on every request. A company
 * without one is not managed by licensing and keeps full access. Only platform operators change it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'subscription')]
#[ORM\UniqueConstraint(name: 'uniq_subscription_company', columns: ['company_id'])]
class Subscription implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $periodCount;

    #[ORM\Column(length: 8)]
    private string $periodUnit;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidUntil;

    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 3, nullable: true)]
    private ?string $price;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $currency;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $graceDays;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $unpaidMode;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Company $company, SubscriptionTerms $terms, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->hold($terms);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function revise(SubscriptionTerms $terms, \DateTimeImmutable $now): void
    {
        $this->hold($terms);
        $this->updatedAt = $now;
    }

    public function standingAt(\DateTimeImmutable $now, LicensingDefaults $defaults): Standing
    {
        $terms = $this->getTerms();
        $coveredUntil = $terms->coveredUntil();
        $graceEndsAt = $coveredUntil->modify(\sprintf('+%d days', $terms->graceDays ?? $defaults->graceDays));

        if ($now <= $coveredUntil) {
            $stage = null !== $terms->paidUntil && $terms->paidUntil >= $coveredUntil ? Stage::Paid : Stage::Trial;

            return new Standing($stage, Access::Full, $coveredUntil, $graceEndsAt, self::daysBetween($now, $coveredUntil));
        }
        if ($now <= $graceEndsAt) {
            return new Standing(Stage::Grace, Access::Full, $coveredUntil, $graceEndsAt, self::daysBetween($now, $graceEndsAt));
        }
        $access = match ($terms->unpaidMode ?? $defaults->unpaidMode) {
            UnpaidMode::ReadOnly => Access::ReadOnly,
            UnpaidMode::Locked => Access::Locked,
        };

        return new Standing(Stage::Unpaid, $access, $coveredUntil, $graceEndsAt, null);
    }

    public function getTerms(): SubscriptionTerms
    {
        return new SubscriptionTerms(
            new BillingPeriod($this->periodCount, PeriodUnit::from($this->periodUnit)),
            $this->trialEndsAt,
            $this->paidUntil,
            $this->price,
            $this->currency,
            $this->graceDays,
            null === $this->unpaidMode ? null : UnpaidMode::from($this->unpaidMode),
        );
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function hold(SubscriptionTerms $terms): void
    {
        $this->periodCount = $terms->period->count;
        $this->periodUnit = $terms->period->unit->value;
        $this->trialEndsAt = $terms->trialEndsAt;
        $this->paidUntil = $terms->paidUntil;
        $this->price = $terms->price;
        $this->currency = $terms->currency;
        $this->graceDays = $terms->graceDays;
        $this->unpaidMode = $terms->unpaidMode?->value;
    }

    /** Whole days from one instant to a later one, a started day counting as one. */
    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) ceil(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }
}
