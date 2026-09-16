// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, OnInit } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatCardModule } from '@angular/material/card';
import { TranslatePipe } from '@ngx-translate/core';
import { AuthFacade } from '../auth/auth-facade';
import { DescriptorForm } from '../shared/form/descriptor-form';
import { buildFormGroup } from '../shared/form/form-builder';
import type { FormValues } from '../shared/form/form-types';
import { profileChanges, profileForm, profileValues } from './company-profile-form';
import { CompanyProfileFacade } from './company-profile-facade';
import { Feedback } from '../shared/feedback/feedback';

/** What the company's documents say about it, revised by whoever holds the settings permission. */
@Component({
  selector: 'app-company-profile-page',
  imports: [MatButtonModule, MatCardModule, TranslatePipe, DescriptorForm],
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
  protected readonly form = computed(() => {
    const profile = this.facade.profile();
    const descriptor = this.descriptor();
    return profile && descriptor ? buildFormGroup(descriptor, profileValues(profile)) : null;
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
      this.feedback.success('company.profile.saved');
    }
  }
}
