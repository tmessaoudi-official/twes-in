<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Parts of a company's data taken away at once for every member, and kept as a copy until the erasure's end, so that
 * the owner may put them all back until then; at the end the copy goes and nothing comes back. A company has at most
 * one erasure still waiting for its end, so the banner offering its undo always has exactly one thing to undo.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_erasure')]
#[ORM\UniqueConstraint(name: 'uniq_data_erasure_pending', columns: ['company_id'], options: ['where' => "((state)::text = 'pending'::text)"])]
#[ORM\Index(name: 'idx_data_erasure_due', columns: ['state', 'effective_at'])]
class DataErasure implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $parts;

    /** @var array<string, array<string, int>> how many of each thing went, by part */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $counts;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $erasedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $erasedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $effectiveAt;

    #[ORM\Column(length: 16, enumType: ErasureState::class)]
    private ErasureState $state = ErasureState::Pending;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * @param list<string>                      $parts
     * @param array<string, array<string, int>> $counts
     */
    public function __construct(Uuid $id, Company $company, array $parts, array $counts, ?Uuid $erasedBy, \DateTimeImmutable $now, \DateInterval $undoableFor)
    {
        if ([] === $parts || \count(array_unique($parts)) !== \count($parts)) {
            throw new \InvalidArgumentException('An erasure takes at least one part, each once.');
        }
        $this->id = $id;
        $this->company = $company;
        $this->parts = $parts;
        $this->counts = $counts;
        $this->erasedBy = $erasedBy;
        $this->erasedAt = $now;
        $this->effectiveAt = $now->add($undoableFor);
    }

    /** @param array<string, array<string, int>> $counts */
    public function recordCounts(array $counts): void
    {
        $this->counts = $counts;
    }

    /** Whether everything it took may still be put back: up to its end, not at it. */
    public function isUndoableAt(\DateTimeImmutable $now): bool
    {
        return ErasureState::Pending === $this->state && $now < $this->effectiveAt;
    }

    /** @throws ErasureNoLongerPending */
    public function undo(\DateTimeImmutable $now): void
    {
        if (!$this->isUndoableAt($now)) {
            throw new ErasureNoLongerPending('This erasure can no longer be undone.');
        }
        $this->state = ErasureState::Undone;
        $this->endedAt = $now;
    }

    /** Makes it final once its end has come, and says whether it did: its copy is then to be deleted. */
    public function finishIfDue(\DateTimeImmutable $now): bool
    {
        if (ErasureState::Pending !== $this->state || $now < $this->effectiveAt) {
            return false;
        }
        $this->state = ErasureState::Final;
        $this->endedAt = $now;

        return true;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    /** @return list<string> */
    public function getParts(): array
    {
        return $this->parts;
    }

    /** @return array<string, array<string, int>> */
    public function getCounts(): array
    {
        return $this->counts;
    }

    public function getErasedBy(): ?Uuid
    {
        return $this->erasedBy;
    }

    public function getErasedAt(): \DateTimeImmutable
    {
        return $this->erasedAt;
    }

    public function getEffectiveAt(): \DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function getState(): ErasureState
    {
        return $this->state;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }
}
