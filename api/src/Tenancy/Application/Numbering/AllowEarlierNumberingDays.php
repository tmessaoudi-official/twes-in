<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\NumberingSeries;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\NumberingSeriesRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The way back from a shop computer whose clock ran ahead: once the clock is right again, every document would be dated
 * before the last one numbered, and numbering refuses it. An operator lets every series the company numbers with take
 * such a day for a few hours, no longer than the installation allows; the sequence still never goes back into an
 * earlier period it starts again in. A shop's one computer moves every series at once, so every series is allowed.
 */
final readonly class AllowEarlierNumberingDays implements DeclaresAuditActions
{
    public const string ALLOWED = 'numbering_series.earlier_day_allowed';

    public function __construct(
        private CompanyRepository $companies,
        private NumberingSeriesRepository $series,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.numbering.earlier_days_hours_max')]
        private int $hoursMax,
    ) {
    }

    /**
     * @return \DateTimeImmutable until when every series of the company may take an earlier day
     *
     * @throws EarlierDaysRefused when the company is unknown or the hours are not between one and the installation's limit
     */
    public function handle(Uuid $companyId, int $hours, ?Uuid $actorUserId): \DateTimeImmutable
    {
        if ($hours < 1 || $hours > $this->hoursMax) {
            throw new EarlierDaysRefused(\sprintf('Give between 1 and %d hours (at most app.numbering.earlier_days_hours_max).', $this->hoursMax));
        }
        if (null === $this->companies->ofId($companyId)) {
            throw new EarlierDaysRefused(\sprintf('No company %s.', $companyId->toRfc4122()));
        }

        return $this->transactions->run(function () use ($companyId, $hours, $actorUserId): \DateTimeImmutable {
            $now = $this->clock->now();
            $until = $now->modify(\sprintf('+%d hours', $hours));
            $defaults = array_filter($this->series->ofCompany($companyId), static fn (NumberingSeries $one): bool => $one->isDefault());
            // One order for every caller, so two allowances at once never wait on each other's series.
            usort($defaults, static fn (NumberingSeries $a, NumberingSeries $b): int => [$a->getEstablishment()->getId()->toRfc4122(), $a->getDocumentType()] <=> [$b->getEstablishment()->getId()->toRfc4122(), $b->getDocumentType()]);
            foreach ($defaults as $one) {
                // Locked as an issue locks it, so a document numbered meanwhile is read before the allowance is written.
                $locked = $this->series->lockedDefaultFor($one->getEstablishment()->getId(), $one->getDocumentType()) ?? $one;
                $locked->allowEarlierDays($until, $now);
                $this->series->save($locked);
                $this->audit->record(new AuditEntry(ManageNumberingSeries::ENTITY_TYPE, $locked->getId(), self::ALLOWED, $actorUserId, ['earlier_day_allowed_until' => $until->format(\DATE_ATOM)], $companyId));
            }

            return $until;
        });
    }

    public function auditActions(): array
    {
        return [
            self::ALLOWED => new Recorded(ManageNumberingSeries::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
