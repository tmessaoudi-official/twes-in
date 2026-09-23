// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AuthFacade } from '../auth/auth-facade';
import { InventoryFacade } from './inventory-facade';
import type { StockLocationRow } from './inventory-types';
import { LocationLabelsPage } from './location-labels-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const site: StockLocationRow = {
  id: '0192a0b1-0000-7000-8000-000000000001',
  establishmentId: 'e1',
  parentId: null,
  kind: 'site',
  code: '000',
  name: 'Siège',
  isDefault: true,
  childCount: 1,
  movementCount: 0,
};
const rack: StockLocationRow = {
  ...site,
  id: '0192a0b1-0000-7000-8000-000000000002',
  parentId: site.id,
  kind: 'rack',
  code: 'R1',
  name: 'Rayonnage 1',
  isDefault: false,
  childCount: 0,
};

describe('LocationLabelsPage', () => {
  const facade = {
    locations: signal<readonly StockLocationRow[]>([site, rack]).asReadonly(),
    loadStockContext: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  const print = vi.fn();
  let fixture: ComponentFixture<LocationLabelsPage>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(async () => {
    facade.loadStockContext.mockReset().mockResolvedValue(undefined);
    print.mockReset();
    TestBed.configureTestingModule({
      imports: [LocationLabelsPage],
      providers: [
        provideTranslateService(),
        provideTranslateLoader(StaticLoader),
        { provide: InventoryFacade, useValue: facade },
        { provide: AuthFacade, useValue: auth },
      ],
    });
    const view = TestBed.inject(DOCUMENT).defaultView!;
    vi.spyOn(view, 'print').mockImplementation(print);
    fixture = TestBed.createComponent(LocationLabelsPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  it('prints one label per location: its code, its path, and a QR code of its own address', () => {
    expect(facade.loadStockContext).toHaveBeenCalledWith('c1');
    const label = q(`location-label-${rack.code}`);
    expect(label?.textContent).toContain('R1');
    expect(label?.textContent).toContain('000 › R1 — Rayonnage 1');
    const qr = label?.querySelector('svg[role="img"]');
    expect(qr).not.toBeNull();
    expect(q(`location-label-${site.code}`)).not.toBeNull();

    // The address is this app's own, the one a phone's camera opens and count mode recognises.
    const origin = TestBed.inject(DOCUMENT).location.origin;
    expect(
      (fixture.componentInstance as unknown as { address(id: string): string }).address(rack.id),
    ).toBe(`${origin}/stock/locations/${rack.id}`);
  });

  it('prints on asking, with the button left off the paper', () => {
    const button = q('location-labels-print');
    expect(button?.classList.contains('print:hidden')).toBe(true);
    button!.click();
    expect(print).toHaveBeenCalled();
  });
});
