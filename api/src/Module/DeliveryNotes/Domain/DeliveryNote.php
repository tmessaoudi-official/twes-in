<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Module\Customers\Domain\Customer;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Goods an establishment of a company hands to a customer (docs/SPEC.md § 4 delivery_note). A draft names its
 * establishment, its customer and its lines, every one of them its own company's, and changes freely; the number,
 * the issue day and what the customer was called that day arrive with validation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_note')]
#[ORM\Index(name: 'idx_delivery_note_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_delivery_note_establishment', columns: ['establishment_id'])]
#[ORM\Index(name: 'idx_delivery_note_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_delivery_note_company_number', columns: ['company_id', 'number'])]
class DeliveryNote
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Establishment $establishment;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false)]
    private Customer $customer;

    #[ORM\Column(length: 16, enumType: DeliveryNoteStatus::class)]
    private DeliveryNoteStatus $status = DeliveryNoteStatus::Draft;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deliveryDate = null;

    #[ORM\Embedded(class: PostalAddress::class, columnPrefix: 'delivery_')]
    private PostalAddress $deliveryAddress;

    #[ORM\Column(length: DeliveryNoteHeader::REFERENCE_MAX, nullable: true)]
    private ?string $customerReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remarksPrinted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesInternal = null;

    /** @var Collection<int, DeliveryNoteLine> */
    #[ORM\OneToMany(targetEntity: DeliveryNoteLine::class, mappedBy: 'deliveryNote', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->lines = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<DeliveryNoteLineDetails> $lines
     *
     * @throws InvalidDeliveryNote
     */
    public static function create(Company $company, Establishment $establishment, Customer $customer, DeliveryNoteHeader $header, array $lines, \DateTimeImmutable $now): self
    {
        $note = new self($company, $now);
        $note->establishment = $note->establishmentOfThisCompany($establishment);
        $note->customer = $note->customerOfThisCompany($customer);
        $note->apply($header);
        $note->writeLines($note->linesOfThisCompany($lines));

        return $note;
    }

    /**
     * @param list<DeliveryNoteLineDetails> $lines
     *
     * @return list<string> the fields that changed, none when the revision says what the note already says
     *
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     */
    public function revise(Establishment $establishment, Customer $customer, DeliveryNoteHeader $header, array $lines, \DateTimeImmutable $now): array
    {
        if (DeliveryNoteStatus::Draft !== $this->status) {
            throw new DeliveryNoteNotDraft(\sprintf('The delivery note %s is %s: only a draft changes.', $this->number ?? $this->id->toRfc4122(), $this->status->value));
        }
        $establishment = $this->establishmentOfThisCompany($establishment);
        $customer = $this->customerOfThisCompany($customer);
        $lines = $this->linesOfThisCompany($lines);

        $changed = [];
        if (!$establishment->getId()->equals($this->establishment->getId())) {
            $changed[] = 'establishmentId';
        }
        if (!$customer->getId()->equals($this->customer->getId())) {
            $changed[] = 'customerId';
        }
        $changed = [...$changed, ...$header->differencesFrom($this->getHeader())];
        $linesChanged = array_map(static fn (DeliveryNoteLineDetails $line): array => $line->values(), $lines) !== array_map(static fn (DeliveryNoteLine $line): array => $line->values(), $this->getLines());
        if ($linesChanged) {
            $changed[] = 'lines';
        }
        if ([] === $changed) {
            return [];
        }

        $this->establishment = $establishment;
        $this->customer = $customer;
        $this->apply($header);
        if ($linesChanged) {
            $this->writeLines($lines);
        }
        $this->updatedAt = $now;

        return $changed;
    }

    public function getHeader(): DeliveryNoteHeader
    {
        return new DeliveryNoteHeader($this->deliveryDate, $this->deliveryAddress, $this->customerReference, $this->remarksPrinted, $this->notesInternal);
    }

    /** @return list<DeliveryNoteLine> in order */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    private function apply(DeliveryNoteHeader $header): void
    {
        $this->deliveryDate = $header->deliveryDate;
        $this->deliveryAddress = $header->deliveryAddress;
        $this->customerReference = $header->customerReference;
        $this->remarksPrinted = $header->remarksPrinted;
        $this->notesInternal = $header->notesInternal;
    }

    /** @param list<DeliveryNoteLineDetails> $lines */
    private function writeLines(array $lines): void
    {
        $this->lines->clear();
        foreach ($lines as $index => $details) {
            $this->lines->add(new DeliveryNoteLine($this, $index + 1, $details));
        }
    }

    private function establishmentOfThisCompany(Establishment $establishment): Establishment
    {
        if (!$establishment->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidDeliveryNote('establishmentId', 'A delivery note leaves an establishment of its own company.');
        }

        return $establishment;
    }

    private function customerOfThisCompany(Customer $customer): Customer
    {
        if (!$customer->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidDeliveryNote('customerId', 'A delivery note goes to a customer of its own company.');
        }

        return $customer;
    }

    /**
     * @param list<DeliveryNoteLineDetails> $lines
     *
     * @return list<DeliveryNoteLineDetails>
     */
    private function linesOfThisCompany(array $lines): array
    {
        $companyId = $this->company->getId();
        foreach ($lines as $index => $line) {
            if (null !== $line->product && !$line->product->getCompany()->getId()->equals($companyId)) {
                throw new InvalidDeliveryNote("lines[$index].productId", 'A line delivers a product of its own company.');
            }
            if (!$line->unit->getCompany()->getId()->equals($companyId)) {
                throw new InvalidDeliveryNote("lines[$index].unitId", 'A line counts in a unit of its own company.');
            }
            foreach ($line->taxes as $tax) {
                if (!$tax->getCompany()->getId()->equals($companyId)) {
                    throw new InvalidDeliveryNote("lines[$index].taxComponentIds", 'A line carries taxes of its own company.');
                }
            }
        }

        return $lines;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getStatus(): DeliveryNoteStatus
    {
        return $this->status;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function getIssueDate(): ?\DateTimeImmutable
    {
        return $this->issueDate;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
