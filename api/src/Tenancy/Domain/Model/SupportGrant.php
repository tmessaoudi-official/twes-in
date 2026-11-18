<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain\Model;

use App\Identity\Domain\Model\User;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Exception\InvalidSupportReason;
use App\Tenancy\Domain\Exception\InvalidSupportWindow;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * « Accès support »: the owner lets the platform's operators read the company for a while. Live from when it was
 * granted until its end, unless someone ended it first; what is live is decided from these dates on each request, never
 * by a job, so a window closes on time whether anything runs or not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_grant')]
#[ORM\Index(name: 'idx_support_grant_company_ends', columns: ['company_id', 'ends_at'])]
class SupportGrant
{
    /** The windows ruled, in hours: the operator may offer fewer or shorter ones (app.support.windows_hours), never longer. */
    public const array HOURS = [1, 24, 72];
    /** The longest « what is wrong » an owner may write, in characters. */
    public const int REASON_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'granted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $grantedBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $endsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'ended_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $endedBy = null;

    /** The one operator the owner opened it to; null when it names no one, or once that account is deleted. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'operator_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $operator;

    /**
     * Whether it names an operator, kept apart from whom: the grant is a record the company keeps, so deleting that
     * account clears the name and keeps the row, and a grant that named someone then opens to no one, never to all.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $operatorNamed;

    /** What the owner says is wrong, so support opens the company knowing where to look; null when they said nothing. */
    #[ORM\Column(length: self::REASON_MAX, nullable: true)]
    private ?string $reason;

    /**
     * @param list<int> $offered  the windows the operator offers, HOURS unless it says otherwise
     * @param ?User     $operator the one operator it opens to, whom the application has found able to read it; null for every operator
     *
     * @throws InvalidSupportWindow when the hours are not one offered, or longer than the longest of HOURS
     * @throws InvalidSupportReason when the reason is longer than REASON_MAX
     */
    public function __construct(Company $company, User $grantedBy, int $hours, \DateTimeImmutable $now, ?string $reason = null, array $offered = self::HOURS, ?User $operator = null)
    {
        if (!\in_array($hours, $offered, true) || $hours < 1 || $hours > max(self::HOURS)) {
            throw new InvalidSupportWindow(\sprintf('Support access lasts %s hours, not %d.', implode(', ', $offered), $hours));
        }
        $reason = TypedText::trimmedOrNull($reason);
        if (null !== $reason && mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidSupportReason(\sprintf('What is wrong is said in at most %d characters.', self::REASON_MAX));
        }
        $this->reason = $reason;
        $this->operator = $operator;
        $this->operatorNamed = null !== $operator;
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->grantedBy = $grantedBy;
        $this->startsAt = $now;
        $this->endsAt = $now->modify(\sprintf('+%d hours', $hours));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getOperator(): ?User
    {
        return $this->operator;
    }

    /** Whether it named an operator whose account was deleted since: it then opens to no one. */
    public function lostItsOperator(): bool
    {
        return $this->operatorNamed && null === $this->operator;
    }

    /** Whether this account is one the grant opens to: anyone when it names no one, else the one it names, if still there. */
    public function opensTo(Uuid $userId): bool
    {
        if (!$this->operatorNamed) {
            return true;
        }

        return null !== $this->operator && $this->operator->getId()->equals($userId);
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function isLiveAt(\DateTimeImmutable $now): bool
    {
        return null === $this->endedAt && $this->startsAt <= $now && $now < $this->endsAt;
    }

    /** Ends it now; a grant no longer live is left as it ended. */
    public function end(User $by, \DateTimeImmutable $now): void
    {
        if (!$this->isLiveAt($now)) {
            return;
        }
        $this->endedAt = $now;
        $this->endedBy = $by;
    }
}
