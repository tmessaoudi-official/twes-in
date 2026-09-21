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
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { liveRecord } from '../shared/form/live-record';
import { RecordChanged } from '../shared/form/record-changed';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { profileChanges, profileForm, profileValues } from './company-profile-form';
import { CompanyProfileFacade } from './company-profile-facade';
import { Feedback } from '../shared/feedback/feedback';
import { revertToSaved, unsavedChanges } from '../shared/form/dirty-count';
import { ScreenActions } from '../shared/actions/screen-actions';
import type { ScreenAction } from '../shared/actions/screen-action';
import { RecordBar } from '../shared/form/record-bar';

/** What the company's documents say about it, revised by whoever holds the settings permission. */
@Component({
  selector: 'app-company-profile-page',
  imports: [
    MatButtonModule,
    MatCardModule,
    TranslatePipe,
    DescriptorForm,
    RecordBar,
    RecordChanged,
  ],
  templateUrl: './company-profile-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CompanyProfilePage implements OnInit {
  private readonly facade = inject(CompanyProfileFacade);
  private readonly feedback = inject(Feedback);
  private readonly auth = inject(AuthFacade);

  protected readonly company = computed(() => this.auth.me()?.company ?? null);
  protected readonly mayManage = computed(() => this.auth.hasPermission('company.settings'));
  protected readonly busy = this.facade.busy;
  protected readonly error = this.facade.error;
  protected readonly descriptor = computed(() => {
    const profile = this.facade.profile();
    return profile ? profileForm(profile) : null;
  });
  /** Bumped when this tab saved, so the form shows what the API kept, normalised. */
  private readonly revision = signal(0);
  /**
   * What the form is of: the fields shown. Reading the profile again yields new objects with the same content, and
   * must not rebuild the form over what is being typed; another person's save is merged into it instead.
   */
  private readonly formKey = computed(() => {
    const descriptor = this.descriptor();
    return descriptor === null ? null : `${this.revision()}|${JSON.stringify(descriptor)}`;
  });
  protected readonly form = computed(() => {
    if (this.formKey() === null) return null;
    return untracked(() => {
      const profile = this.facade.profile();
      const descriptor = this.descriptor();
      return profile && descriptor ? buildFormGroup(descriptor, profileValues(profile)) : null;
    });
  });
  /** The saved profile the form stands on, and what another person's save changed in it. */
  protected readonly sync = liveRecord({
    kind: 'company',
    id: computed(() => this.company()?.id ?? null),
    form: this.form,
    reload: async () => {
      const companyId = this.company()?.id;
      if (companyId) await this.facade.load(companyId);
    },
    saved: () => {
      const profile = this.facade.profile();
      return profile ? profileValues(profile) : null;
    },
  });

  /** What the form holds that the API has not been told yet; the bar beside the title shows it. */
  protected readonly savedValues = computed(() => {
    const profile = this.facade.profile();
    return profile ? profileValues(profile) : null;
  });
  protected readonly changes = unsavedChanges(this.form, this.savedValues);

  constructor() {
    // The same list the bar draws also answers the keyboard, the palette and the "?" sheet (row 45).
    inject(ScreenActions).declare(this.recordActions);
  }

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && this.mayManage()) {
      await this.facade.load(companyId);
    }
  }

  /**
   * What this page offers, declared once (row 45): the bar beside the title draws it, and the keyboard, the Ctrl K
   * palette and the "?" sheet read the same list — so "s" saves here as it does on a document.
   */
  protected readonly recordActions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    const may = this.mayManage();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        // A save that is always available teaches nothing about whether there is anything to save.
        disabled: busy || changes === 0,
        run: () => this.saveFromBar(),
        shown: may,
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        run: () => this.revert(),
        shown: may && changes > 0,
      },
    ];
  });

  /** From the bar beside the title, which holds no form of its own. */
  protected saveFromBar(): void {
    const form = this.form();
    if (form === null) return;
    if (form.invalid) {
      form.markAllAsTouched();
      return;
    }
    void this.save(form.getRawValue());
  }

  protected revert(): void {
    const form = this.form();
    const saved = this.savedValues();
    if (form !== null && saved !== null) revertToSaved(form, saved);
  }

  protected async save(values: FormValues): Promise<void> {
    const companyId = this.company()?.id;
    const profile = this.facade.profile();
    if (!companyId || !profile || this.busy()) return;
    if (await this.facade.save(companyId, profileChanges(profile, values))) {
      this.revision.update((revision) => revision + 1);
      this.feedback.success('company.profile.saved');
    }
  }
}
