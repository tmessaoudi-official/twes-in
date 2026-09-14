<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Files\Domain\StoredFile;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerSnapshot;
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
#[ORM\Index(name: 'idx_invoice_pdf_file', columns: ['pdf_file_id'])]
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

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $language = null;

    /** @var array<string, mixed>|null CustomerSnapshot::toArray(), written by issuing */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $customerSnapshot = null;

    #[ORM\Column(name: 'footer_snapshot', type: Types::TEXT, nullable: true)]
    private ?string $footer = null;

    /** @var array<string, mixed>|null {keys: list<string>, latePenaltyText: string|null}, written by issuing */
    #[ORM\Column(name: 'mentions_snapshot', type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $mentions = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $issuedBy = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $subtotalNet = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $documentDiscount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $totalNet = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $taxBreakdown = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $totalTax = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $fixedTaxes = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $totalGross = null;

    /** @var list<array<string, mixed>>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $withholdings = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $withholdingAmount = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, options: ['default' => '0'])]
    private string $amountPaid = '0.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, options: ['default' => '0'])]
    private string $amountCredited = '0.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $amountDue = null;

    /** The PDF as the document was issued; null until it is stored. */
    #[ORM\ManyToOne(targetEntity: StoredFile::class)]
    #[ORM\JoinColumn(name: 'pdf_file_id', nullable: true)]
    private ?StoredFile $pdfFile = null;

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
     * Numbers a draft with lines and fixes what it says (docs/SPEC.md § 7, 2026-09-14): its taxes take their rates of the
     * issue day, then the figures those give are written once and answered from then on; what the customer was called,
     * the language, the mentions, the footer and the due day (the issue day plus the terms) are kept as they stand.
     * Records `invoice.issued`.
     *
     * @param \Closure(self): InvoiceFigures $figures what the document comes to, asked once its taxes are the issue day's
     *
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function issue(InvoiceIssue $issue, \Closure $figures, \DateTimeImmutable $now): void
    {
        $this->assertDraft('is issued');
        if ($this->lines->isEmpty()) {
            throw new InvalidInvoice('lines', 'An invoice is issued with at least one line.');
        }

        foreach ($this->getLines() as $line) {
            $line->retakeTaxes();
        }
        foreach ($this->getDocumentTaxes() as $tax) {
            $tax->retake();
        }
        $fixed = $figures($this);
        $lines = $this->getLines();
        if (\count($fixed->lines) !== \count($lines)) {
            throw new \LogicException(\sprintf('Figures for %d lines were given to an invoice of %d.', \count($fixed->lines), \count($lines)));
        }
        foreach ($lines as $index => $line) {
            $line->fix($fixed->lines[$index]);
        }
        $this->subtotalNet = $fixed->subtotalNet;
        $this->documentDiscount = $fixed->documentDiscount;
        $this->totalNet = $fixed->totalNet;
        $this->taxBreakdown = $fixed->taxes;
        $this->totalTax = $fixed->totalTax;
        $this->fixedTaxes = $fixed->fixedTaxes;
        $this->totalGross = $fixed->total;
        $this->withholdings = $fixed->withholdings;
        $this->withholdingAmount = $fixed->withholdingAmount;
        $this->amountDue = $fixed->amountDue;

        $this->customerSnapshot = CustomerSnapshot::of($this->customer)->toArray();
        $this->status = InvoiceStatus::Issued;
        $this->number = $issue->number;
        $this->issueDate = self::day($issue->issueDate);
        $this->paymentTermsDays = $issue->paymentTermsDays;
        $this->dueDate = $this->issueDate->modify(\sprintf('+%d days', $issue->paymentTermsDays));
        $this->language = $issue->language;
        $this->mentions = ['keys' => $issue->mentionKeys, 'latePenaltyText' => $issue->latePenaltyText];
        $this->footer = $issue->footer;
        $this->issuedAt = $now;
        $this->issuedBy = $issue->issuedBy;
        $this->updatedAt = $now;
        $this->events[] = new InvoiceIssued($this->id, $this->company->getId(), $this->establishment->getId(), $this->documentType, $issue->number, $this->issueDate, []);
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

    /** Keeps the PDF a numbered document was issued with; a document keeps one, and never replaces it. */
    public function attachPdf(StoredFile $file): void
    {
        if (null === $this->number) {
            throw new \LogicException('Only a numbered invoice keeps the PDF it was issued with.');
        }
        if (null !== $this->pdfFile) {
            throw new \LogicException(\sprintf('The invoice %s already keeps the PDF it was issued with.', $this->number));
        }
        if (!$file->getCompany()->getId()->equals($this->company->getId())) {
            throw new \LogicException('An invoice keeps a file of its own company.');
        }
        $this->pdfFile = $file;
    }

    public function getPdfFile(): ?StoredFile
    {
        return $this->pdfFile;
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

    /** What issuing wrote, as the columns hold it; null while the document is a draft or was cancelled as one. */
    public function getIssuedFigures(): ?InvoiceFigures
    {
        if (null === $this->subtotalNet || null === $this->documentDiscount || null === $this->totalNet || null === $this->totalTax
            || null === $this->totalGross || null === $this->withholdingAmount || null === $this->amountDue) {
            return null;
        }
        $lines = [];
        foreach ($this->getLines() as $line) {
            $lines[] = $line->getFixedFigures() ?? throw new \LogicException(\sprintf('A line of the issued invoice %s has no figures.', $this->reference()));
        }

        return new InvoiceFigures(
            $this->subtotalNet,
            $this->documentDiscount,
            $this->totalNet,
            self::rated($this->taxBreakdown ?? []),
            $this->totalTax,
            array_map(static fn (array $charge): array => ['code' => self::text($charge, 'code'), 'amount' => self::text($charge, 'amount')], $this->fixedTaxes ?? []),
            $this->totalGross,
            self::rated($this->withholdings ?? []),
            $this->withholdingAmount,
            $this->amountDue,
            $lines,
            $this->amountPaid,
            $this->amountCredited,
        );
    }

    /** What its customer was called the day it was issued; null while it is a draft or was cancelled as one. */
    public function getCustomerSnapshot(): ?CustomerSnapshot
    {
        return null === $this->customerSnapshot ? null : CustomerSnapshot::fromArray($this->customerSnapshot);
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    /** The language it prints in, fixed at issue; null on a draft. */
    public function getLanguage(): ?string
    {
        return $this->language;
    }

    /** @return list<string> the translation keys of the mentions it prints, fixed at issue */
    public function getMentionKeys(): array
    {
        $keys = $this->mentions['keys'] ?? [];

        return \is_array($keys) ? array_values(array_filter($keys, is_string(...))) : [];
    }

    /** The company's late penalty text as it read at issue; null when it had none or on a draft. */
    public function getLatePenaltyText(): ?string
    {
        $text = $this->mentions['latePenaltyText'] ?? null;

        return \is_string($text) ? $text : null;
    }

    /** The company's invoice footer as it read at issue; null when it had none or on a draft. */
    public function getFooter(): ?string
    {
        return $this->footer;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getIssuedBy(): ?Uuid
    {
        return $this->issuedBy;
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

    /**
     * @param list<array<string, mixed>> $stored
     *
     * @return list<array{code: string, rate: string, base: string, amount: string}>
     */
    private static function rated(array $stored): array
    {
        return array_map(static fn (array $each): array => ['code' => self::text($each, 'code'), 'rate' => self::text($each, 'rate'), 'base' => self::text($each, 'base'), 'amount' => self::text($each, 'amount')], $stored);
    }

    /** @param array<string, mixed> $stored */
    private static function text(array $stored, string $key): string
    {
        $value = $stored[$key] ?? null;

        return \is_string($value) ? $value : throw new \LogicException(\sprintf('A stored figure has no %s.', $key));
    }

    private static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
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
