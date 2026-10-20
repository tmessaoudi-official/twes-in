<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\Model;

use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Records\Domain\ValueObject\RemovalState;
use App\Shared\Domain\Service\CompanyOwned;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Records of one subject a person deleted at once, kept as a copy for a day on « Récemment supprimés », from where they
 * all come back together; at its end the copy goes and the delete is final. Any number may wait at once. The records
 * are named as they read when they went, the only thing the page shows of them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'record_removal')]
#[ORM\Index(name: 'idx_record_removal_due', columns: ['state', 'effective_at'])]
#[ORM\Index(name: 'idx_record_removal_company_state', columns: ['company_id', 'state'])]
class RecordRemoval implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    /** The subject's table. */
    #[ORM\Column(length: 63)]
    private string $subject;

    /** @var list<array{id: string, label: string}> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $items;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $removedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $removedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $effectiveAt;

    #[ORM\Column(length: 16, enumType: RemovalState::class)]
    private RemovalState $state = RemovalState::Pending;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** @param non-empty-list<array{id: string, label: string}> $items */
    public function __construct(Uuid $id, Company $company, string $subject, array $items, ?Uuid $removedBy, \DateTimeImmutable $now, \DateInterval $undoableFor)
    {
        $this->id = $id;
        $this->company = $company;
        $this->subject = $subject;
        $this->items = $items;
        $this->removedBy = $removedBy;
        $this->removedAt = $now;
        $this->effectiveAt = $now->add($undoableFor);
    }

    /** Pending and within its day, even when the worker has not yet ended it. */
    public function isRestorableAt(\DateTimeImmutable $now): bool
    {
        return RemovalState::Pending === $this->state && $now < $this->effectiveAt;
    }

    /** @throws RemovalNoLongerPending once restored or past its day, even before the worker ended it */
    public function restore(\DateTimeImmutable $now): void
    {
        if (!$this->isRestorableAt($now)) {
            throw new RemovalNoLongerPending('This delete can no longer be undone.');
        }
        $this->state = RemovalState::Restored;
        $this->endedAt = $now;
    }

    public function end(\DateTimeImmutable $now): void
    {
        if (RemovalState::Pending === $this->state) {
            $this->state = RemovalState::Final;
            $this->endedAt = $now;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    /** @return list<array{id: string, label: string}> */
    public function getItems(): array
    {
        return $this->items;
    }

    /** @return list<Uuid> */
    public function recordIds(): array
    {
        return array_map(static fn (array $item): Uuid => Uuid::fromString($item['id']), $this->items);
    }

    public function getRemovedBy(): ?Uuid
    {
        return $this->removedBy;
    }

    public function getRemovedAt(): \DateTimeImmutable
    {
        return $this->removedAt;
    }

    public function getEffectiveAt(): \DateTimeImmutable
    {
        return $this->effectiveAt;
    }

    public function getState(): RemovalState
    {
        return $this->state;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }
}
