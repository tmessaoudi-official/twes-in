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
import type { EstablishmentRow } from './company-types';
import {
  ESTABLISHMENT_LIST,
  establishmentForm,
  establishmentFormValues,
  establishmentInput,
} from './establishment-forms';
import { EstablishmentsFacade } from './establishments-facade';

/** The places the company issues documents from, one of them its default. */
@Component({
  selector: 'app-establishments-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    DescriptorForm,
  ],
  templateUrl: './establishments-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EstablishmentsPage implements OnInit {
  private readonly facade = inject(EstablishmentsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = ESTABLISHMENT_LIST;
  protected readonly establishments = this.facade.establishments;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly rowTestId = (row: EstablishmentRow): string => `establishment-${row.code}`;

  protected readonly editing = signal<EstablishmentRow | 'new' | null>(null);
  protected readonly saved = signal(false);
  protected readonly descriptor = computed(() =>
    establishmentForm(this.establishments()[0]?.codePattern ?? ''),
  );
  protected readonly form = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;
    return buildFormGroup(
      this.descriptor(),
      editing === 'new' ? {} : establishmentFormValues(editing),
    );
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadEstablishments(companyId);
    }
  }

  protected add(): void {
    this.open('new');
  }

  protected edit(row: EstablishmentRow): void {
    this.open(row);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = establishmentInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createEstablishment(companyId, input)
        : await this.facade.reviseEstablishment(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }

  private open(target: EstablishmentRow | 'new'): void {
    this.facade.clearError();
    this.saved.set(false);
    this.editing.set(target);
  }
}
