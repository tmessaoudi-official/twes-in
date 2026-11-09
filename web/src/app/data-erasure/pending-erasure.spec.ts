// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { DataErasureApi, ErasureRefused, type Erasure } from './data-erasure-api';
import { PendingErasure } from './pending-erasure';

describe('PendingErasure', () => {
  const pending = vi.fn<(companyId: string) => Promise<Erasure | null>>();
  const shown = { id: 'e1' } as Erasure;

  beforeEach(() => {
    pending.mockReset();
    TestBed.configureTestingModule({
      providers: [{ provide: DataErasureApi, useValue: { pending } }],
    });
  });

  it.each([
    ['the network lost', new ErasureRefused('network')],
    ['an answer the screen has no words for', new ErasureRefused({ unknown: 'brand_new' })],
  ])('keeps the banner it showed when %s', async (_, refused) => {
    const banner = TestBed.inject(PendingErasure);
    banner.shown(shown);
    pending.mockRejectedValue(refused);
    await banner.load('c1');
    expect(banner.erasure()).toBe(shown);
  });

  it('takes the banner down on a refusal it knows, such as the reader not being the owner', async () => {
    const banner = TestBed.inject(PendingErasure);
    banner.shown(shown);
    pending.mockRejectedValue(new ErasureRefused('owner_only'));
    await banner.load('c1');
    expect(banner.erasure()).toBeNull();
  });
});
