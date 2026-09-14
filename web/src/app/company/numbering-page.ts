// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
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
import type { NumberingSeriesRow } from './company-types';
import { SERIES_LIST, seriesChanges, seriesForm, seriesFormValues } from './establishment-forms';
import { EstablishmentsFacade } from './establishments-facade';
import { renderNumber } from './number-format';

/** How each establishment numbers each kind of document, with the next number shown while a format is typed. */
@Component({
  selector: 'app-numbering-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    DescriptorForm,
  ],
  templateUrl: './numbering-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NumberingPage implements OnInit {
  private readonly facade = inject(EstablishmentsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = SERIES_LIST;
  protected readonly editing = signal<NumberingSeriesRow | null>(null);
  protected readonly descriptor = computed(() => seriesForm(this.editing()?.numbered ?? false));
  protected readonly series = this.facade.series;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly rowTestId = (row: NumberingSeriesRow): string =>
    `series-${row.establishmentCode}-${row.documentType}`;

  protected readonly saved = signal(false);
  protected readonly form = computed(() => {
    const editing = this.editing();
    return editing === null ? null : buildFormGroup(this.descriptor(), seriesFormValues(editing));
  });
  private readonly draft = signal<FormValues | null>(null);
  protected readonly preview = computed(() => {
    const editing = this.editing();
    const draft = this.draft();
    if (editing === null || draft === null) return null;
    const changes = seriesChanges(draft);
    return renderNumber(changes.format, changes.nextNumber, new Date(), editing.establishmentCode);
  });

  constructor() {
    effect((onCleanup) => {
      const form = this.form();
      if (form === null) {
        this.draft.set(null);
        return;
      }
      this.draft.set(form.getRawValue() as FormValues);
      const subscription = form.valueChanges.subscribe(() =>
        this.draft.set(form.getRawValue() as FormValues),
      );
      onCleanup(() => subscription.unsubscribe());
    });
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadSeries(companyId);
    }
  }

  protected edit(row: NumberingSeriesRow): void {
    this.facade.clearError();
    this.saved.set(false);
    this.editing.set(row);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    if (await this.facade.reviseSeries(companyId, editing.id, seriesChanges(values))) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }
}
