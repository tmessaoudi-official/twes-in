<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Module\Quotes\Domain\QuoteRepository;
use App\Module\Quotes\Domain\QuoteTransitionRefused;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A quote past its draft: sent (numbered from its own series in the transaction that stores it, nothing being mailed
 * yet, so it is a mark the company makes), accepted or refused on the customer's word, cancelled while a draft, and
 * an accepted one invoiced wholly into a new draft invoice (docs/SPEC.md § 7, 2026-10-07 10:21). Every move is audited.
 */
final readonly class QuoteWorkflow
{
    public const string DOCUMENT_TYPE = 'quote';
    public const string SENT = 'quote.sent';
    public const string ACCEPTED = 'quote.accepted';
    public const string REFUSED = 'quote.refused';
    public const string CANCELLED = 'quote.cancelled';
    public const string INVOICED = 'quote.invoiced';
    public const string DEPOSIT_DRAFTED = 'quote.deposit_drafted';
    private const string PERCENTAGE = '/^(0|[1-9][0-9]?)(\.[0-9]{1,3})?$/';
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/';

    public function __construct(
        private QuoteRepository $quotes,
        private AllocateNumber $numbers,
        private Transactions $transactions,
        private QuoteTotals $totals,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private ReadSetting $settings,
        private QuoteInvoices $invoices,
    ) {
    }

    /**
     * @throws QuoteNotFound
     * @throws QuoteNotDraft
     * @throws InvalidQuote
     * @throws NoNumberingSeries when the quote's establishment numbers no quote
     * @throws InvalidNumbering  when the company's day comes before the month of the series' last number
     * @throws QuoteNumberTaken  when another establishment of the company already gave the number
     */
    public function send(Company $company, Uuid $id, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): Quote {
            // Held until this transaction ends and read as it stands, before the series: a request that read the draft
            // before another sent it is refused here, and takes no number.
            $quote = $this->locked($company, $id);
            $quote->assertDraft('is sent');
            $this->totals->checked($quote);
            $allocated = $this->numbers->allocate($company, $quote->getEstablishment(), self::DOCUMENT_TYPE);
            if ($this->quotes->numberTaken($company->getId(), $allocated->number)) {
                throw new QuoteNumberTaken(\sprintf('The number %s is already on another quote of this company: give the quote series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $quote->getEstablishment()->getCode()));
            }
            $quote->send($allocated->number, $allocated->issueDate, QuotePrinting::validityDays($this->settings, $quote), QuotePrinting::today($this->settings, $quote), $this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::SENT, ['number' => $allocated->number], $actorUserId);

            return $quote;
        });
    }

    /**
     * @param \DateTimeImmutable|null $on the day the customer agreed; left out, the company's today
     *
     * @throws QuoteNotFound
     * @throws QuoteTransitionRefused
     * @throws InvalidQuote
     */
    public function accept(Company $company, Uuid $id, ?\DateTimeImmutable $on, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $on, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $today = $this->today($company);
            $quote->accept($on ?? $today, $today, $this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::ACCEPTED, ['answeredOn' => $quote->getAnsweredOn()?->format('Y-m-d')], $actorUserId);

            return $quote;
        });
    }

    /**
     * @param \DateTimeImmutable|null $on the day the customer declined; left out, the company's today
     *
     * @throws QuoteNotFound
     * @throws QuoteTransitionRefused
     * @throws InvalidQuote
     */
    public function refuse(Company $company, Uuid $id, ?\DateTimeImmutable $on, ?string $reason, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $on, $reason, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $today = $this->today($company);
            $quote->refuse($on ?? $today, $reason, $today, $this->clock->now());
            $this->quotes->save($quote);
            // The reason is the customer's words: the audit names that one was given, never what it says.
            $this->record($company, $quote, self::REFUSED, ['answeredOn' => $quote->getAnsweredOn()?->format('Y-m-d'), 'fields' => null === $quote->getRefusalReason() ? [] : ['reason']], $actorUserId);

            return $quote;
        });
    }

    /**
     * @throws QuoteNotFound
     * @throws QuoteNotDraft
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $quote->cancel($this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::CANCELLED, [], $actorUserId);

            return $quote;
        });
    }

    /**
     * An accepted quote drafted wholly into a new invoice, once: again only when the invoice it was drafted into has
     * since been cancelled.
     *
     * @throws QuoteNotFound
     * @throws QuoteTransitionRefused
     * @throws QuoteInvoicingRefused
     */
    public function invoice(Company $company, Uuid $id, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $earlier = $quote->getInvoiceId();
            if (null !== $earlier && $this->invoices->stands($company, $earlier)) {
                throw new QuoteTransitionRefused(\sprintf('The quote %s is already on an invoice: cancel that draft first to invoice it again.', $quote->getNumber() ?? $quote->getId()->toRfc4122()));
            }
            // Checked before the draft is made, so a quote that is not accepted makes no invoice.
            $quote->assertInvoiceable();
            $invoiceId = $this->invoices->draftFrom($quote, $actorUserId);
            $quote->markInvoiced($invoiceId, $this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::INVOICED, ['invoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $quote;
        });
    }

    /**
     * « Facture d'acompte »: a draft deposit invoice for a share of an accepted quote not yet invoiced, a percentage
     * above 0 and below 100, or an amount tax included; one of them, never both (docs/fiscal FR.md and TN.md § 2b).
     *
     * @throws QuoteNotFound
     * @throws QuoteTransitionRefused when the quote is not accepted, or already on an invoice
     * @throws InvalidQuote           on `depositPercentage` or `depositAmount`
     * @throws QuoteInvoicingRefused  when the share leaves the quote short, or the invoice refuses it
     */
    public function deposit(Company $company, Uuid $id, ?string $percentage, ?string $amount, ?Uuid $actorUserId): Quote
    {
        if ((null === $percentage) === (null === $amount)) {
            throw new InvalidQuote(null === $percentage ? 'depositPercentage' : 'depositAmount', 'A deposit is a percentage of the quote or an amount, one of them.');
        }
        if (null !== $percentage && (1 !== preg_match(self::PERCENTAGE, $percentage) || self::zero($percentage))) {
            throw new InvalidQuote('depositPercentage', 'A deposit is a percentage above 0 and below 100, with at most three decimals.');
        }
        if (null !== $amount && (1 !== preg_match(self::AMOUNT, $amount) || self::zero($amount))) {
            throw new InvalidQuote('depositAmount', 'A deposit is a positive amount with at most three decimals.');
        }

        return $this->transactions->run(function () use ($company, $id, $percentage, $amount, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $earlier = $quote->getInvoiceId();
            if (null !== $earlier && $this->invoices->stands($company, $earlier)) {
                throw new QuoteTransitionRefused(\sprintf('The quote %s is already on an invoice: a deposit comes before it.', $quote->getNumber() ?? $quote->getId()->toRfc4122()));
            }
            $quote->assertInvoiceable();
            $invoiceId = $this->invoices->depositFrom($quote, $percentage, $amount, $actorUserId);
            $this->record($company, $quote, self::DEPOSIT_DRAFTED, ['invoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $quote;
        });
    }

    private static function zero(string $decimal): bool
    {
        return '' === trim(str_replace('.', '', $decimal), '0');
    }

    /** Held until the transaction ends and read as it stands: a status check on it cannot be overtaken by another request. */
    private function locked(Company $company, Uuid $id): Quote
    {
        return $this->quotes->lockedOfIdInCompany($id, $company->getId()) ?? throw new QuoteNotFound();
    }

    /** The company's today, as a UTC midnight, the way a quote's days are kept. */
    private function today(Company $company): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Quote $quote, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageQuotes::ENTITY_TYPE, $quote->getId(), $action, $actorUserId, $changes, $company->getId()));
    }
}
