// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { StepUp } from '../shared/step-up/step-up';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyProfile, CompanyProfileChanges } from './company-types';
import { sessionSignal } from '../shared/session/session-data';

/** The profile of the company being worked in, and its revision. */
@Injectable({ providedIn: 'root' })
export class CompanyProfileFacade {
  private readonly api = inject(CompanyApi);
  private readonly stepUp = inject(StepUp);
  private readonly profileSignal = sessionSignal<CompanyProfile | null>(null);
  private readonly state = requestState(codeOf);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);

  readonly profile = this.profileSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    await this.state.read(async () => this.profileSignal.set(await this.api.profile(companyId)));
  }

  /** True when the API kept the revision; the page then shows what it kept, normalised. */
  async save(
    companyId: string,
    changes: CompanyProfileChanges,
    version: number | null,
  ): Promise<boolean> {
    this.refusalsSignal.set([]);
    return this.state.write(
      async () => {
        // New bank details wait for the person to prove who they are again; the rest of the profile asks nothing.
        this.profileSignal.set(
          await this.stepUp.retrying(
            () => this.api.reviseProfile(companyId, changes, version),
            (error) => error instanceof CompanyRefused && error.code === 'step_up_required',
            'step_up.intro_sensitive',
          ),
        );
      },
      { failed: (error) => this.refusalsSignal.set(refusalsOf(error)) },
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  /** True when the API kept the picture; the profile then names the new version, so the screen reads it again. */
  async uploadLogo(companyId: string, file: File): Promise<boolean> {
    return this.changeLogo(() => this.api.uploadLogo(companyId, file));
  }

  /**
   * The logo shown now, read before a removal so « Annuler » can upload it again; null when there is none or it could
   * not be read, and the removal then offers no way back.
   */
  async currentLogo(companyId: string): Promise<File | null> {
    const version = this.profileSignal()?.logoVersion ?? null;
    if (version === null) return null;
    try {
      return await this.api.logoFile(companyId, version);
    } catch {
      return null;
    }
  }

  /** True when the company has no logo afterwards. */
  async removeLogo(companyId: string): Promise<boolean> {
    return this.changeLogo(async () => {
      await this.api.removeLogo(companyId);
      return null;
    });
  }

  private async changeLogo(change: () => Promise<string | null>): Promise<boolean> {
    return this.state.write(async () => {
      const logoVersion = await change();
      this.profileSignal.update((profile) => (profile ? { ...profile, logoVersion } : profile));
    });
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}

function refusalsOf(error: unknown): readonly FieldRefusal[] {
  return error instanceof CompanyRefused ? error.refusals : [];
}
