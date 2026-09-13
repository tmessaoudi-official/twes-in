// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import { FiscalFacade } from './fiscal-facade';
import {
  REGIME_LIST,
  TAX_CREATE_FORM,
  TAX_LIST,
  taxFormValues,
  taxInput,
  taxReviseForm,
} from './fiscal-forms';
import type { CustomerTaxRegimeRow, TaxComponentRow } from './fiscal-types';

/** A company's taxes, which it copied from its fiscal preset and now edits, and the regimes its customers may be under. */
@Component({
  selector: 'app-fiscal-taxes-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    DescriptorForm,
  ],
  templateUrl: './fiscal-taxes-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FiscalTaxesPage implements OnInit {
  private readonly fiscal = inject(FiscalFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = TAX_LIST;
  protected readonly regimeList = REGIME_LIST;
  protected readonly taxes = this.fiscal.taxes;
  protected readonly regimes = this.fiscal.regimes;
  protected readonly busy = this.fiscal.busy;
  protected readonly error = this.fiscal.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('fiscal.write'));
  protected readonly rowTestId = (row: TaxComponentRow): string => `tax-${row.code}`;
  protected readonly regimeTestId = (row: CustomerTaxRegimeRow): string => `regime-${row.code}`;

  /** The tax being revised, "new" while one is being added, null when no form is open. */
  protected readonly editing = signal<TaxComponentRow | 'new' | null>(null);
  protected readonly saved = signal(false);
  protected readonly descriptor = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;
    return editing === 'new' ? TAX_CREATE_FORM : taxReviseForm(editing.family);
  });
  protected readonly form = computed(() => {
    const descriptor = this.descriptor();
    const editing = this.editing();
    if (descriptor === null || editing === null) return null;
    return buildFormGroup(descriptor, editing === 'new' ? {} : taxFormValues(editing));
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.fiscal.loadTaxes(companyId);
    }
  }

  protected add(): void {
    this.open('new');
  }

  protected edit(row: TaxComponentRow): void {
    this.open(row);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.fiscal.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const accepted =
      editing === 'new'
        ? await this.fiscal.createTax(companyId, taxInput(values))
        : await this.fiscal.reviseTax(companyId, editing.id, taxInput(values, editing));
    if (accepted) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }

  private open(target: TaxComponentRow | 'new'): void {
    this.fiscal.clearError();
    this.saved.set(false);
    this.editing.set(target);
  }
}
