<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Module\Customers\Domain\Customer;
use App\Shared\Domain\DomainEvent;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What an establishment of a company bills a customer (docs/SPEC.md § 4 invoice), or a credit note correcting such an
 * invoice. A draft names its establishment, its customer, its lines and its document taxes, every one of them its own
 * company's, and changes freely; issuing numbers it and fixes what it says.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice')]
#[ORM\Index(name: 'idx_invoice_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_invoice_establishment', columns: ['establishment_id'])]
#[ORM\Index(name: 'idx_invoice_customer', columns: ['customer_id'])]
#[ORM\Index(name: 'idx_invoice_corrects', columns: ['corrects_invoice_id'])]
#[ORM\UniqueConstraint(name: 'uniq_invoice_company_type_number', columns: ['company_id', 'document_type', 'number'])]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 16, enumType: InvoiceType::class)]
    private InvoiceType $documentType = InvoiceType::Invoice;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'corrects_invoice_id', nullable: true)]
    private ?Invoice $correctsInvoice = null;

    #[ORM\ManyToOne(targetEntity: Establishment::class)]
    #[ORM\JoinColumn(name: 'establishment_id', nullable: false)]
    private Establishment $establishment;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', nullable: false)]
    private Customer $customer;

    #[ORM\Column(length: 16, enumType: InvoiceStatus::class)]
    private InvoiceStatus $status = InvoiceStatus::Draft;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $supplyDate = null;

    #[ORM\Column(nullable: true)]
    private ?int $paymentTermsDays = null;

    #[ORM\Column(length: InvoiceHeader::REFERENCE_MAX, nullable: true)]
    private ?string $customerReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesPrinted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesInternal = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $discountAmount = null;

    /** @var list<DomainEvent> recorded since they were last released; never stored */
    private array $events = [];

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, InvoiceTax> */
    #[ORM\OneToMany(targetEntity: InvoiceTax::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $documentTaxes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->lines = new ArrayCollection();
        $this->documentTaxes = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param list<InvoiceLineDetails> $lines
     * @param list<TaxComponent>       $documentTaxes
     *
     * @throws InvalidInvoice
     */
    public static function create(Company $company, Establishment $establishment, Customer $customer, InvoiceHeader $header, array $lines, array $documentTaxes, \DateTimeImmutable $now): self
    {
        $invoice = new self($company, $now);
        $invoice->establishment = $invoice->establishmentOfThisCompany($establishment);
        $invoice->customer = $invoice->customerOfThisCompany($customer);
        $invoice->apply($header);
        $invoice->writeLines($invoice->linesOfThisCompany($lines));
        $invoice->writeDocumentTaxes($invoice->documentTaxesOfThisCompany($documentTaxes));

        return $invoice;
    }

    /**
     * @param list<InvoiceLineDetails> $lines
     * @param list<TaxComponent>       $documentTaxes
     *
     * @return list<string> the fields that changed, none when the revision says what the invoice already says
     *
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function revise(Establishment $establishment, Customer $customer, InvoiceHeader $header, array $lines, array $documentTaxes, \DateTimeImmutable $now): array
    {
        $this->assertDraft('changes');
        $establishment = $this->establishmentOfThisCompany($establishment);
        $customer = $this->customerOfThisCompany($customer);
        $lines = $this->linesOfThisCompany($lines);
        $documentTaxes = $this->documentTaxesOfThisCompany($documentTaxes);

        $changed = [];
        if (!$establishment->getId()->equals($this->establishment->getId())) {
            $changed[] = 'establishmentId';
        }
        if (!$customer->getId()->equals($this->customer->getId())) {
            $changed[] = 'customerId';
        }
        $changed = [...$changed, ...$header->differencesFrom($this->getHeader())];
        $taxesChanged = self::taxIds($documentTaxes) !== self::taxIds(array_map(static fn (InvoiceTax $tax): TaxComponent => $tax->getTaxComponent(), $this->getDocumentTaxes()));
        if ($taxesChanged) {
            $changed[] = 'documentTaxComponentIds';
        }
        $linesChanged = array_map(static fn (InvoiceLineDetails $line): array => $line->values(), $lines) !== array_map(static fn (InvoiceLine $line): array => $line->values(), $this->getLines());
        if ($linesChanged) {
            $changed[] = 'lines';
        }
        if ([] === $changed) {
            return [];
        }

        $this->establishment = $establishment;
        $this->customer = $customer;
        $this->apply($header);
        if ($taxesChanged) {
            $this->writeDocumentTaxes($documentTaxes);
        }
        if ($linesChanged) {
            $this->writeLines($lines);
        }
        $this->updatedAt = $now;

        return $changed;
    }

    /**
     * A draft that will not be issued. An issued document is never cancelled: a credit note corrects it.
     *
     * @throws InvoiceTransitionRefused
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        if (InvoiceStatus::Draft !== $this->status) {
            throw new InvoiceTransitionRefused(\sprintf('The invoice %s is %s: only a draft is cancelled, and an issued invoice is corrected by a credit note.', $this->reference(), $this->status->value));
        }
        $this->status = InvoiceStatus::Cancelled;
        $this->updatedAt = $now;
    }

    /** @return list<DomainEvent> what happened since the last call, each once */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    public function getHeader(): InvoiceHeader
    {
        return new InvoiceHeader($this->supplyDate, $this->paymentTermsDays, $this->customerReference, $this->notesPrinted, $this->notesInternal, $this->discountAmount);
    }

    /** @return list<InvoiceLine> in order */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<InvoiceTax> in order */
    public function getDocumentTaxes(): array
    {
        return array_values($this->documentTaxes->toArray());
    }

    private function apply(InvoiceHeader $header): void
    {
        $this->supplyDate = $header->supplyDate;
        $this->paymentTermsDays = $header->paymentTermsDays;
        $this->customerReference = $header->customerReference;
        $this->notesPrinted = $header->notesPrinted;
        $this->notesInternal = $header->notesInternal;
        $this->discountAmount = $header->discountAmount;
    }

    /** @param list<InvoiceLineDetails> $lines */
    private function writeLines(array $lines): void
    {
        $this->lines->clear();
        foreach ($lines as $index => $details) {
            $this->lines->add(new InvoiceLine($this, $index + 1, $details));
        }
    }

    /** @param list<TaxComponent> $taxes */
    private function writeDocumentTaxes(array $taxes): void
    {
        $this->documentTaxes->clear();
        foreach ($taxes as $index => $tax) {
            $this->documentTaxes->add(new InvoiceTax($this, $index + 1, $tax));
        }
    }

    /**
     * @param list<TaxComponent> $taxes
     *
     * @return list<string>
     */
    private static function taxIds(array $taxes): array
    {
        return array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(), $taxes);
    }

    private function assertDraft(string $what): void
    {
        if (InvoiceStatus::Draft !== $this->status) {
            throw new InvoiceNotDraft(\sprintf('The invoice %s is %s: only a draft %s.', $this->reference(), $this->status->value, $what));
        }
    }

    private function reference(): string
    {
        return $this->number ?? $this->id->toRfc4122();
    }

    private function establishmentOfThisCompany(Establishment $establishment): Establishment
    {
        if (!$establishment->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidInvoice('establishmentId', 'An invoice is issued by an establishment of its own company.');
        }

        return $establishment;
    }

    private function customerOfThisCompany(Customer $customer): Customer
    {
        if (!$customer->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidInvoice('customerId', 'An invoice goes to a customer of its own company.');
        }

        return $customer;
    }

    /**
     * @param list<InvoiceLineDetails> $lines
     *
     * @return list<InvoiceLineDetails>
     */
    private function linesOfThisCompany(array $lines): array
    {
        $companyId = $this->company->getId();
        foreach ($lines as $index => $line) {
            if (null !== $line->product && !$line->product->getCompany()->getId()->equals($companyId)) {
                throw new InvalidInvoice("lines[$index].productId", 'A line sells a product of its own company.');
            }
            if (!$line->unit->getCompany()->getId()->equals($companyId)) {
                throw new InvalidInvoice("lines[$index].unitId", 'A line counts in a unit of its own company.');
            }
            foreach ($line->taxes as $tax) {
                if (!$tax->getCompany()->getId()->equals($companyId)) {
                    throw new InvalidInvoice("lines[$index].taxComponentIds", 'A line carries taxes of its own company.');
                }
            }
        }

        return $lines;
    }

    /**
     * Fixed charges and withholdings of its own company, each once.
     *
     * @param list<TaxComponent> $taxes
     *
     * @return list<TaxComponent>
     */
    private function documentTaxesOfThisCompany(array $taxes): array
    {
        $ids = [];
        foreach ($taxes as $tax) {
            if (TaxKind::PercentageLine === $tax->getKind()) {
                throw new InvalidInvoice('documentTaxComponentIds', \sprintf('%s is charged on lines, not on a whole document.', $tax->getCode()));
            }
            if (!$tax->getCompany()->getId()->equals($this->company->getId())) {
                throw new InvalidInvoice('documentTaxComponentIds', 'A document carries taxes of its own company.');
            }
            $id = $tax->getId()->toRfc4122();
            if (isset($ids[$id])) {
                throw new InvalidInvoice('documentTaxComponentIds', \sprintf('A document carries %s once.', $tax->getCode()));
            }
            $ids[$id] = true;
        }

        return $taxes;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getType(): InvoiceType
    {
        return $this->documentType;
    }

    /** The invoice a credit note corrects; null for an invoice. */
    public function getCorrectedInvoice(): ?self
    {
        return $this->correctsInvoice;
    }

    public function getEstablishment(): Establishment
    {
        return $this->establishment;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getStatus(): InvoiceStatus
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
