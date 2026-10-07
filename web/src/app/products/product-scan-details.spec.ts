// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { provideTranslateService, provideTranslateLoader } from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { InventoryApi } from '../inventory/inventory-api';
import type { StockLevelRow } from '../inventory/inventory-types';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ProductScanDetails } from './product-scan-details';
import { ProductScans } from './product-scans';
import type { ProductScan } from './products-types';

const scan = (extra: Partial<ProductScan> = {}): ProductScan => ({
  productId: 'p-1',
  reference: 'NUT-400',
  name: 'Nutella',
  isActive: true,
  code: '3017620422003',
  role: 'unit',
  quantity: 1,
  lot: null,
  useBy: null,
  serial: null,
  unitPriceNet: '10.000',
  unitPriceGross: '11.900',
  priceGross: '11.900',
  ...extra,
});

const level = (extra: Partial<StockLevelRow>): StockLevelRow => ({
  id: 'x',
  productId: 'p-1',
  productReference: 'NUT-400',
  productName: 'Nutella',
  unitCode: 'C62',
  unitName: 'Unité',
  unitDecimals: 0,
  locationId: 'l-1',
  locationCode: 'SHOP',
  locationName: 'Shop',
  establishmentId: 'e-1',
  quantity: '10.000',
  lotId: null,
  lotCode: null,
  lotExpiresOn: null,
  lotReleased: false,
  ...extra,
});

describe('ProductScanDetails', () => {
  const scans = { named: vi.fn() };
  const inventory = { levels: vi.fn() };

  function details(): ProductScanDetails {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => ({
            getTranslation: () =>
              of({
                scan: {
                  phone: {
                    details: {
                      pack: 'A pack of {{count}}',
                      use_by: 'Use by {{date}}',
                      stock: 'In stock: {{count}}',
                      none: 'Out of stock',
                      at: '{{place}}: {{count}}',
                      nearest: 'Nearest use-by {{date}}',
                      expired: 'Nearest use-by {{date}}, past',
                    },
                  },
                },
              }),
          })),
        }),
        { provide: ProductScans, useValue: scans },
        { provide: InventoryApi, useValue: inventory },
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'c-1' } }) } },
        {
          provide: FormatFacade,
          useValue: { day: (v: string) => `d:${v}`, decimal: (v: string) => v },
        },
      ],
    });
    return TestBed.inject(ProductScanDetails);
  }

  beforeEach(() => {
    scans.named.mockReset().mockResolvedValue(scan());
    inventory.levels.mockReset().mockResolvedValue({ rows: [], total: 0 });
  });

  it('says what a pack holds and the use-by date a GS1 code carried', async () => {
    scans.named.mockResolvedValue(scan({ quantity: 6, useBy: '2026-12-01' }));

    const lines = await details().of('x');

    expect(lines).toContain('A pack of 6');
    expect(lines).toContain('Use by d:2026-12-01');
  });

  it('says what is in stock and where, leaving out the places with none', async () => {
    inventory.levels.mockResolvedValue({
      rows: [
        level({ quantity: '14.000', locationName: 'Shop' }),
        level({ id: 'y', locationId: 'l-2', locationName: 'Warehouse', quantity: '30.000' }),
        level({ id: 'z', locationId: 'l-3', locationName: 'Empty', quantity: '0.000' }),
        level({ id: 'w', productId: 'p-2', quantity: '99.000' }),
      ],
      total: 4,
    });

    const lines = await details().of('x');

    expect(lines).toContain('In stock: 44');
    expect(lines).toContain('Shop: 14');
    expect(lines).toContain('Warehouse: 30');
    expect(lines.join('|')).not.toContain('Empty');
  });

  it('marks the nearest use-by of what is in stock, and a past one as such', async () => {
    inventory.levels.mockResolvedValue({
      rows: [
        level({ lotExpiresOn: '2026-12-01', quantity: '5.000' }),
        level({ id: 'y', lotExpiresOn: '2026-09-01', quantity: '2.000' }),
        level({ id: 'z', lotExpiresOn: '2026-01-01', quantity: '0.000' }),
      ],
      total: 3,
    });
    vi.setSystemTime(new Date('2026-10-02T10:00:00Z'));

    const lines = await details().of('x');

    expect(lines).toContain('Nearest use-by d:2026-09-01, past');
    vi.useRealTimers();
  });

  it('says out of stock when nothing is held, and nothing at all for a code no product answers to', async () => {
    expect(await details().of('x')).toContain('Out of stock');

    scans.named.mockResolvedValue(null);
    expect(await details().of('x')).toEqual([]);
  });
});
