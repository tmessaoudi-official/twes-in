// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  type TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { ThemeFacade } from '../shared/theme/theme-facade';
import type { StockDrawingRow } from './inventory-types';
import { StockMapVolume } from './stock-map-volume';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      inventory: {
        plan: {
          volume: {
            summary: '{{floor}} : {{racks}} rayonnage(s), {{zones}} zone(s)',
            lit: '{{count}} allumé(s)',
            missing: 'Pas de WebGL 2.',
          },
        },
      },
    });
  }
}

const rack: StockDrawingRow = {
  id: 'd1',
  floorId: 'f1',
  locationId: 'l1',
  locationCode: 'R1',
  locationName: 'Rayonnage 1',
  locationKind: 'rack',
  x: '1.000',
  y: '1.000',
  width: '6.000',
  depth: '1.000',
  rotation: 0,
  height: '2.400',
};

/**
 * jsdom draws nothing, so what is tested here is what a person reads and presses around the picture; the picture's
 * geometry is `stock-map-volume-scene.spec.ts`'s, and the e2e looks at it in a real browser.
 */
describe('StockMapVolume', () => {
  let fixture: ComponentFixture<StockMapVolume>;
  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  beforeEach(async () => {
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(null);
    TestBed.configureTestingModule({
      imports: [StockMapVolume],
      providers: [
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        {
          provide: ThemeFacade,
          useValue: { scheme: signal('light'), accent: signal('#1f6feb') },
        },
      ],
    });
    fixture = TestBed.createComponent(StockMapVolume);
    fixture.componentRef.setInput('floorName', 'Rez-de-chaussée');
    fixture.componentRef.setInput('size', { width: 20, depth: 10 });
    fixture.componentRef.setInput('drawings', [
      rack,
      { ...rack, id: 'd2', locationId: 'l2', locationKind: 'zone', height: '0.000' },
    ]);
    fixture.componentRef.setInput('structures', []);
    fixture.componentRef.setInput('lit', new Set(['l2']));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  afterEach(() => vi.restoreAllMocks());

  it('says a browser without WebGL 2 cannot show it, and keeps saying what the floor holds', () => {
    expect(q('stock-volume-missing')?.textContent).toContain('Pas de WebGL 2.');
    expect(q('stock-volume-canvas')?.classList).toContain('hidden');
    expect(q('stock-volume-summary')?.textContent).toContain(
      'Rez-de-chaussée : 1 rayonnage(s), 1 zone(s)',
    );
    expect(q('stock-volume-summary')?.textContent).toContain('1 allumé(s)');
    // Nothing to press on a picture that is not there.
    expect(q('stock-volume-turn-left')).toBeNull();
  });

  it('shows the building, the ground and the heights until each is turned off', () => {
    for (const name of ['building', 'ground', 'heights']) {
      expect(q(`stock-volume-toggle-${name}`)?.getAttribute('aria-pressed')).toBe('true');
    }

    q('stock-volume-toggle-heights')?.click();
    fixture.detectChanges();

    expect(q('stock-volume-toggle-heights')?.getAttribute('aria-pressed')).toBe('false');
    expect(q('stock-volume-toggle-building')?.getAttribute('aria-pressed')).toBe('true');
  });
});
