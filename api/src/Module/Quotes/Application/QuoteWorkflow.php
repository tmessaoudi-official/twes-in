<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Quotes\Application\Delivery\QuoteDeliveryNotes;
use App\Module\Quotes\Domain\Exception\InvalidQuote;
use App\Module\Quotes\Domain\Exception\QuoteNotDraft;
use App\Module\Quotes\Domain\Exception\QuoteTransitionRefused;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\Model\QuoteLine;
use App\Module\Quotes\Domain\Repository\QuoteRepository;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A quote past its draft: sent (numbered from its own series in the transaction that stores it, nothing being mailed
 * yet, so it is a mark the company makes), accepted or refused on the customer's word, cancelled while a draft, and
 * an accepted one invoiced wholly into a new draft invoice or added to an open one.
 * Every move is audited.
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
        private DocumentNames $names,
        private ExcludedTaxFamilies $excluded,
        private QuoteDeliveryNotes $deliveries,
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
            // A draft put back from « Récemment supprimés » was never moved by a merge made meanwhile.
            if ($quote->getCustomer()->isMergedAway()) {
                throw new InvalidQuote('customerId', $quote->getCustomer()->whySwitchedOff());
            }
            $this->totals->checked($quote);
            $this->chargedByItsRegimes($company, $quote);
            $allocated = $this->numbers->allocate($company, $quote->getEstablishment(), self::DOCUMENT_TYPE);
            if ($this->quotes->numberTaken($company->getId(), $allocated->number)) {
                throw new QuoteNumberTaken(\sprintf('The number %s is already on another quote of this company: give the quote series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $quote->getEstablishment()->getCode()));
            }
            $printing = QuotePrinting::today($this->settings, $quote);
            $units = array_map(static fn (QuoteLine $line): Unit => $line->getUnit(), $quote->getLines());
            $quote->send($allocated->number, $allocated->issueDate, QuotePrinting::validityDays($this->settings, $quote), $printing, $this->names->now($company, $printing->language, $units), $this->clock->now());
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
            $this->assertNotDelivered($company, $quote, 'it is invoiced through them, « Facturer les bons de livraison »');
            $invoiceId = $this->invoices->draftFrom($quote, $actorUserId);
            $quote->markInvoiced($invoiceId, $this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::INVOICED, ['invoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $quote;
        });
    }

    /**
     * An accepted quote added to an open draft invoice of its customer instead of a new one, once, as `invoice` drafts
     * one: the quote then names that draft.
     *
     * @throws QuoteNotFound
     * @throws QuoteTransitionRefused
     * @throws QuoteTargetInvoiceNotFound
     * @throws QuoteTargetInvoiceNotDraft
     * @throws QuoteInvoicingRefused
     */
    public function addToInvoice(Company $company, Uuid $id, Uuid $invoiceId, ?Uuid $actorUserId): Quote
    {
        return $this->transactions->run(function () use ($company, $id, $invoiceId, $actorUserId): Quote {
            $quote = $this->locked($company, $id);
            $earlier = $quote->getInvoiceId();
            if (null !== $earlier && $this->invoices->stands($company, $earlier)) {
                throw new QuoteTransitionRefused(\sprintf('The quote %s is already on an invoice: cancel that draft first to invoice it again.', $quote->getNumber() ?? $quote->getId()->toRfc4122()));
            }
            $quote->assertInvoiceable();
            $this->assertNotDelivered($company, $quote, 'it is invoiced through them, « Facturer les bons de livraison »');
            $this->invoices->addTo($quote, $invoiceId, $actorUserId);
            $quote->markInvoiced($invoiceId, $this->clock->now());
            $this->quotes->save($quote);
            $this->record($company, $quote, self::INVOICED, ['invoiceId' => $invoiceId->toRfc4122(), 'added' => true], $actorUserId);

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
            $this->assertNotDelivered($company, $quote, 'a deposit comes before them, and the invoice of its notes would not give it back');
            $invoiceId = $this->invoices->depositFrom($quote, $percentage, $amount, $actorUserId);
            $this->record($company, $quote, self::DEPOSIT_DRAFTED, ['invoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $quote;
        });
    }

    /**
     * A quote delivered by notes that are not cancelled is invoiced through them alone: invoiced as a whole too, its goods
     * would leave stock twice and be billed twice.
     *
     * @throws QuoteTransitionRefused
     */
    private function assertNotDelivered(Company $company, Quote $quote, string $instead): void
    {
        $key = $quote->getId()->toRfc4122();
        $lines = array_map(static fn (QuoteLine $line): Uuid => $line->getId(), $quote->getLines());
        $notes = $this->deliveries->ofQuotes($company, [$key => $lines])[$key]->notes ?? [];
        if ([] !== $notes) {
            throw new QuoteTransitionRefused(\sprintf('The quote %s is delivered by delivery notes that are not cancelled: %s.', $quote->getNumber() ?? $key, $instead));
        }
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

    /**
     * Every tax the draft carries is still one its customer's regime and its company's charge: either may have changed
     * since the draft was saved, and the send refuses rather than drop a tax, which would choose a fiscal answer for
     * the person sending.
     *
     * @throws InvalidQuote
     */
    private function chargedByItsRegimes(Company $company, Quote $quote): void
    {
        $regime = $quote->getCustomer()->getTaxRegime();
        foreach ($quote->getLines() as $index => $line) {
            foreach ($line->getTaxes() as $tax) {
                $leftOutBy = $this->excluded->regimeLeavingOut($company, $regime, $tax->getTaxComponent()->getFamily());
                if (null !== $leftOutBy) {
                    throw (new InvalidQuote('taxComponentIds', \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode())))->within("lines[$index]");
                }
            }
        }
    }
}
