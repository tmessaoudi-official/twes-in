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

/** What the company's documents say about it, revised by whoever holds the settings permission. */
@Component({
  selector: 'app-company-profile-page',
  imports: [MatButtonModule, MatCardModule, TranslatePipe, DescriptorForm, RecordChanged],
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

  async ngOnInit(): Promise<void> {
    const companyId = this.company()?.id;
    if (companyId && this.mayManage()) {
      await this.facade.load(companyId);
    }
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
