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
    /** The windows an owner may choose from, in hours. */
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

    /** What the owner says is wrong, so support opens the company knowing where to look; null when they said nothing. */
    #[ORM\Column(length: self::REASON_MAX, nullable: true)]
    private ?string $reason;

    /**
     * @throws InvalidSupportWindow when the hours are not one of HOURS
     * @throws InvalidSupportReason when the reason is longer than REASON_MAX
     */
    public function __construct(Company $company, User $grantedBy, int $hours, \DateTimeImmutable $now, ?string $reason = null)
    {
        if (!\in_array($hours, self::HOURS, true)) {
            throw new InvalidSupportWindow(\sprintf('Support access lasts %s hours, not %d.', implode(', ', self::HOURS), $hours));
        }
        $reason = TypedText::trimmedOrNull($reason);
        if (null !== $reason && mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidSupportReason(\sprintf('What is wrong is said in at most %d characters.', self::REASON_MAX));
        }
        $this->reason = $reason;
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
