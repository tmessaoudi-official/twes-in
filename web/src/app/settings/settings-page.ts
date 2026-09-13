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
import { CompanySettings } from './company-settings-facade';
import {
  changedSettings,
  companyOverrides,
  companySettingsForm,
  companySettingsValues,
} from './settings-forms';

/** The one generic settings page: the company's defaults, rendered by type from the definitions the API returns. */
@Component({
  selector: 'app-settings-page',
  imports: [MatButtonModule, MatCardModule, TranslatePipe, DescriptorForm],
  templateUrl: './settings-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsPage implements OnInit {
  private readonly settings = inject(CompanySettings);
  private readonly auth = inject(AuthFacade);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly saved = signal(false);
  protected readonly descriptor = computed(() => companySettingsForm(this.settings.rows()));
  protected readonly form = computed(() =>
    buildFormGroup(this.descriptor(), companySettingsValues(this.settings.rows())),
  );
  protected readonly overrides = computed(() => companyOverrides(this.settings.rows()));

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && this.mayManage()) {
      await this.settings.load(companyId);
    }
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    const changes = changedSettings(this.settings.rows(), values);
    if (changes.length === 0 || (await this.settings.save(companyId, changes))) {
      this.saved.set(true);
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    if (await this.settings.reset(companyId, key)) {
      this.saved.set(true);
    }
  }
}
