// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { FormControl, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { todayIn } from '../shared/i18n/format';
import type { ExportFormat } from '../shared/list/export-address';
import { ListExport } from '../shared/list/list-export';
import {
  type AccountingFile,
  ACCOUNTING_FILES,
  accountingFileAddress,
  isPeriod,
  lastMonth,
} from './accounting-files';

/**
 * « Export comptable »: the period, last month unless changed, and the four files an accountant takes for it, each as
 * CSV or Excel. A file waits for the person to have proved who they are, as every file leaving the company does.
 */
@Component({
  selector: 'app-accounting-export-page',
  imports: [ReactiveFormsModule, MatFormFieldModule, MatInputModule, TranslatePipe, ListExport],
  templateUrl: './accounting-export-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AccountingExportPage {
  private readonly auth = inject(AuthFacade);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly files = ACCOUNTING_FILES;
  private readonly lastMonthAsked = lastMonth(todayIn(this.company()?.timezone));
  protected readonly period = new FormGroup({
    from: new FormControl(this.lastMonthAsked.from, {
      nonNullable: true,
      validators: Validators.required,
    }),
    to: new FormControl(this.lastMonthAsked.to, {
      nonNullable: true,
      validators: Validators.required,
    }),
  });
  private readonly asked = toSignal(this.period.valueChanges, {
    initialValue: this.period.getRawValue(),
  });

  /** Writes a file's address, or is null while the period is not one the API takes. */
  protected readonly addresses = computed(() => {
    const companyId = this.company()?.id;
    const { from = '', to = '' } = this.asked();
    if (!companyId || !isPeriod(from, to)) return null;
    return (file: AccountingFile) => (format: ExportFormat) =>
      accountingFileAddress(companyId, file, from, to, format);
  });
}
