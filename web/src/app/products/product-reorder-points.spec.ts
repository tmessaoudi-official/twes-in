// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { decimalShown } from '../shared/i18n/format';
import { FormatFacade } from '../shared/i18n/format-facade';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { ProductReorderPoints } from './product-reorder-points-facade';
import { ProductReorderPointsSection } from './product-reorder-points';
import type { ProductReorderPointRow } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ products: { reorder: { none: 'Aucun seuil', clear: 'Retirer le seuil' } } });
  }
}

const row = (code: string, quantity: string | null): ProductReorderPointRow => ({
  establishmentId: `e-${code}`,
  establishmentCode: code,
  establishmentName: `Établissement ${code}`,
  quantity,
});

// docs/SPEC.md § 7, 2026-09-24 11:40: a reorder point per product per establishment, empty meaning no alert.
describe('ProductReorderPointsSection', () => {
  const rows = signal<readonly ProductReorderPointRow[]>([]);
  const facade = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    set: vi.fn(),
    clear: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ProductReorderPointsSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(readOnly = false): Promise<void> {
    fixture = TestBed.createComponent(ProductReorderPointsSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('readOnly', readOnly);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function typeInto(code: string, value: string): Promise<void> {
    const field = q(`product-reorder-quantity-${code}`) as HTMLInputElement;
    field.value = value;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    rows.set([row('SIEGE', '12.000'), row('SFAX', null)]);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.set.mockReset().mockResolvedValue(true);
    facade.clear.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [ProductReorderPointsSection],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ProductReorderPoints, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        {
          provide: FormatFacade,
          useValue: {
            locale: signal('fr-FR'),
            decimal: (value: string) => decimalShown(value, 'fr-FR'),
          },
        },
      ],
    });
  });

  it('reads the point of each establishment, showing a kept one as a person writes it', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1', 'p1');
    expect((q('product-reorder-quantity-SIEGE') as HTMLInputElement).value).toBe('12');
    expect((q('product-reorder-quantity-SFAX') as HTMLInputElement).value).toBe('');
    expect(q('product-reorder-clear-SIEGE')).not.toBeNull();
    expect(q('product-reorder-clear-SFAX')).toBeNull();
  });

  it('saves what is typed for one establishment, a comma read as a decimal point', async () => {
    await open();
    expect((q('product-reorder-save-SFAX') as HTMLButtonElement).disabled).toBe(true);

    await typeInto('SFAX', '2,5');
    q('product-reorder-save-SFAX')!.click();
    await fixture.whenStable();

    expect(facade.set).toHaveBeenCalledWith('c1', 'p1', 'e-SFAX', '2.5');
    expect(successToasts()).toContain('products.reorder.saved');
  });

  it('says what is wrong with a quantity the API would refuse, and does not send it', async () => {
    await open();

    await typeInto('SFAX', '-3');

    expect(q('product-reorder-malformed-SFAX')).not.toBeNull();
    expect((q('product-reorder-save-SFAX') as HTMLButtonElement).disabled).toBe(true);
  });

  it('clears the point of one establishment, so there is no alert there', async () => {
    await open();

    q('product-reorder-clear-SIEGE')!.click();
    await fixture.whenStable();

    expect(facade.clear).toHaveBeenCalledWith('c1', 'p1', 'e-SIEGE');
    expect(successToasts()).toContain('products.reorder.cleared');
  });

  it('shows somebody who may not write the points and changes nothing', async () => {
    await open(true);

    expect(q('product-reorder-value-SIEGE')?.textContent?.trim()).toBe('12');
    expect(q('product-reorder-value-SFAX')?.textContent?.trim()).toBe('Aucun seuil');
    expect(q('product-reorder-quantity-SIEGE')).toBeNull();
    expect(q('product-reorder-clear-SIEGE')).toBeNull();
  });
});
