<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Module\Customers\Domain\Customer;
use App\Shared\Domain\DomainEvent;
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

    /** @var array<string, mixed>|null CustomerSnapshot::toArray(), written by validation */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $customerSnapshot = null;

    /** @var list<DomainEvent> recorded since they were last released; never stored */
    private array $events = [];

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
        $this->assertDraft('changes');
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

    /**
     * Gives a draft with lines its number and issue day. From then on it prints what its customer was called that day,
     * the rates its taxes had that day, and, when it names no delivery address, where the customer takes goods
     * (its shipping address, else its billing one). Records `delivery_note.validated`.
     *
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     */
    public function validate(string $number, \DateTimeImmutable $issueDate, \DateTimeImmutable $now): void
    {
        $this->assertDraft('is validated');
        if ($this->lines->isEmpty()) {
            throw new InvalidDeliveryNote('lines', 'A delivery note is validated with at least one line.');
        }

        foreach ($this->getLines() as $line) {
            $line->retakeTaxes();
        }
        if ($this->deliveryAddress->isEmpty()) {
            $profile = $this->customer->getProfile();
            $address = null === $profile->shippingAddress || $profile->shippingAddress->isEmpty() ? $profile->billingAddress : $profile->shippingAddress;
            $this->deliveryAddress = new PostalAddress(...$address->parts());
        }
        $this->customerSnapshot = CustomerSnapshot::of($this->customer)->toArray();
        $this->status = DeliveryNoteStatus::Validated;
        $this->number = $number;
        $this->issueDate = self::day($issueDate);
        $this->updatedAt = $now;
        $this->events[] = new DeliveryNoteValidated(
            $this->id,
            $this->company->getId(),
            $this->establishment->getId(),
            $number,
            $this->issueDate,
            array_map(static fn (DeliveryNoteLine $line): DeliveredQuantity => new DeliveredQuantity($line->getProduct()?->getId(), $line->getQuantity(), $line->getUnit()->getId()), $this->getLines()),
        );
    }

    /**
     * A validated note's goods reached the customer on a day from its issue day to the company's today, which becomes
     * its delivery date.
     *
     * @throws DeliveryNoteTransitionRefused
     * @throws InvalidDeliveryNote
     */
    public function deliver(\DateTimeImmutable $deliveredOn, \DateTimeImmutable $today, \DateTimeImmutable $now): void
    {
        if (DeliveryNoteStatus::Validated !== $this->status) {
            throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is %s: only a validated note is delivered.', $this->reference(), $this->status->value));
        }
        $issued = $this->issueDate ?? throw new \LogicException('A validated delivery note has an issue day.');
        $day = self::day($deliveredOn);
        if ($day < $issued) {
            throw new InvalidDeliveryNote('deliveredOn', \sprintf('Goods are delivered on or after the issue day, %s.', $issued->format('Y-m-d')));
        }
        if ($day > self::day($today)) {
            throw new InvalidDeliveryNote('deliveredOn', 'A delivery is confirmed once it happened, today at the latest.');
        }

        $this->deliveryDate = $day;
        $this->status = DeliveryNoteStatus::Delivered;
        $this->updatedAt = $now;
    }

    /**
     * A draft or a validated note that will not be delivered. A validated note keeps its number, which is never given
     * again, and records `delivery_note.cancelled`.
     *
     * @throws DeliveryNoteTransitionRefused
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        $was = $this->status;
        if (DeliveryNoteStatus::Draft !== $was && DeliveryNoteStatus::Validated !== $was) {
            throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is %s: only a draft or a validated note is cancelled.', $this->reference(), $was->value));
        }

        $this->status = DeliveryNoteStatus::Cancelled;
        $this->updatedAt = $now;
        if (DeliveryNoteStatus::Validated === $was) {
            $this->events[] = new DeliveryNoteCancelled($this->id, $this->company->getId(), $this->establishment->getId(), $this->number ?? throw new \LogicException('A validated delivery note has a number.'));
        }
    }

    /** @return list<DomainEvent> what happened since the last call, each once */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /** What its customer was called the day the note was validated; null while it is a draft or was cancelled as one. */
    public function getCustomerSnapshot(): ?CustomerSnapshot
    {
        return null === $this->customerSnapshot ? null : CustomerSnapshot::fromArray($this->customerSnapshot);
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

    private function assertDraft(string $what): void
    {
        if (DeliveryNoteStatus::Draft !== $this->status) {
            throw new DeliveryNoteNotDraft(\sprintf('The delivery note %s is %s: only a draft %s.', $this->reference(), $this->status->value, $what));
        }
    }

    private function reference(): string
    {
        return $this->number ?? $this->id->toRfc4122();
    }

    private static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
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
