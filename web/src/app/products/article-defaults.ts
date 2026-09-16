// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  effect,
  inject,
  input,
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
  imports: [MatButtonModule, TranslatePipe, DescriptorForm],
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
        if (companyId) {
          void this.settings.load(companyId, subject);
        }
      });
    });
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    const changes = changedSettingsAt(this.settings.rows(), values, this.level());
    if (changes.length === 0 || (await this.settings.save(companyId, this.subject(), changes))) {
      this.feedback.success('products.defaults.saved');
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    if (await this.settings.reset(companyId, this.subject(), key)) {
      this.feedback.success('products.defaults.saved');
    }
  }
}
