<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Payment;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Application\Credit\ManageCustomerCredit;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\PaymentInstrument;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\Repository\PaymentInstrumentRepository;
use App\Module\Invoices\Domain\ValueObject\InstrumentDetails;
use App\Module\Invoices\Domain\ValueObject\InstrumentKind;
use App\Module\Invoices\Domain\ValueObject\InstrumentPortfolioSearch;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Shared\Application\Transactions;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Tenancy\Domain\Model\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cheques and traites received against an issued invoice (docs/SPEC.md § 7, 2026-09-21 18:40). An instrument is not
 * money: receiving one leaves the invoice due, and only cashing it records the payment, on the company's day. Together
 * the open instruments never promise more than is still due. Each step holds the invoice's row, so a cashing and a
 * payment recorded at once never both fit what was due, and each is audited on the invoice.
 */
final readonly class ManageInstruments implements DeclaresAuditActions
{
    public const string RECEIVED = 'instrument.received';
    public const string DEPOSITED = 'instrument.deposited';
    public const string CASHED = 'instrument.cashed';
    public const string UNPAID = 'instrument.unpaid';

    public function __construct(
        private InvoiceRepository $invoices,
        private PaymentInstrumentRepository $instruments,
        private ManagePayments $payments,
        private ManageCustomerCredit $credit,
        private Transactions $transactions,
        private CurrencyScales $scales,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<PaymentInstrument>
     *
     * @throws InvoiceNotFound
     */
    public function ofInvoice(Company $company, Uuid $invoiceId): array
    {
        $this->invoices->ofIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();

        return $this->instruments->ofInvoice($company->getId(), $invoiceId);
    }

    /**
     * One page of the company's cheques and traites, with their invoice, for the portfolio screen.
     *
     * @return Page<PaymentInstrument>
     */
    public function portfolio(Company $company, InstrumentPortfolioSearch $search, PageRequest $page): Page
    {
        return $this->instruments->portfolio($company->getId(), $search, $page);
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused when the invoice is not an issued invoice
     * @throws InvalidInvoice           on `amount` or `dueOn`
     */
    public function receive(Company $company, Uuid $invoiceId, InstrumentDetails $details, ?Uuid $actorUserId): PaymentInstrument
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $details, $actorUserId): PaymentInstrument {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            $figures = $invoice->getIssuedFigures();
            $issued = $invoice->getIssueDate();
            if (InvoiceType::Invoice !== $invoice->getType() || !\in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true) || null === $figures || null === $issued) {
                throw new InvoiceTransitionRefused(\sprintf('The %s %s is %s: only an issued invoice is paid.', $invoice->getType()->value, $invoice->getNumber() ?? $invoice->getId()->toRfc4122(), $invoice->getStatus()->value));
            }
            $scale = $this->scales->of($company->getCurrency());
            $amount = Decimal::of($details->amount);
            if (0 !== Decimal::round($amount, $scale)->compare($amount)) {
                throw new InvalidInvoice('amount', \sprintf('The currency %s has %d decimals.', $company->getCurrency(), $scale));
            }
            $free = $this->free($company, $invoice);
            if ($amount->compare($free) > 0) {
                throw new InvalidInvoice('amount', \sprintf('An instrument is at most what is still due and not already covered by another, %s.', Decimal::format($free, $scale)));
            }
            if ($details->dueOn < $issued) {
                throw new InvalidInvoice('dueOn', \sprintf('An instrument falls due from the issue day, %s.', $issued->format('Y-m-d')));
            }

            $instrument = new PaymentInstrument($invoice, $details, $actorUserId, $this->clock->now());
            $this->instruments->save($instrument);
            $this->trail($company, $invoice, self::RECEIVED, $instrument, $actorUserId);

            return $instrument;
        });
    }

    /**
     * @throws InvoiceNotFound
     * @throws InstrumentNotFound
     * @throws InvoiceTransitionRefused unless the instrument is still held
     */
    public function deposit(Company $company, Uuid $invoiceId, Uuid $instrumentId, ?Uuid $actorUserId): PaymentInstrument
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $instrumentId, $actorUserId): PaymentInstrument {
            [$invoice, $instrument] = $this->locked($company, $invoiceId, $instrumentId);
            $instrument->deposit($this->clock->now());
            $this->instruments->save($instrument);
            $this->trail($company, $invoice, self::DEPOSITED, $instrument, $actorUserId);

            return $instrument;
        });
    }

    /**
     * The instrument clears: the payment it stands for is recorded today, in the company's day, by the way it was paid.
     *
     * @throws InvoiceNotFound
     * @throws InstrumentNotFound
     * @throws InvoiceTransitionRefused unless the instrument is held or deposited
     */
    public function cash(Company $company, Uuid $invoiceId, Uuid $instrumentId, ?Uuid $actorUserId): PaymentInstrument
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $instrumentId, $actorUserId): PaymentInstrument {
            [$invoice, $instrument] = $this->locked($company, $invoiceId, $instrumentId);
            if (!$instrument->getStatus()->isOpen()) {
                throw new InvoiceTransitionRefused(\sprintf('The %s is already %s.', $instrument->getKind()->value, $instrument->getStatus()->value));
            }
            $now = $this->clock->now();
            $today = $now->setTimezone(new \DateTimeZone($company->getTimezone()));
            $method = InstrumentKind::Check === $instrument->getKind() ? PaymentMethod::Check : PaymentMethod::Other;
            // Other money may have covered the invoice since the instrument came in: it pays what is still due, and the
            // rest, which really arrived, is the customer's, on account (docs/SPEC.md § 7, 2026-10-06 22:11).
            $scale = $this->scales->of($company->getCurrency());
            $amount = Decimal::of($instrument->getAmount());
            $due = Decimal::of($invoice->getIssuedFigures()->amountDue ?? '0');
            $paid = $amount->compare($due) > 0 ? $due : $amount;
            $onAccount = $amount->sub($paid);
            $payment = $paid->compare(0) > 0
                ? $this->payments->record($company, $invoiceId, new PaymentDetails($today, Decimal::format($paid, $scale), $method, $instrument->getNumber()), $actorUserId)
                : null;
            if ($onAccount->compare(0) > 0) {
                $this->credit->overpayment($company, $invoiceId, new PaymentDetails($today, Decimal::format($onAccount, $scale), $method, $instrument->getNumber()), $actorUserId);
            }
            $instrument->cash($payment, $today, $now);
            $this->instruments->save($instrument);
            $this->trail($company, $invoice, self::CASHED, $instrument, $actorUserId, ['onAccount' => Decimal::format($onAccount, $scale)]);

            return $instrument;
        });
    }

    /**
     * The instrument came back unpaid: no payment, the invoice stays due, and the trace is kept.
     *
     * @throws InvoiceNotFound
     * @throws InstrumentNotFound
     * @throws InvoiceTransitionRefused unless the instrument is held or deposited
     */
    public function refuse(Company $company, Uuid $invoiceId, Uuid $instrumentId, ?Uuid $actorUserId): PaymentInstrument
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $instrumentId, $actorUserId): PaymentInstrument {
            [$invoice, $instrument] = $this->locked($company, $invoiceId, $instrumentId);
            $now = $this->clock->now();
            $instrument->refuse($now->setTimezone(new \DateTimeZone($company->getTimezone())), $now);
            $this->instruments->save($instrument);
            $this->trail($company, $invoice, self::UNPAID, $instrument, $actorUserId);

            return $instrument;
        });
    }

    /** @return array{Invoice, PaymentInstrument} */
    private function locked(Company $company, Uuid $invoiceId, Uuid $instrumentId): array
    {
        $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
        $instrument = $this->instruments->ofIdInCompany($instrumentId, $company->getId());
        if (null === $instrument || !$instrument->getInvoice()->getId()->equals($invoiceId)) {
            throw new InstrumentNotFound();
        }

        return [$invoice, $instrument];
    }

    /**
     * Whether the instruments put back from the bin, now standing again, still fit what their invoices owe: the bin
     * restores a row as it was, past the cap `receive` applies. Each invoice is held first, in a fixed order.
     *
     * @param list<Uuid> $instrumentIds
     */
    public function fitWhatIsDue(Company $company, array $instrumentIds): bool
    {
        $invoiceIds = [];
        foreach ($instrumentIds as $id) {
            $instrument = $this->instruments->ofIdInCompany($id, $company->getId()) ?? throw new InstrumentNotFound();
            $invoiceIds[$instrument->getInvoice()->getId()->toRfc4122()] = $instrument->getInvoice()->getId();
        }
        ksort($invoiceIds);
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) ?? throw new InvoiceNotFound();
            if ($this->free($company, $invoice)->compare(0) < 0) {
                return false;
            }
        }

        return true;
    }

    /** What the instruments already held or deposited promise is spoken for: the rest is what another can cover. */
    private function free(Company $company, Invoice $invoice): Number
    {
        $due = $invoice->getIssuedFigures()->amountDue ?? '0';

        return Decimal::of($due)->sub(Decimal::of($this->instruments->openAmount($company->getId(), $invoice->getId())));
    }

    /** @param array<string, string> $more */
    private function trail(Company $company, Invoice $invoice, string $action, PaymentInstrument $instrument, ?Uuid $actorUserId, array $more = []): void
    {
        $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), $action, $actorUserId, [
            'instrumentId' => $instrument->getId()->toRfc4122(),
            'kind' => $instrument->getKind()->value,
            'dueOn' => $instrument->getDueOn()->format('Y-m-d'),
            'amount' => $instrument->getAmount(),
        ] + $more, $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CASHED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
            self::DEPOSITED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
            self::RECEIVED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
            self::UNPAID => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
