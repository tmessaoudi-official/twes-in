// SPDX-License-Identifier: AGPL-3.0-or-later

import { inject, Injectable, signal } from '@angular/core';
import type { SettingChange } from '../shared/settings/setting-forms';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type {
  ArticleSubject,
  SettingLevel,
  SettingRow,
  SettingsError,
} from '../shared/settings/settings-types';

/** The level a product's or a product category's own values are stored at. */
export const articleLevelOf = (subject: ArticleSubject): SettingLevel =>
  'productId' in subject ? 'product' : 'product_category';

/** The articles chain as one product or one product category sees it, read and changed at its own level. */
@Injectable({ providedIn: 'root' })
export class ArticleSettings {
  private readonly api = inject(SettingsApi);
  private readonly rowsSignal = signal<readonly SettingRow[]>([]);
  private readonly busySignal = signal(false);
  private readonly errorSignal = signal<SettingsError | null>(null);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.busySignal.asReadonly();
  readonly error = this.errorSignal.asReadonly();

  async load(companyId: string, subject: ArticleSubject): Promise<void> {
    this.busySignal.set(true);
    try {
      this.rowsSignal.set(await this.api.chain(companyId, 'articles', subject));
      this.errorSignal.set(null);
    } catch (error) {
      this.errorSignal.set(codeOf(error));
    } finally {
      this.busySignal.set(false);
    }
  }

  /** True when the API accepted every change; the chain is read again after. */
  async save(
    companyId: string,
    subject: ArticleSubject,
    changes: readonly SettingChange[],
  ): Promise<boolean> {
    return this.write(companyId, subject, async () => {
      for (const change of changes) {
        await this.api.change(
          companyId,
          change.key,
          articleLevelOf(subject),
          change.value,
          undefined,
          subject,
        );
      }
    });
  }

  /** Forgets the subject's own value: the setting falls back to the category's or the company's. */
  async reset(companyId: string, subject: ArticleSubject, key: string): Promise<boolean> {
    return this.write(companyId, subject, () =>
      this.api.reset(companyId, key, articleLevelOf(subject), undefined, subject),
    );
  }

  clearError(): void {
    this.errorSignal.set(null);
  }

  private async write(
    companyId: string,
    subject: ArticleSubject,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    this.busySignal.set(true);
    this.errorSignal.set(null);
    try {
      await call();
      this.rowsSignal.set(await this.api.chain(companyId, 'articles', subject));
      return true;
    } catch (error) {
      this.errorSignal.set(codeOf(error));
      return false;
    } finally {
      this.busySignal.set(false);
    }
  }
}

function codeOf(error: unknown): SettingsError {
  return error instanceof SettingsRefused ? error.code : 'network';
}
