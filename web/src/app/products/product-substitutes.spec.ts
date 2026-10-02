// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { ProductSubstitutes } from './product-substitutes-facade';
import { ProductSubstitutesSection } from './product-substitutes';
import type { ProductSubstituteRow } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      products: {
        substitutes: {
          none: 'Aucun',
          ungrouped: 'Pas de groupe',
          on_hand: '{{quantity}} en stock',
        },
      },
    });
  }
}

const row = (reference: string, onHand: string | null): ProductSubstituteRow => ({
  id: `id-${reference}`,
  reference,
  name: `Produit ${reference}`,
  isActive: true,
  unitPriceNet: '10.0000',
  onHand,
});

// docs/SPEC.md § 7: the other products of a substitution group, each with its stock when it may be read.
describe('ProductSubstitutesSection', () => {
  const rows = signal<readonly ProductSubstituteRow[]>([]);
  const facade = {
    rows: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ProductSubstitutesSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(group: string | null): Promise<void> {
    fixture = TestBed.createComponent(ProductSubstitutesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('group', group);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    rows.set([row('ART-002', '7.000'), row('ART-003', null)]);
    facade.load.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [ProductSubstitutesSection],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        { provide: ProductSubstitutes, useValue: facade },
        { provide: AuthFacade, useValue: auth },
      ],
    });
  });

  it('lists each substitute with its stock, and no quantity where stock cannot be read', async () => {
    await open('Portables');

    expect(facade.load).toHaveBeenCalledWith('c1', 'p1');
    expect(q('product-substitute-ART-002')?.textContent).toContain('Produit ART-002');
    expect(q('product-substitute-stock-ART-002')?.textContent?.trim()).toBe('7 en stock');
    expect(q('product-substitute-ART-003')).not.toBeNull();
    expect(q('product-substitute-stock-ART-003')).toBeNull();
  });

  it('says so when the group has no other active product', async () => {
    rows.set([]);
    await open('Portables');

    expect(q('product-substitutes-none')).not.toBeNull();
  });

  it('asks for a group instead of listing when the product has none', async () => {
    await open(null);

    expect(q('product-substitutes-ungrouped')).not.toBeNull();
    expect(q('product-substitutes-list')).toBeNull();
  });
});
