// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CustomerView } from '../shared/customer-view/customer-view';
import { FormatFacade } from '../shared/i18n/format-facade';
import { ScanBus } from '../shared/scan/scan-bus';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';
import { PriceCheckPage } from './price-check-page';
import { ProductsApi } from './products-api';
import type { ProductScan } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const pack: ProductScan = {
  productId: 'p1',
  reference: 'VIS-6X40',
  name: 'Vis 6x40',
  isActive: true,
  code: '13017620422000',
  role: 'pack',
  quantity: 12,
  lot: 'L7',
  useBy: '2027-05-31',
  serial: null,
  unitPriceNet: '100.0000',
  unitPriceGross: '120.190',
  priceGross: '1442.280',
};

describe('PriceCheckPage', () => {
  const api = { scan: vi.fn() };
  const active = signal(false);
  const customerView = {
    active: active.asReadonly(),
    on: vi.fn(() => active.set(true)),
    off: vi.fn(() => active.set(false)),
  };
  let fixture: ComponentFixture<PriceCheckPage>;
  let bus: ScanBus;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(): Promise<void> {
    fixture = TestBed.createComponent(PriceCheckPage);
    await settle();
  }

  beforeEach(() => {
    api.scan.mockReset();
    active.set(false);
    customerView.on.mockClear();
    customerView.off.mockClear();
    TestBed.configureTestingModule({
      imports: [PriceCheckPage],
      providers: [
        provideRouter([]),
        provideQuietFeedback(),
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: ProductsApi, useValue: api },
        { provide: CustomerView, useValue: customerView },
        {
          provide: Session,
          useValue: { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', currency: 'TND' } }) },
        },
        {
          provide: FormatFacade,
          useValue: { amount: (value: string) => `~${value}`, day: (value: string) => value },
        },
      ],
    });
    bus = TestBed.inject(ScanBus);
  });

  afterEach(() => fixture?.destroy());

  it('turns customer view on while it is open, and puts it back as it was on leaving', async () => {
    await open();
    expect(active()).toBe(true);

    fixture.destroy();
    expect(active()).toBe(false);

    active.set(true);
    await open();
    fixture.destroy();
    expect(active()).toBe(true);
  });

  it('shows what a scan names and what a customer pays for it, taxes included, and for the pack it enters', async () => {
    api.scan.mockResolvedValue(pack);
    await open();

    const outcome = await bus.receive(pack.code, 'wedge');
    await settle();

    expect(api.scan).toHaveBeenCalledWith('c1', pack.code);
    expect(q('price-check-name')?.textContent).toContain('Vis 6x40');
    expect(q('price-check-price')?.textContent).toContain('~120.190 TND');
    expect(q('price-check-pack')?.textContent).toContain('~1442.280 TND');
    expect(q('price-check-use-by')).not.toBeNull();
    expect(q('price-check-cost')).toBeNull();
    expect(outcome).toEqual(
      expect.objectContaining({
        kind: 'done',
        product: { name: 'Vis 6x40', unitPrice: '120.190' },
      }),
    );
  });

  it('says a code no product answers to is unknown, and offers nothing else', async () => {
    api.scan.mockResolvedValue(null);
    await open();

    const outcome = await bus.receive('NOPE-1', 'wedge');
    await settle();

    expect(outcome.kind).toBe('refused');
    expect(q('price-check-unknown')).not.toBeNull();
    expect(q('price-check-name')).toBeNull();
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    expect(said).toContainEqual(
      expect.objectContaining({ kind: 'failure', key: 'price_check.unknown' }),
    );
  });

  it('checks a code typed by hand, as a scan', async () => {
    api.scan.mockResolvedValue({ ...pack, role: 'unit', quantity: 1, priceGross: '120.190' });
    await open();

    const field = q('price-check-code') as HTMLInputElement;
    field.value = '3017620422003';
    field.dispatchEvent(new Event('input'));
    (q('price-check-form') as HTMLFormElement).dispatchEvent(new Event('submit'));
    await vi.waitFor(() => expect(api.scan).toHaveBeenCalledWith('c1', '3017620422003'));
    await settle();

    expect(q('price-check-name')?.textContent).toContain('Vis 6x40');
    expect(q('price-check-pack')).toBeNull();
  });
});
