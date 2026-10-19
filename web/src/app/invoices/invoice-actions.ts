// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { MatDialog } from '@angular/material/dialog';
import { Router } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import {
  MakeRecurringDialog,
  makeRecurringForm,
  recurringDraft,
  recurringFailure,
  type MakeRecurringData,
} from '../recurring/make-recurring';
import { RecurringApi, RecurringRefused } from '../recurring/recurring-api';
import { RECURRING_MODULE } from '../recurring/recurring-nav';
import type { RecordAction } from '../shared/actions/record-action';
import { Feedback } from '../shared/feedback/feedback';
import { FileSaver } from '../shared/files/save-file';
import { buildFormGroup } from '../shared/form/form-builder';
import { todayIn } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { TextsParts } from '../shared/i18n/texts-part';
import { SCHEDULE_TEXTS } from '../shared/schedule/schedule-texts';
import { versus } from '../shared/ui/versus';
import { CreditNoteDialog, type CreditNoteDialogData } from './credit-note-dialog';
import { FacturXRefusalDialog, type FacturXRefusal } from './factur-x-refusal-dialog';
import {
  creditNoteForm,
  paidInFull,
  paymentForm,
  paymentInput,
  paymentValues,
  stillOwed,
} from './invoice-forms';
import { InvoicesFacade } from './invoices-facade';
import { FACTUR_X_COUNTRIES, type FacturXFormat, type InvoiceRow } from './invoices-types';
import { OverpaymentDialog, type OverpaymentDialogData } from './overpayment-dialog';
import { overpaymentForm, overpaymentInput, overpaymentValues } from './overpayment-form';
import { PaymentDialog, type PaymentDialogData } from './payment-dialog';

/** What the person working may do to invoices, and what the company has switched on: all the guards read besides the document. */
export interface InvoiceRights {
  write: boolean;
  issue: boolean;
  pay: boolean;
  credit: boolean;
  recurring: boolean;
  /** Whether the company's preset writes Factur-X. */
  facturX: boolean;
}

/** An amount the API wrote as zero at any scale, "0" or "0.000". */
export function isZero(amount: string): boolean {
  return /^-?[0.]+$/.test(amount);
}

function isCreditNote(invoice: InvoiceRow): boolean {
  return invoice.type === 'credit_note';
}

/** Issued and not withdrawn: what a printed copy and a credit note are made of. */
export function isOpen(invoice: InvoiceRow): boolean {
  return (
    invoice.status === 'issued' || invoice.status === 'partially_paid' || invoice.status === 'paid'
  );
}

/**
 * Whether each action applies to a document, for a person: one function per action, read by the record's page, the
 * sheet over the list and the list's rows alike, so none of them offers what another hides and the API refuses.
 */
export const invoiceMay = {
  /** A credit note reverses revenue, so changing its draft takes the right to draft one, as the API asks. */
  touch: (invoice: InvoiceRow, rights: InvoiceRights) =>
    rights.write && (!isCreditNote(invoice) || rights.credit),
  issue: (invoice: InvoiceRow, rights: InvoiceRights) =>
    invoice.status === 'draft' &&
    rights.write &&
    rights.issue &&
    (!isCreditNote(invoice) || rights.credit),
  abandon: (invoice: InvoiceRow, rights: InvoiceRights) =>
    invoice.status === 'draft' && invoiceMay.touch(invoice, rights),
  pay: (invoice: InvoiceRow, rights: InvoiceRights) => stillOwed(invoice) && rights.pay,
  /** Money paid beyond an invoice is kept to the customer's credit once the invoice is paid in full, never before. */
  overpay: (invoice: InvoiceRow, rights: InvoiceRights) => paidInFull(invoice) && rights.pay,
  /**
   * A credit note takes at most what the invoice asked to be paid less its earlier credit notes, as the API counts it:
   * paid in full, goods returned are still credited, and what was paid beyond is the customer's again.
   */
  credit: (invoice: InvoiceRow, rights: InvoiceRights) =>
    !isCreditNote(invoice) &&
    isOpen(invoice) &&
    rights.credit &&
    versus(invoice.netToPay, invoice.amountCredited).kind === 'up',
  /** An invoice of the company is made recurring, never a credit note nor a deposit, while the module is on. */
  makeRecurring: (invoice: InvoiceRow, rights: InvoiceRights) =>
    rights.write && rights.recurring && invoice.type === 'invoice' && !invoice.deposit,
  duplicate: (_invoice: InvoiceRow, rights: InvoiceRights) => rights.write,
  /** Factur-X is written for a company whose preset the API serves, of a document issued and not cancelled. */
  facturX: (invoice: InvoiceRow, rights: InvoiceRights) =>
    rights.facturX && invoice.status !== 'draft' && invoice.status !== 'cancelled',
  printCopies: (invoice: InvoiceRow) => isOpen(invoice),
} as const;

/** What a screen adds to the declaration: what only it knows, and what it does once an action changed the document. */
export interface InvoiceActionsOptions {
  /** What the document's customer has to their credit; a screen that has not read it offers no « Utiliser l'avoir ». */
  creditBalance?: () => string;
  /** Said after an action changed the document, to a screen whose own change never comes back to it as a live one. */
  changed?: (invoice: InvoiceRow) => void;
}

/**
 * What can be done to an invoice or a credit note, declared once (docs/SPEC.md § 7, 2026-10-11 08:26) and read by the
 * record's page, the sheet over the list and the list's rows. The page adds what only a form has: saving, issuing what
 * was typed, the customer display.
 */
@Injectable({ providedIn: 'root' })
export class InvoiceActions {
  private readonly auth = inject(AuthFacade);
  private readonly facade = inject(InvoicesFacade);
  private readonly dialog = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly feedback = inject(Feedback);
  private readonly saver = inject(FileSaver);
  private readonly recurring = inject(RecurringApi);
  private readonly format = inject(FormatFacade);
  private readonly texts = inject(TextsParts);

  rights(): InvoiceRights {
    const country = this.auth.me()?.company?.countryCode;
    return {
      write: this.auth.hasPermission('invoice.write'),
      issue: this.auth.hasPermission('invoice.issue'),
      pay: this.auth.hasPermission('payment.write'),
      credit: this.auth.hasPermission('invoice.credit'),
      recurring: this.auth.hasModule(RECURRING_MODULE),
      facturX: country !== undefined && FACTUR_X_COUNTRIES.includes(country),
    };
  }

  declare(options: InvoiceActionsOptions = {}): RecordAction<InvoiceRow>[] {
    const rights = this.rights();
    const changed = (invoice: InvoiceRow) => options.changed?.(invoice);
    const draft = (invoice: InvoiceRow) => invoice.status === 'draft';
    return [
      {
        id: 'record-payment',
        label: 'invoices.payments.collect',
        icon: 'payments',
        group: 'state',
        frequency: 'next',
        effect: { kind: 'plain' },
        shortcut: 'p',
        shown: (invoice) => invoiceMay.pay(invoice, rights),
        run: (invoice) => void this.pay(invoice, changed),
      },
      {
        id: 'pdf',
        label: (invoice) =>
          draft(invoice) ? 'invoices.actions.pdf_draft' : 'invoices.actions.pdf',
        icon: 'picture_as_pdf',
        group: 'send',
        // A draft's PDF is a preview, rarely wanted; an issued one is what the customer is sent.
        frequency: (invoice) => (draft(invoice) ? 'rare' : 'frequent'),
        effect: { kind: 'plain' },
        shown: () => true,
        href: (invoice) => this.pdfUrl(invoice),
      },
      {
        id: 'duplicate',
        label: 'invoices.actions.duplicate',
        icon: 'content_copy',
        group: 'create',
        frequency: (invoice) => (draft(invoice) ? 'rare' : 'frequent'),
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.duplicate(invoice, rights),
        run: (invoice) => void this.duplicate(invoice),
      },
      {
        id: 'print-duplicate',
        label: 'invoices.actions.print_duplicate',
        icon: 'print',
        group: 'send',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.printCopies(invoice),
        href: (invoice) => this.copyUrl(invoice, 'duplicate'),
      },
      {
        id: 'print-current',
        label: 'invoices.actions.print_current',
        icon: 'sync',
        group: 'send',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.printCopies(invoice),
        href: (invoice) => this.copyUrl(invoice, 'current'),
      },
      {
        id: 'factur-x-pdf',
        label: 'invoices.actions.factur_x_pdf',
        icon: 'picture_as_pdf',
        group: 'send',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.facturX(invoice, rights),
        run: (invoice) => void this.facturX(invoice, 'pdf'),
      },
      {
        id: 'factur-x-xml',
        label: 'invoices.actions.factur_x_xml',
        icon: 'description',
        group: 'send',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.facturX(invoice, rights),
        run: (invoice) => void this.facturX(invoice, 'xml'),
      },
      {
        id: 'apply-credit',
        label: 'invoices.actions.apply_credit',
        icon: 'savings',
        group: 'state',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) =>
          options.creditBalance !== undefined &&
          invoiceMay.pay(invoice, rights) &&
          !isZero(options.creditBalance()),
        run: (invoice) => void this.applyCredit(invoice, changed),
      },
      {
        id: 'record-overpayment',
        label: 'invoices.actions.record_overpayment',
        icon: 'savings',
        group: 'state',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.overpay(invoice, rights),
        run: (invoice) => void this.overpay(invoice, changed),
      },
      {
        id: 'make-recurring',
        label: 'recurring.make.action',
        icon: 'event_repeat',
        group: 'state',
        frequency: 'rare',
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.makeRecurring(invoice, rights),
        run: (invoice) => void this.makeRecurring(invoice),
      },
      {
        id: 'credit-note',
        label: 'invoices.actions.credit_note',
        icon: 'assignment_return',
        group: 'correct',
        frequency: 'rare',
        // It drafts the credit note, asking why first; issuing that draft is what asks with a preview.
        effect: { kind: 'plain' },
        shown: (invoice) => invoiceMay.credit(invoice, rights),
        run: (invoice) => void this.creditNote(invoice),
      },
      {
        id: 'cancel',
        label: 'invoices.actions.cancel',
        icon: 'delete_forever',
        group: 'state',
        frequency: 'rare',
        effect: {
          kind: 'definitif',
          preview: () => ({
            title: 'invoices.actions.cancel_title',
            message: 'invoices.actions.cancel_message',
            confirmLabel: 'invoices.actions.confirm_cancel',
            keepLabel: 'invoices.actions.keep',
          }),
        },
        shown: (invoice) => invoiceMay.abandon(invoice, rights),
        run: (invoice) => void this.abandon(invoice, changed),
      },
    ];
  }

  private companyId(): string | null {
    return this.auth.me()?.company?.id ?? null;
  }

  private today(): string {
    return todayIn(this.auth.me()?.company?.timezone);
  }

  pdfUrl(invoice: InvoiceRow): string | null {
    const companyId = this.companyId();
    return companyId ? this.facade.pdfUrl(companyId, invoice.id) : null;
  }

  private copyUrl(invoice: InvoiceRow, kind: 'duplicate' | 'current'): string | null {
    const companyId = this.companyId();
    return companyId ? this.facade.pdfCopyUrl(companyId, invoice.id, kind) : null;
  }

  /** Asked in a dialog rather than in a form at the foot of a page (design review finding 3). */
  async pay(
    invoice: InvoiceRow,
    changed: (invoice: InvoiceRow) => void = () => undefined,
  ): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const data = {
      descriptor: paymentForm(),
      group: buildFormGroup(paymentForm(), paymentValues(this.today(), invoice.amountDue)),
    };
    const values = await firstValueFrom(
      this.dialog
        .open<PaymentDialog, PaymentDialogData>(PaymentDialog, {
          data,
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (values && (await this.facade.recordPayment(companyId, invoice.id, paymentInput(values)))) {
      this.feedback.success('invoices.payments.recorded');
      changed(invoice);
    }
  }

  /** Pays as much of the invoice from the customer's credit as it holds and the invoice is due. */
  private async applyCredit(
    invoice: InvoiceRow,
    changed: (invoice: InvoiceRow) => void,
  ): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    if (await this.facade.applyCredit(companyId, invoice.id)) {
      this.feedback.success('invoices.payments.credit_applied');
      changed(invoice);
    }
  }

  /** What the customer paid beyond this invoice, kept to their credit (a « trop-perçu »). */
  private async overpay(
    invoice: InvoiceRow,
    changed: (invoice: InvoiceRow) => void,
  ): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const descriptor = overpaymentForm();
    const values = await firstValueFrom(
      this.dialog
        .open<OverpaymentDialog, OverpaymentDialogData>(OverpaymentDialog, {
          data: { descriptor, group: buildFormGroup(descriptor, overpaymentValues(this.today())) },
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (values && (await this.facade.overpay(companyId, invoice.id, overpaymentInput(values)))) {
      this.feedback.success('invoices.overpayment.recorded');
      changed(invoice);
    }
  }

  /** An issued document's Factur-X handed over as a file, or what keeps it from being written said in a dialog. */
  private async facturX(invoice: InvoiceRow, format: FacturXFormat): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const answer = await this.facade.facturX(companyId, invoice.id, format);
    if (answer === null) return;
    if (answer.kind === 'file') {
      this.saver.save(answer.file, answer.filename);
      return;
    }
    this.dialog.open<FacturXRefusalDialog, FacturXRefusal>(FacturXRefusalDialog, {
      data: answer,
      autoFocus: 'first-tabbable',
    });
  }

  /** A copy of the document as a new draft, which is where the person then continues. */
  private async duplicate(invoice: InvoiceRow): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const copy = await this.facade.duplicate(companyId, invoice.id);
    if (copy !== null) {
      this.feedback.success('invoices.duplicated');
      await this.router.navigate(['/invoices', copy.id]);
    }
  }

  /** Asks how often and from when, makes it, and offers the recurring invoices in the toast that says so. */
  private async makeRecurring(invoice: InvoiceRow): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    // The schedule's words, read in the dialog wherever the invoice is shown.
    await this.texts.open(SCHEDULE_TEXTS);
    const descriptor = makeRecurringForm(this.format.locale(), this.today());
    const data: MakeRecurringData = { descriptor, group: buildFormGroup(descriptor, {}) };
    const values = await firstValueFrom(
      this.dialog
        .open<MakeRecurringDialog, MakeRecurringData>(MakeRecurringDialog, {
          data,
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (!values) return;
    try {
      await this.recurring.create(companyId, recurringDraft(invoice.id, values));
      this.feedback.success(
        'recurring.made',
        {},
        { key: 'recurring.open', run: () => void this.router.navigate(['/invoices/recurring']) },
      );
    } catch (error) {
      this.feedback.failure(
        recurringFailure(error instanceof RecurringRefused ? error.field : null),
      );
    }
  }

  /** Asks why first: a credit note states its reason when it is drafted (docs/SPEC.md § 7, 2026-09-24 22:51). */
  private async creditNote(invoice: InvoiceRow): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const data = {
      descriptor: creditNoteForm(),
      group: buildFormGroup(creditNoteForm(), { reason: '' }),
    };
    const reason = await firstValueFrom(
      this.dialog
        .open<CreditNoteDialog, CreditNoteDialogData, string | null>(CreditNoteDialog, {
          data,
          autoFocus: 'first-tabbable',
        })
        .afterClosed(),
    );
    if (!reason) return;
    const credit = await this.facade.creditNote(companyId, invoice.id, reason);
    if (credit !== null) await this.router.navigate(['/invoices', credit.id]);
  }

  /** Voids a draft for good; asked first by whoever runs it, from its preview. */
  private async abandon(
    invoice: InvoiceRow,
    changed: (invoice: InvoiceRow) => void,
  ): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.facade.busy()) return;
    const abandoned = await this.facade.cancel(companyId, invoice.id);
    if (abandoned !== null) {
      this.feedback.effect('invoices.cancelled', {}, 'definitif');
      changed(invoice);
    }
  }
}
