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
import type { StockLocationRow } from '../inventory/inventory-types';
import { Session } from '../shared/session/session';
import { provideQuietFeedback, successToasts } from '../shared/testing/feedback';
import { ProductHomes } from './product-homes-facade';
import { ProductHomesSection } from './product-homes';
import type { ProductHomeRow } from './products-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({ products: { homes: { set: 'Définir', clear: 'Retirer' } } });
  }
}

const location = (id: string, code: string, extra: Partial<StockLocationRow> = {}) =>
  ({
    id,
    establishmentId: 'e1',
    parentId: null,
    kind: 'zone',
    code,
    name: `Zone ${code}`,
    isDefault: false,
    childCount: 0,
    movementCount: 0,
    ...extra,
  }) as StockLocationRow;

const home: ProductHomeRow = {
  id: 'h1',
  establishmentId: 'e1',
  establishmentCode: 'SIEGE',
  establishmentName: 'Siège',
  locationId: 'l1',
  locationCode: 'A-12',
  locationName: 'Zone A-12',
};

describe('ProductHomesSection', () => {
  const homes = signal<readonly ProductHomeRow[]>([]);
  const locations = signal<readonly StockLocationRow[]>([location('l1', 'A-12')]);
  const facade = {
    homes: homes.asReadonly(),
    locations: locations.asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    load: vi.fn(),
    set: vi.fn(),
    clear: vi.fn(),
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ProductHomesSection>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(readOnly = false): Promise<void> {
    fixture = TestBed.createComponent(ProductHomesSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('readOnly', readOnly);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    homes.set([]);
    locations.set([location('l1', 'A-12')]);
    facade.load.mockReset().mockResolvedValue(undefined);
    facade.set.mockReset().mockResolvedValue(true);
    facade.clear.mockReset().mockResolvedValue(true);
    TestBed.configureTestingModule({
      imports: [ProductHomesSection],
      providers: [
        ...provideQuietFeedback(),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: ProductHomes, useValue: facade },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
      ],
    });
  });

  it('reads this product’s homes and the locations it may be given', async () => {
    await open();

    expect(facade.load).toHaveBeenCalledWith('c1', 'p1');
  });

  it('says so plainly while the product lives nowhere in particular', async () => {
    await open();

    expect(q('product-homes-none')).not.toBeNull();
    expect(q('product-homes-list')).toBeNull();
  });

  it('shows each home by its establishment and the location a person reads', async () => {
    homes.set([home]);

    await open();

    expect(q('product-homes-list')?.textContent).toContain('Siège');
    expect(q('product-homes-list')?.textContent).toContain('A-12');
    expect(q('product-homes-none')).toBeNull();
  });

  it('gives the product the chosen home and says it was saved', async () => {
    await open();
    fixture.componentInstance['chosen'].set('l1');
    fixture.detectChanges();

    q('product-home-set')!.click();
    await fixture.whenStable();

    expect(facade.set).toHaveBeenCalledWith('c1', 'p1', 'l1');
    expect(successToasts()).toContain('products.homes.saved');
  });

  /** Nothing chosen is not a home at the empty string: the API would answer 422 on a claim nobody made. */
  it('does not save while no location is chosen', async () => {
    await open();

    expect((q('product-home-set') as HTMLButtonElement).disabled).toBe(true);
  });

  it('clears the home of one establishment, naming that establishment', async () => {
    homes.set([home]);
    await open();

    q('product-home-clear-e1')!.click();
    await fixture.whenStable();

    expect(facade.clear).toHaveBeenCalledWith('c1', 'p1', 'e1');
    expect(successToasts()).toContain('products.homes.cleared');
  });

  /** A home is set with product.write; somebody who may only read one sees where it lives and changes nothing. */
  it('offers neither the picker nor a clear to somebody who may not write', async () => {
    homes.set([home]);

    await open(true);

    expect(q('product-homes-list')).not.toBeNull();
    expect(q('product-home-set')).toBeNull();
    expect(q('product-home-clear-e1')).toBeNull();
  });
});
