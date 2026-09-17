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
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import {
  changedSettingsAt,
  overridesAt,
  settingsForm,
  settingsValues,
} from '../shared/settings/setting-forms';
import type { ArticleSubject, SettingChain } from '../shared/settings/settings-types';
import { articleLevelOf, ArticleSettings } from './article-settings-facade';
import { Feedback } from '../shared/feedback/feedback';

const ARTICLE_CHAINS: readonly SettingChain[] = ['articles'];

/**
 * A product's or a product category's defaults: the articles chain at its own level, each field starting at what the
 * level above says, saved only where it was changed, with the values set here listed for a reset.
 */
@Component({
  selector: 'app-article-defaults',
  imports: [MatButtonModule, TranslatePipe, DescriptorForm, RecordChanged],
  templateUrl: './article-defaults.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ArticleDefaults {
  private readonly settings = inject(ArticleSettings);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  readonly subject = input.required<ArticleSubject>();

  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  protected readonly level = computed(() => articleLevelOf(this.subject()));
  protected readonly intro = computed(() =>
    'productId' in this.subject()
      ? 'products.defaults.intro_product'
      : 'products.defaults.intro_category',
  );
  protected readonly writable = computed(() =>
    this.settings.rows().some((row) => row.writableLevels.includes(this.level())),
  );
  protected readonly descriptor = computed(() =>
    settingsForm(this.settings.rows(), {
      id: 'article-defaults',
      level: this.level(),
      chains: ARTICLE_CHAINS,
      readOnly: !this.writable(),
    }),
  );
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
      const companyId = this.companyId();
      if (companyId) await this.settings.load(companyId, this.subject());
    },
    saved: () => this.values(),
  });
  protected readonly overrides = computed(() =>
    overridesAt(this.settings.rows(), this.level(), !this.writable()),
  );

  constructor() {
    effect(() => {
      const companyId = this.companyId();
      const subject = this.subject();
      untracked(() => {
        if (companyId) {
          // Another subject's rows can describe the same fields: build the form again once they are read.
          void this.settings
            .load(companyId, subject)
            .then(() => this.revision.update((revision) => revision + 1));
        }
      });
    });
  }

  /** The chain's values at this form's level, for the fields it shows. */
  private values(): FormValues {
    const descriptor = this.descriptor();
    const shown = new Set(descriptor.sections.flatMap((s) => s.fields.map((field) => field.id)));
    const values = Object.entries(settingsValues(this.settings.rows(), this.level()));
    return Object.fromEntries(values.filter(([id]) => shown.has(id)));
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    const changes = changedSettingsAt(this.settings.rows(), values, this.level());
    if (changes.length === 0 || (await this.settings.save(companyId, this.subject(), changes))) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('products.defaults.saved');
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    if (await this.settings.reset(companyId, this.subject(), key)) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('products.defaults.saved');
    }
  }
}
