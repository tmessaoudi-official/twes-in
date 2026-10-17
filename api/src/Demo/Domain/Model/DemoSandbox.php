<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Domain\Model;

use App\Demo\Domain\ValueObject\SandboxState;
use App\Identity\Domain\Model\User;
use App\Tenancy\Domain\Model\Company;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One demo sandbox: a company written with a demo history and owned by an account made for it alone, built ahead by the
 * worker and taken by one visitor. It has no company until the worker opens one, which is why its company may be null
 * and why the company filter leaves it alone: it is read across companies, by the worker and by a visitor's claim.
 * Removing its company removes it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_sandbox')]
#[ORM\Index(name: 'idx_demo_sandbox_country_state', columns: ['country', 'state'])]
class DemoSandbox
{
    /** The countries a visitor may pick, each with its company in the demo catalogue. */
    public const array COUNTRIES = ['TN', 'FR'];

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 2)]
    private string $country;

    #[ORM\Column(length: 16, enumType: SandboxState::class)]
    private SandboxState $state = SandboxState::Queued;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: true, onDelete: 'CASCADE')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: true, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $queuedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $buildStartedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $claimedAt = null;

    public function __construct(Uuid $id, string $country, \DateTimeImmutable $now)
    {
        if (!\in_array($country, self::COUNTRIES, true)) {
            throw new \InvalidArgumentException(\sprintf('There is no demo for the country "%s".', $country));
        }
        $this->id = $id;
        $this->country = $country;
        $this->queuedAt = $now;
    }

    /** @throws \DomainException unless it is waiting for the worker */
    public function startBuilding(\DateTimeImmutable $now): void
    {
        $this->moveFrom(SandboxState::Queued, SandboxState::Building);
        $this->buildStartedAt = $now;
    }

    /** @throws \DomainException unless it is being built and has no company yet */
    public function opened(Company $company, User $owner): void
    {
        if (SandboxState::Building !== $this->state || null !== $this->company) {
            throw new \DomainException('A sandbox is opened once, while it is being built.');
        }
        $this->company = $company;
        $this->owner = $owner;
    }

    /** @throws \DomainException unless it is being built and was opened */
    public function ready(\DateTimeImmutable $now): void
    {
        if (null === $this->company) {
            throw new \DomainException('A sandbox with no company is not ready.');
        }
        $this->moveFrom(SandboxState::Building, SandboxState::Ready);
        $this->readyAt = $now;
    }

    /** @throws \DomainException unless it is ready */
    public function claim(\DateTimeImmutable $now): void
    {
        $this->moveFrom(SandboxState::Ready, SandboxState::Claimed);
        $this->claimedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getState(): SandboxState
    {
        return $this->state;
    }

    public function getCompanyId(): ?Uuid
    {
        return $this->company?->getId();
    }

    public function getOwnerId(): ?Uuid
    {
        return $this->owner?->getId();
    }

    private function moveFrom(SandboxState $from, SandboxState $to): void
    {
        if ($from !== $this->state) {
            throw new \DomainException(\sprintf('A sandbox %s cannot become %s.', $this->state->value, $to->value));
        }
        $this->state = $to;
    }
}
