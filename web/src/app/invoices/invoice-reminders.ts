// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatCardModule } from '@angular/material/card';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { DayPipe } from '../shared/i18n/format-pipes';
import type { InvoiceReminderRow } from './invoices-types';
import { RemindersApi } from './reminders-api';

/**
 * The stages of the company's reminder calendar a late invoice reached, each with the late fee it drafted when the
 * company charges one. Shown only once a stage is reached: before that there is nothing to chase. A stage says it was
 * time to remind the customer, never that anything was sent, and the card says so.
 */
@Component({
  selector: 'app-invoice-reminders',
  imports: [MatCardModule, RouterLink, TranslatePipe, DayPipe],
  template: `
    @if (error()) {
      <p role="alert" class="text-error" data-testid="invoice-reminders-error">
        {{ 'invoices.reminders.error' | translate }}
      </p>
    } @else if (rows().length > 0) {
      <mat-card data-testid="invoice-reminders">
        <mat-card-content>
          <div class="flex flex-col gap-3">
            <h2 class="text-lg font-semibold">{{ 'invoices.reminders.title' | translate }}</h2>
            <ol class="flex flex-col gap-1">
              @for (row of rows(); track row.id) {
                <li
                  class="flex flex-wrap items-baseline gap-x-3"
                  [attr.data-testid]="'invoice-reminder-' + row.stage"
                >
                  <span>{{
                    'invoices.reminders.stage'
                      | translate
                        : { stage: row.stage, day: (row.reachedOn | day), count: row.daysLate }
                  }}</span>
                  @if (row.lateFeeInvoiceId; as feeId) {
                    <a
                      class="underline"
                      [routerLink]="['/invoices', feeId]"
                      [attr.data-testid]="'invoice-reminder-' + row.stage + '-fee'"
                      >{{ 'invoices.reminders.fee' | translate }}</a
                    >
                  }
                </li>
              }
            </ol>
            <p class="text-sm text-on-surface-variant" data-testid="invoice-reminders-note">
              {{ 'invoices.reminders.note' | translate }}
            </p>
          </div>
        </mat-card-content>
      </mat-card>
    }
  `,
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class InvoiceReminders {
  private readonly api = inject(RemindersApi);

  readonly companyId = input.required<string>();
  readonly invoiceId = input.required<string>();

  protected readonly rows = signal<readonly InvoiceReminderRow[]>([]);
  protected readonly error = signal(false);
  /** The latest read: an answer for an invoice no longer shown is dropped. */
  private request = 0;

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const invoiceId = this.invoiceId();
      untracked(() => void this.load(companyId, invoiceId));
    });
  }

  private async load(companyId: string, invoiceId: string): Promise<void> {
    const request = ++this.request;
    this.rows.set([]);
    this.error.set(false);
    try {
      const rows = await this.api.list(companyId, invoiceId);
      if (request === this.request) {
        this.rows.set(rows);
      }
    } catch {
      if (request === this.request) {
        this.error.set(true);
      }
    }
  }
}
