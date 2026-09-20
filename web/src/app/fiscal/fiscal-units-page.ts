// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  OnInit,
  signal,
} from '@angular/core';
import { LiveChanges } from '../shared/realtime/live-changes';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import type { ListDescriptor } from '../shared/list/list-types';
import { DataList, DataListCell } from '../shared/list/data-list';
import { FiscalFacade } from './fiscal-facade';
import {
  UNIT_CREATE_FORM,
  UNIT_LIST,
  UNIT_REVISE_FORM,
  unitFormValues,
  unitInput,
} from './fiscal-forms';
import type { UnitRow } from './fiscal-types';
import { Feedback } from '../shared/feedback/feedback';

/** The units a company sells in: UN/ECE Recommendation 20 codes, named in the company's own words. */
@Component({
  selector: 'app-fiscal-units-page',
  imports: [MatButtonModule, MatCardModule, TranslatePipe, DataList, DataListCell, DescriptorForm],
  templateUrl: './fiscal-units-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class FiscalUnitsPage implements OnInit {
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);
  private readonly fiscal = inject(FiscalFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  /** A unit is retired through its form rather than deleted, so editing is its one action. */
  protected readonly list = computed<ListDescriptor<UnitRow>>(() => ({
    ...UNIT_LIST,
    actions: [
      {
        id: 'edit',
        label: 'fiscal.edit',
        icon: 'edit',
        run: (row) => this.edit(row),
        disabled: () => this.busy(),
        shown: () => this.mayManage(),
      },
    ],
  }));
  protected readonly units = this.fiscal.units;
  protected readonly busy = this.fiscal.busy;
  protected readonly error = this.fiscal.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('fiscal.write'));
  protected readonly rowTestId = (row: UnitRow): string => `unit-${row.code}`;

  protected readonly editing = signal<UnitRow | 'new' | null>(null);
  protected readonly descriptor = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;
    return editing === 'new' ? UNIT_CREATE_FORM : UNIT_REVISE_FORM;
  });
  protected readonly form = computed(() => {
    const descriptor = this.descriptor();
    const editing = this.editing();
    if (descriptor === null || editing === null) return null;
    return buildFormGroup(descriptor, editing === 'new' ? {} : unitFormValues(editing));
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      this.live.reloadOn(['unit'], () => this.fiscal.loadUnits(companyId), this.destroyRef);
      await this.fiscal.loadUnits(companyId);
    }
  }

  protected add(): void {
    this.open('new');
  }

  protected edit(row: UnitRow): void {
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
        ? await this.fiscal.createUnit(companyId, unitInput(values))
        : await this.fiscal.reviseUnit(companyId, editing.id, unitInput(values, editing.code));
    if (accepted) {
      this.editing.set(null);
      this.feedback.success('fiscal.units.saved');
    }
  }

  private open(target: UnitRow | 'new'): void {
    this.fiscal.clearError();
    this.editing.set(target);
  }
}
