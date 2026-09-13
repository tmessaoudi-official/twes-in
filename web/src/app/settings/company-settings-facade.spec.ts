// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingChain, SettingRow } from '../shared/settings/settings-types';
import { CompanySettings } from './company-settings-facade';

const rowOf = (chain: SettingChain): SettingRow => ({ key: `${chain}.one`, chain }) as SettingRow;

describe('CompanySettings', () => {
  let api: {
    chain: ReturnType<typeof vi.fn>;
    change: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
  };
  let facade: CompanySettings;

  beforeEach(() => {
    api = {
      chain: vi.fn(async (_companyId: string, chain: SettingChain) => [rowOf(chain)]),
      change: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn().mockResolvedValue(undefined),
    };
    TestBed.configureTestingModule({ providers: [{ provide: SettingsApi, useValue: api }] });
    facade = TestBed.inject(CompanySettings);
  });

  it('reads the three chains a company sets defaults in', async () => {
    await facade.load('c1');

    expect(api.chain.mock.calls).toEqual([
      ['c1', 'parties'],
      ['c1', 'articles'],
      ['c1', 'presentation'],
    ]);
    expect(facade.rows().map((setting) => setting.chain)).toEqual([
      'parties',
      'articles',
      'presentation',
    ]);
    expect(facade.error()).toBeNull();
  });

  it('stores each change at the company level, then reads the settings again', async () => {
    const accepted = await facade.save('c1', [
      { key: 'document.payment_terms_days', value: 45 },
      { key: 'article.stock_tracking', value: true },
    ]);

    expect(accepted).toBe(true);
    expect(api.change.mock.calls).toEqual([
      ['c1', 'document.payment_terms_days', 'company', 45],
      ['c1', 'article.stock_tracking', 'company', true],
    ]);
    expect(api.chain).toHaveBeenCalledTimes(3);
  });

  it("forgets the company's value on reset", async () => {
    expect(await facade.reset('c1', 'document.payment_terms_days')).toBe(true);

    expect(api.reset).toHaveBeenCalledWith('c1', 'document.payment_terms_days', 'company');
    expect(api.chain).toHaveBeenCalledTimes(3);
  });

  it('names what the API refused', async () => {
    api.change.mockRejectedValue(new SettingsRefused('invalid'));

    expect(await facade.save('c1', [{ key: 'document.payment_terms_days', value: 999 }])).toBe(
      false,
    );
    expect(facade.error()).toBe('invalid');

    api.chain.mockRejectedValue(new Error('offline'));
    await facade.load('c1');
    expect(facade.error()).toBe('network');
  });
});
