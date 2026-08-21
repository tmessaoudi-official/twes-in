<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Document;

use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Settings\CompanySettingsRepository;
use Twes\Domain\Shared\IdGenerator;

/**
 * Creates a draft invoice: mint an id, build the aggregate, persist it, and return it **as it reads back**.
 *
 * ## The return value is re-read on purpose, and this is the subtle part
 *
 * The obvious implementation returns the aggregate it just built. That would make `POST` and a subsequent `GET`
 * disagree byte-for-byte on the same document: `quantity` is `NUMERIC(21,6)`, so a line written as `2` comes back as
 * `2.000000`. Same number, different string — and every client compares numerically, which this project's own test
 * suite got wrong once and recorded. But a create response that does not match the resource a client can then fetch
 * is a contract defect regardless of whether anyone compares by string, and the mobile client freezes the contract
 * on app-store timelines.
 *
 * So the document is read back **inside the same transaction**. Not after it: outside, the read would be a second
 * transaction that could observe a different state, and under row-level security it would need the binding to still
 * be in place. Inside, it is the same unit of work and cannot fail independently.
 *
 * A `null` from that read is a `\LogicException` and not a 404 — we just wrote it, in this transaction, under this
 * tenant. It means the write and the read disagree about which rows are visible, i.e. a tenancy or policy fault, and
 * that is ours rather than the caller's.
 *
 * ## What it does NOT do
 *
 * **It allocates no number**, because a draft has none. Numbers come from a gapless per-`(tenant, type)` counter, so
 * allocating at create would let every abandoned draft consume one permanently. {@see IssueInvoiceHandler} is where
 * that happens, and `Invoice::draft()` taking no number is what makes the timing structural rather than a convention.
 */
final readonly class CreateInvoiceHandler
{
    public function __construct(
        private InvoiceRepository $invoices,
        private IdGenerator $ids,
        private TransactionalScope $transaction,
        private CompanySettingsRepository $settings,
    ) {}

    /**
     * @throws \Twes\Domain\Money\Exception\CurrencyMismatch if a line or charge is not in the document's currency
     * @throws \InvalidArgumentException if the lines cannot be totalled, or there are more than `Invoice::MAX_LINES`
     * @throws \LogicException if the document cannot be read back after being written
     * @throws \RuntimeException if no tenant is bound
     */
    public function handle(CreateInvoice $command): PersistedInvoice
    {
        // BUILT OUTSIDE THE TRANSACTION. Every refusal the aggregate can raise here — a foreign currency, a document
        // that cannot be totalled, too many lines — is a caller error that touches no rows, so opening a transaction
        // first would mean opening and rolling back one for every invalid request. It also keeps the transaction as
        // short as the writes, which matters because `withLine()` totals the whole document on every call.
        $invoice = Invoice::draft($command->currency);

        foreach ($command->lines as $line) {
            $invoice = $invoice->withLine($line);
        }

        foreach ($command->fixedCharges as $charge) {
            $invoice = $invoice->withFixedCharge($charge);
        }

        return $this->transaction->transactional(function () use ($invoice): PersistedInvoice {
            // THE IDENTITY IS BUILT INSIDE THE TRANSACTION AND THE AGGREGATE OUTSIDE IT, and the split is not
            // arbitrary. The aggregate's refusals are caller errors that touch no rows, so building it first keeps
            // an invalid request from opening a transaction at all — that argument is unchanged. The identity now
            // carries the tenant's configured rounding point, and reading it requires an active transaction:
            // the binding row-level security compares against is transaction-local, so outside one the read is
            // issued unbound and `CompanySettingsRepository` refuses rather than answering with defaults it cannot
            // prove are this company's.
            //
            // THE TYPE IS FIXED HERE AND IS NOT THE CALLER'S. This handler creates invoices; a quote and a credit
            // note are different documents with different numbering sequences and, in the credit's case, a
            // different EN 16931 type code. A `DocumentType` parameter would make this one handler pretend to serve
            // four use cases whose rules differ.
            //
            // NOR IS THE ROUNDING POINT, and it stopped being a field on the command in the same change that added
            // this line. It decides how much tax the document declares, so it is company configuration read here
            // rather than something any caller — HTTP, CLI or Messenger — can state (see {@see CreateInvoice}).
            $identity = new DocumentIdentity(
                $this->ids->nextIdentifier(),
                DocumentType::Invoice,
                $this->settings->forCurrentTenant()->defaultVatRoundingPoint(),
            );

            $this->invoices->save($identity, $invoice);

            return $this->invoices->find($identity->id) ?? throw new \LogicException(\sprintf(
                'Document %s was saved and is not readable back in the same transaction. The write and the read '
                . 'disagree about which rows are visible, which means a row-level-security binding fault rather than '
                . 'a missing document — so this is ours and not the caller\'s.',
                $identity->id,
            ));
        });
    }
}
