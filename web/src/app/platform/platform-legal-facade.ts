// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type { LegalLanguage } from '../shared/legal/legal-api';
import type { LegalPage } from '../shared/legal/legal-pages';
import { StepUp } from '../shared/step-up/step-up';
import { stepUpAsked } from '../shared/step-up/step-up-api';
import { PlatformLegalApi } from './platform-legal-api';
import type { LegalIdentity, LegalStatusRow, LegalVersionRow } from './platform-legal-types';

/**
 * The operator's legal pages (docs/SPEC.md § 8 row 148): where each page stands in each language, and the history of
 * the one open. Writing publishes a new version at once; validating marks the latest one checked.
 */
@Injectable({ providedIn: 'root' })
export class PlatformLegalFacade {
  private readonly api = inject(PlatformLegalApi);
  private readonly stepUp = inject(StepUp);
  private readonly overviewSignal = signal<readonly LegalStatusRow[]>([]);
  private readonly versionsSignal = signal<readonly LegalVersionRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly failedSignal = signal(false);
  private readonly identitySignal = signal<Readonly<Record<string, string>>>({});
  private opened: { page: LegalPage; language: LegalLanguage } | null = null;
  /** The version of each placeholder's row when read, which a save sends back. */
  private identityVersions: Readonly<Record<string, number>> = {};

  readonly overview = this.overviewSignal.asReadonly();
  /** The open page's versions in its language, the latest first. */
  readonly versions = this.versionsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  /** The publisher's and host's identity, by placeholder, the empty ones included, in the API's order. */
  readonly identity = this.identitySignal.asReadonly();
  /** Whether the last read or write did not reach the API. */
  readonly failed = this.failedSignal.asReadonly();

  async load(): Promise<void> {
    await this.run(async () => {
      const [overview, identity] = await Promise.all([this.api.overview(), this.api.identity()]);
      this.overviewSignal.set(overview);
      this.holdIdentity(identity);
    });
  }

  /**
   * Saves every placeholder changed, together or not at all. `saved_since`: another operator saved one of them since it
   * was read, nothing was kept, and the identity is read again so the form shows what is saved.
   */
  async saveIdentity(
    changes: Readonly<Record<string, string>>,
  ): Promise<'saved' | 'saved_since' | 'failed'> {
    let since = false;
    const saved = await this.run(async () => {
      const versioned = Object.entries(changes).map(([placeholder, value]) => {
        const version = this.identityVersions[placeholder];
        if (version === undefined) throw new Error(`No version was read for ${placeholder}.`);
        return { placeholder, value, version };
      });
      since = (await this.proven(() => this.api.saveIdentity(versioned))) === 'saved_since';
      this.holdIdentity(await this.api.identity());
    });
    if (!saved) return 'failed';
    return since ? 'saved_since' : 'saved';
  }

  async open(page: LegalPage, language: LegalLanguage): Promise<void> {
    this.opened = { page, language };
    this.versionsSignal.set([]);
    await this.run(async () => this.versionsSignal.set(await this.api.versions(page, language)));
  }

  /** @returns whether a new version was published */
  async write(body: string): Promise<boolean> {
    const opened = this.opened;
    if (opened === null) return false;
    return this.run(async () => {
      await this.proven(() => this.api.write(opened.page, opened.language, body));
      await this.refresh(opened.page, opened.language);
    });
  }

  /** @returns whether the latest version is now validated */
  async validate(): Promise<boolean> {
    const opened = this.opened;
    if (opened === null) return false;
    return this.run(async () => {
      await this.proven(() => this.api.validate(opened.page, opened.language));
      await this.refresh(opened.page, opened.language);
    });
  }

  /** A platform write waits for the operator to prove who is at the screen again (docs/SPEC.md § 7, the step-up list). */
  private proven<T>(call: () => Promise<T>): Promise<T> {
    return this.stepUp.retrying(call, stepUpAsked, 'step_up.intro_change');
  }

  private async refresh(page: LegalPage, language: LegalLanguage): Promise<void> {
    const [overview, versions] = await Promise.all([
      this.api.overview(),
      this.api.versions(page, language),
    ]);
    this.overviewSignal.set(overview);
    this.versionsSignal.set(versions);
  }

  private holdIdentity(identity: LegalIdentity): void {
    this.identitySignal.set(identity.values);
    this.identityVersions = identity.versions;
  }

  private async run(work: () => Promise<void>): Promise<boolean> {
    this.busySignal.set(true);
    try {
      await work();
      this.failedSignal.set(false);
      return true;
    } catch {
      this.failedSignal.set(true);
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}
