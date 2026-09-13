// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import {
  changedSettingsAt,
  overridesAt,
  settingsForm,
  settingsValues,
} from '../shared/settings/setting-forms';
import type { SettingChain, SettingSubject } from '../shared/settings/settings-types';
import { levelOf, PartySettings } from './party-settings-facade';

const PARTY_CHAINS: readonly SettingChain[] = ['parties'];

/**
 * A customer's or a customer group's defaults: the parties chain at its own level, each field starting at what the
 * level above says, saved only where it was changed, with the values set here listed for a reset.
 */
@Component({
  selector: 'app-party-defaults',
  imports: [MatButtonModule, TranslatePipe, DescriptorForm],
  templateUrl: './party-defaults.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PartyDefaults {
  private readonly settings = inject(PartySettings);
  private readonly auth = inject(AuthFacade);

  readonly subject = input.required<SettingSubject>();

  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly saved = signal(false);
  protected readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  protected readonly level = computed(() => levelOf(this.subject()));
  protected readonly intro = computed(() =>
    'customerId' in this.subject()
      ? 'customers.defaults.intro_customer'
      : 'customers.defaults.intro_group',
  );
  protected readonly writable = computed(() =>
    this.settings.rows().some((row) => row.writableLevels.includes(this.level())),
  );
  protected readonly descriptor = computed(() =>
    settingsForm(this.settings.rows(), {
      id: 'party-defaults',
      level: this.level(),
      chains: PARTY_CHAINS,
      readOnly: !this.writable(),
    }),
  );
  protected readonly form = computed(() => {
    const descriptor = this.descriptor();
    const shown = new Set(descriptor.sections.flatMap((s) => s.fields.map((field) => field.id)));
    const values = Object.entries(settingsValues(this.settings.rows(), this.level()));
    return buildFormGroup(descriptor, Object.fromEntries(values.filter(([id]) => shown.has(id))));
  });
  protected readonly overrides = computed(() =>
    overridesAt(this.settings.rows(), this.level(), !this.writable()),
  );

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const subject = this.subject();
      untracked(() => {
        this.saved.set(false);
        if (companyId) {
          void this.settings.load(companyId, subject);
        }
      });
    });
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    const changes = changedSettingsAt(this.settings.rows(), values, this.level());
    if (changes.length === 0 || (await this.settings.save(companyId, this.subject(), changes))) {
      this.saved.set(true);
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    this.saved.set(false);
    if (await this.settings.reset(companyId, this.subject(), key)) {
      this.saved.set(true);
    }
  }
}
