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
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { DataList, DataListRowActions } from '../shared/list/data-list';
import { GROUP_FORM, GROUPS_LIST, groupInput, groupValues } from './customer-forms';
import { CustomersFacade } from './customers-facade';
import type { CustomerGroupRow } from './customers-types';

/** The groups customers are sorted into; a group's settings apply to every customer in it. */
@Component({
  selector: 'app-customer-groups-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DataList,
    DataListRowActions,
    DescriptorForm,
  ],
  templateUrl: './customer-groups-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomerGroupsPage implements OnInit {
  private readonly facade = inject(CustomersFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = GROUPS_LIST;
  protected readonly descriptor = GROUP_FORM;
  protected readonly groups = this.facade.groups;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayWrite = computed(() => this.auth.hasPermission('customer.write'));
  protected readonly rowTestId = (row: CustomerGroupRow): string => `customer-group-${row.name}`;

  protected readonly editing = signal<CustomerGroupRow | 'new' | null>(null);
  protected readonly saved = signal(false);
  protected readonly form = computed(() => {
    const editing = this.editing();
    return editing === null
      ? null
      : buildFormGroup(GROUP_FORM, groupValues(editing === 'new' ? null : editing));
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.loadGroups(companyId);
    }
  }

  protected open(target: CustomerGroupRow | 'new'): void {
    this.facade.clearError();
    this.saved.set(false);
    this.editing.set(target);
  }

  protected cancel(): void {
    this.editing.set(null);
    this.facade.clearError();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const editing = this.editing();
    if (!companyId || editing === null || this.busy()) return;
    const input = groupInput(values);
    const accepted =
      editing === 'new'
        ? await this.facade.createGroup(companyId, input)
        : await this.facade.reviseGroup(companyId, editing.id, input);
    if (accepted) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }

  protected async remove(row: CustomerGroupRow): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    await this.facade.deleteGroup(companyId, row.id);
  }
}
