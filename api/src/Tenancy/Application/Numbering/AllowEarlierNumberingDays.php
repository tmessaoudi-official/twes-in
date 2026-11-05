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
use App\Shared\Domain\Service\TypedText;
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
 * Given too long or to the wrong company, it is ended at once. The trail says why, and what the server's clock read
 * when it was given or ended, the very thing in doubt when a clock ran ahead.
 */
final readonly class AllowEarlierNumberingDays implements DeclaresAuditActions
{
    public const string ALLOWED = 'numbering_series.earlier_day_allowed';
    public const string ENDED = 'numbering_series.earlier_day_ended';
    /** As long as a credit note's reason: a sentence, never a file. */
    private const int REASON_MAX = 500;

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
     * @throws EarlierDaysRefused when the company is unknown, the hours are not between one and the installation's limit,
     *                            or no reason is given
     */
    public function handle(Uuid $companyId, int $hours, string $reason, ?Uuid $actorUserId): \DateTimeImmutable
    {
        if ($hours < 1 || $hours > $this->hoursMax) {
            throw new EarlierDaysRefused(\sprintf('Give between 1 and %d hours (at most app.numbering.earlier_days_hours_max).', $this->hoursMax));
        }
        $reason = $this->checked($companyId, $reason);

        return $this->transactions->run(function () use ($companyId, $hours, $reason, $actorUserId): \DateTimeImmutable {
            $now = $this->clock->now();
            $until = $now->modify(\sprintf('+%d hours', $hours));
            foreach ($this->lockedDefaults($companyId) as $locked) {
                $locked->allowEarlierDays($until, $now);
                $this->series->save($locked);
                $this->audit->record(new AuditEntry(ManageNumberingSeries::ENTITY_TYPE, $locked->getId(), self::ALLOWED, $actorUserId, ['earlier_day_allowed_until' => $until->format(\DATE_ATOM), 'reason' => $reason, 'clock_read' => $now->format(\DATE_ATOM)], $companyId));
            }

            return $until;
        });
    }

    /**
     * Ends at once the allowance every series of the company is under: an earlier day is refused again.
     *
     * @return int how many series it was in force on, none when it had run out or was never given
     *
     * @throws EarlierDaysRefused when the company is unknown or no reason is given
     */
    public function end(Uuid $companyId, string $reason, ?Uuid $actorUserId): int
    {
        $reason = $this->checked($companyId, $reason);

        return $this->transactions->run(function () use ($companyId, $reason, $actorUserId): int {
            $now = $this->clock->now();
            $ended = 0;
            foreach ($this->lockedDefaults($companyId) as $locked) {
                if (!$locked->endEarlierDays($now)) {
                    continue;
                }
                $this->series->save($locked);
                $this->audit->record(new AuditEntry(ManageNumberingSeries::ENTITY_TYPE, $locked->getId(), self::ENDED, $actorUserId, ['reason' => $reason, 'clock_read' => $now->format(\DATE_ATOM)], $companyId));
                ++$ended;
            }

            return $ended;
        });
    }

    public function auditActions(): array
    {
        return [
            self::ALLOWED => new Recorded(ManageNumberingSeries::ENTITY_TYPE, AuditScope::Company),
            self::ENDED => new Recorded(ManageNumberingSeries::ENTITY_TYPE, AuditScope::Company),
        ];
    }

    /** @throws EarlierDaysRefused */
    private function checked(Uuid $companyId, string $reason): string
    {
        $reason = TypedText::trimmed($reason);
        if ('' === $reason || mb_strlen($reason) > self::REASON_MAX) {
            throw new EarlierDaysRefused(\sprintf('Say why, in at most %d characters: the reason goes in the company\'s trail.', self::REASON_MAX));
        }
        if (null === $this->companies->ofId($companyId)) {
            throw new EarlierDaysRefused(\sprintf('No company %s.', $companyId->toRfc4122()));
        }

        return $reason;
    }

    /**
     * Every series the company numbers with, each locked as an issue locks it, so a document numbered meanwhile is read
     * first; in one order for every caller, so two operators at once never wait on each other's series.
     *
     * @return list<NumberingSeries>
     */
    private function lockedDefaults(Uuid $companyId): array
    {
        $defaults = array_filter($this->series->ofCompany($companyId), static fn (NumberingSeries $one): bool => $one->isDefault());
        usort($defaults, static fn (NumberingSeries $a, NumberingSeries $b): int => [$a->getEstablishment()->getId()->toRfc4122(), $a->getDocumentType()] <=> [$b->getEstablishment()->getId()->toRfc4122(), $b->getDocumentType()]);

        return array_map(fn (NumberingSeries $one): NumberingSeries => $this->series->lockedDefaultFor($one->getEstablishment()->getId(), $one->getDocumentType()) ?? $one, $defaults);
    }
}
