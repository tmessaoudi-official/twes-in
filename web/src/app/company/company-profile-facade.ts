// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { StepUp } from '../shared/step-up/step-up';
import { CompanyApi, CompanyRefused } from './company-api';
import type { CompanyError, CompanyProfile, CompanyProfileChanges } from './company-types';

/** The profile of the company being worked in, and its revision. */
@Injectable({ providedIn: 'root' })
export class CompanyProfileFacade {
  private readonly api = inject(CompanyApi);
  private readonly stepUp = inject(StepUp);
  private readonly profileSignal = signal<CompanyProfile | null>(null);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<CompanyError | null>(null);
  private readonly refusalsSignal = signal<readonly FieldRefusal[]>([]);

  readonly profile = this.profileSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();
  /** The fields the last save was refused on, each with the reason the screens translate. */
  readonly refusals = this.refusalsSignal.asReadonly();

  async load(companyId: string): Promise<void> {
    this.busySignal.set(true);
    try {
      this.profileSignal.set(await this.api.profile(companyId));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** True when the API kept the revision; the page then shows what it kept, normalised. */
  async save(companyId: string, changes: CompanyProfileChanges): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    this.refusalsSignal.set([]);
    try {
      // New bank details wait for the person to prove who they are again; the rest of the profile asks nothing.
      this.profileSignal.set(
        await this.stepUp.retrying(
          () => this.api.reviseProfile(companyId, changes),
          (error) => error instanceof CompanyRefused && error.code === 'step_up_required',
          'step_up.intro_sensitive',
        ),
      );
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      this.refusalsSignal.set(error instanceof CompanyRefused ? error.refusals : []);
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }

  /** True when the API kept the picture; the profile then names the new version, so the screen reads it again. */
  async uploadLogo(companyId: string, file: File): Promise<boolean> {
    return this.changeLogo(() => this.api.uploadLogo(companyId, file));
  }

  /** True when the company has no logo afterwards. */
  async removeLogo(companyId: string): Promise<boolean> {
    return this.changeLogo(async () => {
      await this.api.removeLogo(companyId);
      return null;
    });
  }

  private async changeLogo(change: () => Promise<string | null>): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      const logoVersion = await change();
      this.profileSignal.update((profile) => (profile ? { ...profile, logoVersion } : profile));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): CompanyError {
  return error instanceof CompanyRefused ? error.code : 'network';
}
