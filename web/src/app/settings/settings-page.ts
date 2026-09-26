// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  OnInit,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { CompanySettings } from './company-settings-facade';
import {
  changedSettings,
  companyOverrides,
  companySettingsForm,
  companySettingsValues,
} from './settings-forms';
import { Feedback } from '../shared/feedback/feedback';
import { ThemeFacade } from '../shared/theme/theme-facade';

/** A planned module's settings, said in words: what a company will be able to set once it ships. */
export interface PlannedSetting {
  /** The planned module's key in the API's catalogue; the card shows only while the catalogue lists it. */
  readonly module: string;
  /** A translation key: one sentence naming what will be configurable. */
  readonly label: string;
}

/**
 * The planned modules that will have settings, in the menu's order (docs/SPEC.md § 7, 2026-09-26 10:08 and 18:17,
 * row 150): each is one « Bientôt » card, with no field and no default that would read as already chosen. A module
 * that ships takes its card away and brings its real section with it.
 */
export const PLANNED_SETTINGS: readonly PlannedSetting[] = [
  { module: 'quotes', label: 'coming.quotes.settings' },
  { module: 'recurring', label: 'coming.recurring.settings' },
  { module: 'statements', label: 'coming.statements.settings' },
  { module: 'mailing', label: 'coming.mailing.settings' },
  { module: 'whatsapp', label: 'coming.whatsapp.settings' },
  { module: 'portal', label: 'coming.portal.settings' },
  { module: 'price_lists', label: 'coming.price_lists.settings' },
  { module: 'register', label: 'coming.register.settings' },
  { module: 'stock_valuation', label: 'coming.stock_valuation.settings' },
  { module: 'purchases', label: 'coming.purchases.settings' },
  { module: 'declarations', label: 'coming.declarations.settings' },
  { module: 'accounting_export', label: 'coming.accounting_export.settings' },
  { module: 'einvoicing', label: 'coming.einvoicing.settings' },
  { module: 'currencies', label: 'coming.currencies.settings' },
  { module: 'zakat', label: 'coming.zakat.settings' },
];

/** The one generic settings page: the company's defaults, rendered by type from the definitions the API returns. */
@Component({
  selector: 'app-settings-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    RouterLink,
    TranslatePipe,
    DescriptorForm,
    RecordChanged,
  ],
  templateUrl: './settings-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SettingsPage implements OnInit {
  private readonly settings = inject(CompanySettings);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);
  private readonly theme = inject(ThemeFacade);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly descriptor = computed(() => companySettingsForm(this.settings.rows()));
  /** Bumped when this tab saved or reset, so the form shows the chain as the API now holds it. */
  private readonly revision = signal(0);
  /**
   * What the form is of: the fields shown. Reading the chain again yields new rows with the same content, and must
   * not rebuild the form over what is being typed; another person's save is merged into it instead.
   */
  private readonly formKey = computed(
    () => `${this.revision()}|${JSON.stringify(this.descriptor())}`,
  );
  protected readonly form = computed(() => {
    this.formKey();
    return untracked(() => buildFormGroup(this.descriptor(), this.values()));
  });
  /** The saved chain the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'setting',
    id: () => null,
    // A setting is saved under its own row: any setting of the company may be one this form shows.
    matches: () => true,
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.settings.load(companyId);
    },
    saved: () => this.values(),
  });
  protected readonly overrides = computed(() => companyOverrides(this.settings.rows()));
  /** The planned modules' settings the API still lists as planned, while the person shows what is coming. */
  protected readonly comingSettings = computed(() => {
    if (!this.theme.showComing()) return [];
    const planned = new Set((this.auth.me()?.plannedModules ?? []).map((each) => each.key));
    return PLANNED_SETTINGS.filter((setting) => planned.has(setting.module));
  });

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && this.mayManage()) {
      await this.settings.load(companyId);
    }
  }

  /** The chain's values at this form's level, for the fields it shows. */
  private values(): FormValues {
    return companySettingsValues(this.settings.rows());
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    const changes = changedSettings(this.settings.rows(), values);
    if (changes.length === 0 || (await this.settings.save(companyId, changes))) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('settings.saved');
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.company()?.id;
    if (!companyId || this.busy()) return;
    if (await this.settings.reset(companyId, key)) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('settings.saved');
    }
  }
}
