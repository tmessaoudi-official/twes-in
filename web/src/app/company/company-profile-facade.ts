// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable } from '@angular/core';
import { requestState } from '../shared/feedback/request-state';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { StepUp } from '../shared/step-up/step-up';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyProfile, CompanyProfileChanges } from './company-types';
import { sessionSignal } from '../shared/session/session-data';
import type { UnknownRefusal } from '../shared/errors/unknown-refusal';

/** The profile of the company being worked in, and its revision. */
@Injectable({ providedIn: 'root' })
export class CompanyProfileFacade {
  private readonly api = inject(CompanyApi);
  private readonly stepUp = inject(StepUp);
  private readonly profileSignal = sessionSignal<CompanyProfile | null>(null);
  private readonly state = requestState(codeOf);
  private readonly refusalsSignal = sessionSignal<readonly FieldRefusal[]>([]);
  private readonly logoRefusalSignal = sessionSignal<FieldRefusal | null>(null);

  readonly profile = this.profileSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;
  /** Whether `error` is a read's failure, which « Réessayer » answers; a refused save is not. */
  readonly readFailed = this.state.readFailed;
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();
  /** What the API refused of the picture last sent, for the zone that took it to say; null once one is kept. */
  readonly logoRefusal = this.logoRefusalSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.logoRefusalSignal.set(null);
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
    this.logoRefusalSignal.set(null);
    const answer = await this.state.writeOrRefuse(async () => {
      const logoVersion = await this.api.uploadLogo(companyId, file);
      this.profileSignal.update((profile) => (profile ? { ...profile, logoVersion } : profile));
    }, refusalsOf);
    // What was refused of the picture (empty, too large or wide, not one) is said where it was chosen.
    if (answer !== null && 'refused' in answer)
      this.logoRefusalSignal.set(answer.refused[0] ?? null);
    return answer !== null && 'kept' in answer;
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

function codeOf(error: unknown): CompanyError | UnknownRefusal {
  return error instanceof CompanyRefused ? error.code : 'network';
}

function refusalsOf(error: unknown): readonly FieldRefusal[] {
  return error instanceof CompanyRefused ? error.refusals : [];
}
