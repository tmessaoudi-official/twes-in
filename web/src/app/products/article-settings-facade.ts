// SPDX-License-Identifier: AGPL-3.0-or-later

import { requestState } from '../shared/feedback/request-state';
import { inject, Injectable, signal } from '@angular/core';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type {
  ArticleSubject,
  SettingLevel,
  SettingRow,
  SettingsError,
  VersionedSettingChange,
} from '../shared/settings/settings-types';

/** The level a product's, a product category's or an establishment's own values are stored at. */
export const articleLevelOf = (subject: ArticleSubject): SettingLevel =>
  'productId' in subject
    ? 'product'
    : 'establishmentId' in subject
      ? 'establishment'
      : 'product_category';

/** The articles chain as one product, product category or establishment sees it, read and changed at its own level. */
@Injectable({ providedIn: 'root' })
export class ArticleSettings {
  private readonly api = inject(SettingsApi);
  private readonly rowsSignal = signal<readonly SettingRow[]>([]);
  private readonly state = requestState(codeOf);

  readonly rows = this.rowsSignal.asReadonly();
  readonly busy = this.state.busy;
  readonly error = this.state.error;

  async load(companyId: string, subject: ArticleSubject): Promise<void> {
    await this.state.read(async () =>
      this.rowsSignal.set(await this.api.chain(companyId, 'articles', subject)),
    );
  }

  /** True when the API accepted every change, together; the chain is read again after. */
  async save(
    companyId: string,
    subject: ArticleSubject,
    changes: readonly VersionedSettingChange[],
  ): Promise<boolean> {
    return this.write(companyId, subject, () =>
      this.api.changeAll(companyId, articleLevelOf(subject), changes, subject),
    );
  }

  /** Forgets the subject's own value: the setting falls back to the category's or the company's. */
  async reset(companyId: string, subject: ArticleSubject, key: string): Promise<boolean> {
    return this.write(companyId, subject, () =>
      this.api.reset(companyId, key, articleLevelOf(subject), undefined, subject),
    );
  }

  clearError(): void {
    this.state.clearError();
  }

  private async write(
    companyId: string,
    subject: ArticleSubject,
    call: () => Promise<unknown>,
  ): Promise<boolean> {
    return this.state.write(async () => {
      await call();
      this.rowsSignal.set(await this.api.chain(companyId, 'articles', subject));
    });
  }
}

function codeOf(error: unknown): SettingsError {
  return error instanceof SettingsRefused ? error.code : 'network';
}
