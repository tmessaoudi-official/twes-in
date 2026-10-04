<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\InstrumentDetails;
use App\Module\Invoices\Domain\InstrumentKind;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Module\Invoices\Domain\PaymentInstrument;
use App\Module\Invoices\Domain\PaymentInstrumentRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cheques and traites received against an issued invoice (docs/SPEC.md § 7, 2026-09-21 18:40). An instrument is not
 * money: receiving one leaves the invoice due, and only cashing it records the payment, on the company's day. Together
 * the open instruments never promise more than is still due. Each step holds the invoice's row, so a cashing and a
 * payment recorded at once never both fit what was due, and each is audited on the invoice.
 */
final readonly class ManageInstruments
{
    public const string RECEIVED = 'instrument.received';
    public const string DEPOSITED = 'instrument.deposited';
    public const string CASHED = 'instrument.cashed';
    public const string UNPAID = 'instrument.unpaid';
    public const string DELETED = 'instrument.deleted';

    public function __construct(
        private InvoiceRepository $invoices,
        private PaymentInstrumentRepository $instruments,
        private ManagePayments $payments,
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
            // What the instruments already held or deposited promise is spoken for: the rest is what another can cover.
            $free = Decimal::of($figures->amountDue)->sub(Decimal::of($this->instruments->openAmount($company->getId(), $invoiceId)));
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
     * @throws InvalidInvoice           when the invoice no longer owes the instrument's amount
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
            $payment = $this->payments->record($company, $invoiceId, new PaymentDetails($today, $instrument->getAmount(), $method, $instrument->getNumber()), $actorUserId);
            $instrument->cash($payment, $today, $now);
            $this->instruments->save($instrument);
            $this->trail($company, $invoice, self::CASHED, $instrument, $actorUserId);

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

    /**
     * A held instrument entered by mistake, taken out of the portfolio; one that was deposited or settled stays as the
     * record of what happened.
     *
     * @throws InvoiceNotFound
     * @throws InstrumentNotFound
     * @throws InvoiceTransitionRefused unless the instrument is still held
     */
    public function delete(Company $company, Uuid $invoiceId, Uuid $instrumentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $invoiceId, $instrumentId, $actorUserId): void {
            [$invoice, $instrument] = $this->locked($company, $invoiceId, $instrumentId);
            if (!$instrument->isErasable()) {
                throw new InvoiceTransitionRefused(\sprintf('The %s is %s: only one still held is taken out of the portfolio.', $instrument->getKind()->value, $instrument->getStatus()->value));
            }
            $this->instruments->remove($instrument);
            $this->trail($company, $invoice, self::DELETED, $instrument, $actorUserId);
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

    private function trail(Company $company, Invoice $invoice, string $action, PaymentInstrument $instrument, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), $action, $actorUserId, [
            'instrumentId' => $instrument->getId()->toRfc4122(),
            'kind' => $instrument->getKind()->value,
            'dueOn' => $instrument->getDueOn()->format('Y-m-d'),
            'amount' => $instrument->getAmount(),
        ], $company->getId()));
    }
}
