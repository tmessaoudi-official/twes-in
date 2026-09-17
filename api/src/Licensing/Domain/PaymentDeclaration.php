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
 * A payment a company says it made, waiting for an operator's decision (docs/SPEC.md § 7, 2026-09-17). Many are paid in
 * cash, so nothing here takes money: the company declares, the operator confirms or rejects, and only a confirmation
 * carries the subscription's covered time forward. Every declaration is kept, decided or not, as the ledger of what was
 * said and answered.
 */
#[ORM\Entity]
#[ORM\Table(name: 'payment_declaration')]
#[ORM\Index(name: 'idx_payment_declaration_company', columns: ['company_id', 'declared_at'])]
#[ORM\Index(name: 'idx_payment_declaration_status', columns: ['status', 'declared_at'])]
// One declaration waits per company, held by the database and not only by the use case: two requests at once cannot
// both find none open. Partial, so the decided ones — the ledger — are free to repeat. The predicate is written the
// way PostgreSQL stores it, since that is what the schema comparison reads back (SchemaInSyncTest).
#[ORM\UniqueConstraint(name: 'uniq_payment_declaration_open', columns: ['company_id'], options: ['where' => "((status)::text = 'declared'::text)"])]
class PaymentDeclaration implements CompanyOwned
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(type: Types::DECIMAL, precision: 13, scale: 3)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 16)]
    private string $method;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $paidOn;

    #[ORM\Column(length: DeclaredPayment::REFERENCE_MAX, nullable: true)]
    private ?string $reference;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(type: 'uuid')]
    private Uuid $declaredBy;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $declaredAt;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $decidedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    public function __construct(Company $company, DeclaredPayment $payment, Uuid $declaredBy, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->amount = $payment->amount;
        $this->currency = $payment->currency;
        $this->method = $payment->method->value;
        $this->paidOn = $payment->paidOn;
        $this->reference = self::trimmed($payment->reference);
        $this->note = self::trimmed($payment->note);
        $this->status = DeclarationStatus::Declared->value;
        $this->declaredBy = $declaredBy;
        $this->declaredAt = $now;
    }

    /** @throws InvalidPayment when it was already decided: a decision is made once. */
    public function confirm(Uuid $operatorId, \DateTimeImmutable $now, ?string $note): void
    {
        $this->decide(DeclarationStatus::Confirmed, $operatorId, $now, $note);
    }

    /** @throws InvalidPayment when it was already decided */
    public function reject(Uuid $operatorId, \DateTimeImmutable $now, ?string $note): void
    {
        $this->decide(DeclarationStatus::Rejected, $operatorId, $now, $note);
    }

    public function isOpen(): bool
    {
        return DeclarationStatus::Declared === $this->getStatus();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->method);
    }

    public function getPaidOn(): \DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getStatus(): DeclarationStatus
    {
        return DeclarationStatus::from($this->status);
    }

    public function getDeclaredBy(): Uuid
    {
        return $this->declaredBy;
    }

    public function getDeclaredAt(): \DateTimeImmutable
    {
        return $this->declaredAt;
    }

    public function getDecidedBy(): ?Uuid
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    private function decide(DeclarationStatus $status, Uuid $operatorId, \DateTimeImmutable $now, ?string $note): void
    {
        if (!$this->isOpen()) {
            throw new InvalidPayment('This payment was already decided.');
        }
        $this->status = $status->value;
        $this->decidedBy = $operatorId;
        $this->decidedAt = $now;
        $this->decisionNote = self::trimmed($note);
    }

    private static function trimmed(?string $value): ?string
    {
        $trimmed = trim($value ?? '');

        return '' === $trimmed ? null : $trimmed;
    }
}
