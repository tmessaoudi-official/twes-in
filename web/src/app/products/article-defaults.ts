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
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { resetTakenBack } from '../shared/settings/reset-taken-back';
import { AuthFacade } from '../auth/auth-facade';
import type { ScreenAction } from '../shared/actions/screen-action';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { RecordBar } from '../shared/form/record-bar';
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import {
  changedSettingsAt,
  overridesAt,
  settingsForm,
  settingsValues,
  versioned,
  versionsAt,
} from '../shared/settings/setting-forms';
import type { ArticleSubject, SettingChain } from '../shared/settings/settings-types';
import { articleLevelOf, ArticleSettings } from './article-settings-facade';
import { Feedback } from '../shared/feedback/feedback';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { SettingRefusalLines } from '../shared/settings/setting-refusal-lines';
import { FiscalFacade } from '../fiscal/fiscal-facade';
import { withUnitChoice } from '../settings/settings-forms';

const ARTICLE_CHAINS: readonly SettingChain[] = ['articles'];

/**
 * A product's, a product category's or an establishment's defaults: the articles chain at its own level, each field starting at what the
 * level above says, saved only where it was changed, with the values set here listed for a reset.
 */
@Component({
  selector: 'app-article-defaults',
  imports: [
    MatButtonModule,
    TranslatePipe,
    DescriptorForm,
    RecordBar,
    RecordChanged,
    SettingRefusalLines,
  ],
  templateUrl: './article-defaults.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ArticleDefaults {
  private readonly settings = inject(ArticleSettings);
  private readonly feedback = inject(Feedback);
  private readonly translate = inject(TranslateService);
  private readonly auth = inject(AuthFacade);
  private readonly fiscal = inject(FiscalFacade);

  readonly subject = input.required<ArticleSubject>();

  protected readonly busy = this.settings.busy;
  protected readonly error = this.settings.error;
  protected readonly rows = this.settings.rows;
  /** The settings the last save refused that no field of the form carries, named in the card's line. */
  protected readonly unplaced = signal<readonly FieldRefusal[]>([]);
  protected readonly companyId = computed(() => this.auth.me()?.company?.id ?? null);
  protected readonly level = computed(() => articleLevelOf(this.subject()));
  protected readonly intro = computed(() =>
    'productId' in this.subject()
      ? 'products.defaults.intro_product'
      : 'establishmentId' in this.subject()
        ? 'products.defaults.intro_establishment'
        : 'roleId' in this.subject()
          ? 'company.roles.defaults_intro'
          : 'products.defaults.intro_category',
  );
  protected readonly writable = computed(() =>
    this.settings.rows().some((row) => row.writableLevels.includes(this.level())),
  );
  protected readonly descriptor = computed(() =>
    withUnitChoice(
      settingsForm(this.settings.rows(), {
        id: 'article-defaults',
        level: this.level(),
        chains: ARTICLE_CHAINS,
        readOnly: !this.writable(),
      }),
      this.fiscal.units(),
    ),
  );
  /** Bumped when this tab saved or reset, so the form shows the chain as the API now holds it. */
  private readonly revision = signal(0);
  /**
   * Whether the chain and the units are both read. The form waits for them: drawn before the units, the default unit is
   * a code field that turns into a choice under the person's hand, and the form is built again over what they typed.
   */
  protected readonly ready = signal(false);
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
    version: () => versionsAt(this.settings.rows(), this.level()),
  });
  private readonly savedValues = computed(() => this.values());
  /** What is typed and not saved, which the bar shows and leaving the page asks about. */
  protected readonly changes = unsavedChanges(this.form, this.savedValues);
  private readonly shownForm = viewChild(DescriptorForm);
  /**
   * Saving and going back from the bar beside the tab's title. Handed to the bar alone, not declared to the page:
   * the record's own save already answers the page's shortcut, and one key cannot save two forms.
   */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => revertToSaved(this.form(), this.savedValues()),
        shown: changes > 0,
      },
    ];
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
          this.ready.set(false);
          // The units name the default unit's choices; without them the field stays the code it is held as.
          const units = this.auth.hasPermission('fiscal.read')
            ? this.fiscal.loadUnits(companyId)
            : Promise.resolve();
          // Another subject's rows can describe the same fields: build the form again once they are read.
          void Promise.all([this.settings.load(companyId, subject), units]).then(() => {
            this.ready.set(true);
            this.revision.update((revision) => revision + 1);
          });
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

  /** The bar's save goes through the form's own submit: an invalid field is shown where it is. */
  private saveFromBar(): void {
    this.shownForm()?.submit();
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    const changes = versioned(
      changedSettingsAt(this.settings.rows(), values, this.level()),
      this.sync.version(),
    );
    this.unplaced.set([]);
    if (changes.length === 0 || (await this.settings.save(companyId, this.subject(), changes))) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('products.defaults.saved');
    } else if (this.settings.error() === 'record_changed') {
      this.settings.clearError();
      await this.sync.savedElsewhere();
    } else {
      // Each refused setting under its own field; what no field here shows is named in the card's line.
      const refused = this.settings.refusals();
      this.unplaced.set(this.shownForm()?.showRefused(refused) ?? refused);
    }
  }

  protected async reset(key: string): Promise<void> {
    const companyId = this.companyId();
    if (!companyId || this.busy()) return;
    const subject = this.subject();
    await resetTakenBack(this.feedback, this.translate, {
      key,
      level: this.level(),
      rows: () => this.settings.rows(),
      reset: () => this.settings.reset(companyId, subject, key),
      save: (changes) => this.settings.save(companyId, subject, changes),
      written: () => this.revision.update((revision) => revision + 1),
    });
  }
}
