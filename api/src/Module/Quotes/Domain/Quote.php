<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Domain;

use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\SellerSnapshot;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A price an establishment of a company offers a customer, binding until its validity date: a devis, its own record
 * with its own numbering, never the order (docs/SPEC.md § 7, 2026-09-21 14:55). A draft names its establishment, its
 * customer and its lines, every one of them its own company's, and changes freely; sending it gives it its number, its
 * issue and validity days, and what the customer and the seller were called that day. The customer then accepts or
 * refuses it, and an accepted quote is invoiced.
 */
#[ORM\Entity]
#[ORM\Table(name: 'quote')]
#[ORM\Index(name: 'idx_quote_company', columns: ['company_id'])]
#[ORM\Index(name: 'idx_quote_establishment', columns: ['establishment_id'])]
#[ORM\Index(name: 'idx_quote_customer', columns: ['customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_quote_company_number', columns: ['company_id', 'number'])]
class Quote implements CompanyOwned
{
    public const int REASON_MAX = 500;

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

    #[ORM\Column(length: 16, enumType: QuoteStatus::class)]
    private QuoteStatus $status = QuoteStatus::Draft;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $number = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $issueDate = null;

    /** The last day its price binds; null while it is a draft. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(length: QuoteHeader::REFERENCE_MAX, nullable: true)]
    private ?string $customerReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesPrinted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notesInternal = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $discountAmount = null;

    /** @var array<string, mixed>|null CustomerSnapshot::toArray(), written by sending */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $customerSnapshot = null;

    /** @var array<string, mixed>|null SellerSnapshot::toArray(), written by sending */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $sellerSnapshot = null;

    /** @var array<string, mixed>|null QuotePrint::toArray(), written by sending */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $printSettings = null;

    /** The day the customer agreed or declined; null before. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $answeredOn = null;

    /** Why the customer declined, when the company was told. */
    #[ORM\Column(length: self::REASON_MAX, nullable: true)]
    private ?string $refusalReason = null;

    /** The invoice the accepted quote was drafted into. An id rather than an association: invoices are their own module. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $invoiceId = null;

    /** @var Collection<int, QuoteLine> */
    #[ORM\OneToMany(targetEntity: QuoteLine::class, mappedBy: 'quote', cascade: ['persist'], orphanRemoval: true)]
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
     * @param list<QuoteLineDetails> $lines
     *
     * @throws InvalidQuote
     */
    public static function create(Company $company, Establishment $establishment, Customer $customer, QuoteHeader $header, array $lines, \DateTimeImmutable $now): self
    {
        $quote = new self($company, $now);
        $quote->establishment = $quote->establishmentOfThisCompany($establishment);
        $quote->customer = $quote->customerOfThisCompany($customer);
        $quote->apply($header);
        $quote->writeLines($quote->linesOfThisCompany($lines));

        return $quote;
    }

    /**
     * @param list<QuoteLineDetails> $lines
     *
     * @return list<string> the fields that changed, none when the revision says what the quote already says
     *
     * @throws QuoteNotDraft
     * @throws InvalidQuote
     */
    public function revise(Establishment $establishment, Customer $customer, QuoteHeader $header, array $lines, \DateTimeImmutable $now): array
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
        $linesChanged = array_map(static fn (QuoteLineDetails $line): array => $line->values(), $lines) !== array_map(static fn (QuoteLine $line): array => $line->values(), $this->getLines());
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
     * Hands a draft with lines to its customer: its number, its issue day and the last day its price binds. From then
     * on it prints what its customer and its seller were called that day, the rates its taxes had that day, and as its
     * settings said that day (`$print`).
     *
     * @throws QuoteNotDraft
     * @throws InvalidQuote
     */
    public function send(string $number, \DateTimeImmutable $issueDate, int $validityDays, QuotePrint $print, \DateTimeImmutable $now): void
    {
        $this->assertDraft('is sent');
        if ($this->lines->isEmpty()) {
            throw new InvalidQuote('lines', 'A quote is sent with at least one line.');
        }
        if ($validityDays < 1) {
            throw new InvalidQuote('validityDays', 'A quote binds its price for one day at least.');
        }
        foreach ($this->getLines() as $line) {
            $line->assertCountedByItsUnit();
        }
        foreach ($this->getLines() as $line) {
            $line->retakeTaxes();
        }

        $this->customerSnapshot = CustomerSnapshot::of($this->customer)->toArray();
        $this->sellerSnapshot = SellerSnapshot::of($this->company, $this->establishment)->toArray();
        $this->printSettings = $print->toArray();
        $this->status = QuoteStatus::Sent;
        $this->number = $number;
        $this->issueDate = self::day($issueDate);
        $this->validUntil = $this->issueDate->modify(\sprintf('+%d days', $validityDays));
        $this->updatedAt = $now;
    }

    /**
     * Whether a sent quote's price no longer binds: its validity date is before the company's today. Never stored, and
     * never true of a quote already answered.
     */
    public function isExpired(\DateTimeImmutable $today): bool
    {
        return QuoteStatus::Sent === $this->status && null !== $this->validUntil && $this->validUntil < self::day($today);
    }

    /**
     * The customer agreed, on a day from the issue day to the company's today. An expired quote may still be accepted:
     * the company's own gesture, once the customer agreed to it.
     *
     * @throws QuoteTransitionRefused
     * @throws InvalidQuote
     */
    public function accept(\DateTimeImmutable $acceptedOn, \DateTimeImmutable $today, \DateTimeImmutable $now): void
    {
        $this->answer(QuoteStatus::Accepted, $acceptedOn, $today, 'accepted');
        $this->updatedAt = $now;
    }

    /**
     * The customer declined, on a day from the issue day to the company's today, and why when the company was told.
     *
     * @throws QuoteTransitionRefused
     * @throws InvalidQuote
     */
    public function refuse(\DateTimeImmutable $refusedOn, ?string $reason, \DateTimeImmutable $today, \DateTimeImmutable $now): void
    {
        $reason = trim($reason ?? '');
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new InvalidQuote('reason', \sprintf('At most %d characters.', self::REASON_MAX));
        }
        $this->answer(QuoteStatus::Refused, $refusedOn, $today, 'refused');
        $this->refusalReason = '' === $reason ? null : $reason;
        $this->updatedAt = $now;
    }

    /**
     * A draft that will not be sent. A sent quote is answered, never cancelled: its number stays where it was given.
     *
     * @throws QuoteNotDraft
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        $this->assertDraft('is cancelled');
        $this->status = QuoteStatus::Cancelled;
        $this->updatedAt = $now;
    }

    /**
     * An accepted quote drafted into an invoice. Whether an earlier invoice of it still stands is the caller's to say,
     * since invoices are their own module.
     *
     * @throws QuoteTransitionRefused
     */
    public function markInvoiced(Uuid $invoiceId, \DateTimeImmutable $now): void
    {
        $this->assertInvoiceable();
        $this->invoiceId = $invoiceId;
        $this->updatedAt = $now;
    }

    /** @throws QuoteTransitionRefused unless the quote is accepted */
    public function assertInvoiceable(): void
    {
        if (QuoteStatus::Accepted !== $this->status) {
            throw new QuoteTransitionRefused(\sprintf('The quote %s is %s: only an accepted quote is invoiced.', $this->reference(), $this->status->value));
        }
    }

    /** What its customer was called the day the quote was sent; null while it is a draft or was cancelled as one. */
    public function getCustomerSnapshot(): ?CustomerSnapshot
    {
        return null === $this->customerSnapshot ? null : CustomerSnapshot::fromArray($this->customerSnapshot);
    }

    /** The seller as the quote printed it the day it was sent; null while it is a draft. */
    public function getSellerSnapshot(): ?SellerSnapshot
    {
        return null === $this->sellerSnapshot ? null : SellerSnapshot::fromArray($this->sellerSnapshot);
    }

    /** How the quote printed the day it was sent; null while it is a draft. */
    public function getPrintSettings(): ?QuotePrint
    {
        return null === $this->printSettings ? null : QuotePrint::fromArray($this->printSettings);
    }

    public function getHeader(): QuoteHeader
    {
        return new QuoteHeader($this->customerReference, $this->notesPrinted, $this->notesInternal, $this->discountAmount);
    }

    /** @return list<QuoteLine> in order */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function assertDraft(string $what): void
    {
        if (QuoteStatus::Draft !== $this->status) {
            throw new QuoteNotDraft(\sprintf('The quote %s is %s: only a draft %s.', $this->reference(), $this->status->value, $what));
        }
    }

    /**
     * @throws QuoteTransitionRefused
     * @throws InvalidQuote
     */
    private function answer(QuoteStatus $answer, \DateTimeImmutable $on, \DateTimeImmutable $today, string $what): void
    {
        if (QuoteStatus::Sent !== $this->status) {
            throw new QuoteTransitionRefused(\sprintf('The quote %s is %s: only a sent quote is %s.', $this->reference(), $this->status->value, $what));
        }
        $issued = $this->issueDate ?? throw new \LogicException('A sent quote has an issue day.');
        $day = self::day($on);
        if ($day < $issued) {
            throw new InvalidQuote('answeredOn', \sprintf('A quote is answered on or after its issue day, %s.', $issued->format('Y-m-d')));
        }
        if ($day > self::day($today)) {
            throw new InvalidQuote('answeredOn', 'A quote is answered once the customer did, today at the latest.');
        }

        $this->status = $answer;
        $this->answeredOn = $day;
    }

    private function apply(QuoteHeader $header): void
    {
        $this->customerReference = $header->customerReference;
        $this->notesPrinted = $header->notesPrinted;
        $this->notesInternal = $header->notesInternal;
        $this->discountAmount = $header->discountAmount;
    }

    /** @param list<QuoteLineDetails> $lines */
    private function writeLines(array $lines): void
    {
        $this->lines->clear();
        foreach ($lines as $index => $details) {
            $this->lines->add(new QuoteLine($this, $index + 1, $details));
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
            throw new InvalidQuote('establishmentId', 'A quote is made by an establishment of its own company.');
        }

        return $establishment;
    }

    private function customerOfThisCompany(Customer $customer): Customer
    {
        if (!$customer->getCompany()->getId()->equals($this->company->getId())) {
            throw new InvalidQuote('customerId', 'A quote goes to a customer of its own company.');
        }

        return $customer;
    }

    /**
     * @param list<QuoteLineDetails> $lines
     *
     * @return list<QuoteLineDetails>
     */
    private function linesOfThisCompany(array $lines): array
    {
        $companyId = $this->company->getId();
        foreach ($lines as $index => $line) {
            if (null !== $line->product && !$line->product->getCompany()->getId()->equals($companyId)) {
                throw new InvalidQuote("lines[$index].productId", 'A line offers a product of its own company.');
            }
            if (!$line->unit->getCompany()->getId()->equals($companyId)) {
                throw new InvalidQuote("lines[$index].unitId", 'A line counts in a unit of its own company.');
            }
            foreach ($line->taxes as $tax) {
                if (!$tax->getCompany()->getId()->equals($companyId)) {
                    throw new InvalidQuote("lines[$index].taxComponentIds", 'A line carries taxes of its own company.');
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

    public function getStatus(): QuoteStatus
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

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getAnsweredOn(): ?\DateTimeImmutable
    {
        return $this->answeredOn;
    }

    public function getRefusalReason(): ?string
    {
        return $this->refusalReason;
    }

    /** The invoice the accepted quote was drafted into; null until it is. */
    public function getInvoiceId(): ?Uuid
    {
        return $this->invoiceId;
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
