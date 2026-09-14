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
import {
  CUSTOM_FIELD_ENTITIES,
  type CustomFieldDefinition,
  type CustomFieldEntity,
} from '../shared/custom-fields/custom-fields-types';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { DataList, DataListCell, DataListRowActions } from '../shared/list/data-list';
import {
  DEFINITIONS_LIST,
  definitionForm,
  definitionFormValues,
  definitionInput,
} from './custom-field-forms';
import { CustomFieldsFacade } from './custom-fields-facade';

/** The fields the company adds to its customers: declared here, retired here, never deleted. */
@Component({
  selector: 'app-custom-fields-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DataList,
    DataListCell,
    DataListRowActions,
    DescriptorForm,
  ],
  templateUrl: './custom-fields-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CustomFieldsPage implements OnInit {
  private readonly facade = inject(CustomFieldsFacade);
  private readonly auth = inject(AuthFacade);

  protected readonly list = DEFINITIONS_LIST;
  protected readonly fields = this.facade.fields;
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly rowTestId = (row: CustomFieldDefinition): string => `custom-field-${row.key}`;

  protected readonly entities = CUSTOM_FIELD_ENTITIES;
  /** The kind of record whose fields are shown and declared. */
  protected readonly entity = signal<CustomFieldEntity>('customer');
  protected readonly editing = signal<CustomFieldDefinition | 'new' | null>(null);
  protected readonly saved = signal(false);
  protected readonly descriptor = computed(() => {
    const editing = this.editing();
    return definitionForm(editing === 'new' || editing === null ? null : editing);
  });
  protected readonly form = computed(() => {
    const editing = this.editing();
    if (editing === null) return null;
    return buildFormGroup(
      this.descriptor(),
      definitionFormValues(editing === 'new' ? null : editing),
    );
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId) {
      await this.facade.load(companyId, this.entity());
    }
  }

  protected async show(entity: CustomFieldEntity): Promise<void> {
    const companyId = this.company()?.id;
    this.editing.set(null);
    this.saved.set(false);
    this.facade.clearError();
    this.entity.set(entity);
    if (companyId) {
      await this.facade.load(companyId, entity);
    }
  }

  protected add(): void {
    this.open('new');
  }

  protected edit(row: CustomFieldDefinition): void {
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
    const declared = editing === 'new' ? null : editing;
    const input = definitionInput(values, declared, this.entity());
    const accepted =
      declared === null
        ? await this.facade.create(companyId, input)
        : await this.facade.revise(companyId, declared.id, input);
    if (accepted) {
      this.editing.set(null);
      this.saved.set(true);
    }
  }

  private open(target: CustomFieldDefinition | 'new'): void {
    this.facade.clearError();
    this.saved.set(false);
    this.editing.set(target);
  }
}
