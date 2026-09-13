// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { SettingsApi, SettingsRefused } from '../shared/settings/settings-api';
import type { SettingRow } from '../shared/settings/settings-types';
import { PartySettings } from './party-settings-facade';

const terms = { key: 'document.payment_terms_days', chain: 'parties' } as SettingRow;

describe('PartySettings', () => {
  let api: {
    chain: ReturnType<typeof vi.fn>;
    change: ReturnType<typeof vi.fn>;
    reset: ReturnType<typeof vi.fn>;
  };
  let facade: PartySettings;

  beforeEach(() => {
    api = {
      chain: vi.fn().mockResolvedValue([terms]),
      change: vi.fn().mockResolvedValue(undefined),
      reset: vi.fn().mockResolvedValue(undefined),
    };
    TestBed.configureTestingModule({ providers: [{ provide: SettingsApi, useValue: api }] });
    facade = TestBed.inject(PartySettings);
  });

  it('reads the parties chain as the customer sees it', async () => {
    await facade.load('c1', { customerId: 'k1' });

    expect(api.chain).toHaveBeenCalledWith('c1', 'parties', { customerId: 'k1' });
    expect(facade.rows()).toEqual([terms]);
    expect(facade.error()).toBeNull();
  });

  it("stores each change at the group's level, naming the group, then reads again", async () => {
    const group = { customerGroupId: 'g1' };

    expect(await facade.save('c1', group, [{ key: terms.key, value: 45 }])).toBe(true);

    expect(api.change).toHaveBeenCalledWith(
      'c1',
      terms.key,
      'customer_group',
      45,
      undefined,
      group,
    );
    expect(api.chain).toHaveBeenCalledWith('c1', 'parties', group);
  });

  it("forgets the customer's own value", async () => {
    expect(await facade.reset('c1', { customerId: 'k1' }, terms.key)).toBe(true);

    expect(api.reset).toHaveBeenCalledWith('c1', terms.key, 'customer', undefined, {
      customerId: 'k1',
    });
  });

  it('names what the API refused', async () => {
    api.change.mockRejectedValue(new SettingsRefused('invalid'));

    expect(await facade.save('c1', { customerId: 'k1' }, [{ key: terms.key, value: 999 }])).toBe(
      false,
    );
    expect(facade.error()).toBe('invalid');
  });
});
