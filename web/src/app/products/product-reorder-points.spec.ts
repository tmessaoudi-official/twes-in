// SPDX-License-Identifier: AGPL-3.0-or-later

import { UnsavedChanges } from '../shared/form/unsaved-changes';
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
import { provideQuietFeedback, RecordedFeedback, successToasts } from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';
import { announceSaved } from '../shared/testing/live';
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
  };
  const auth = { me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }) };
  let fixture: ComponentFixture<ProductReorderPointsSection>;
  const moved: string[] = [];

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(readOnly = false): Promise<void> {
    fixture = TestBed.createComponent(ProductReorderPointsSection);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('version', 7);
    fixture.componentRef.setInput('readOnly', readOnly);
    moved.length = 0;
    fixture.componentInstance.moved.subscribe((how) => moved.push(how));
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
    facade.set.mockReset().mockResolvedValue('saved');
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
    // A point typed and not saved holds the page until it is.
    expect(TestBed.inject(UnsavedChanges).count()).toBe(1);
    q('product-reorder-save-SFAX')!.click();
    await fixture.whenStable();
    expect(TestBed.inject(UnsavedChanges).count()).toBe(0);

    expect(facade.set).toHaveBeenCalledWith('c1', 'p1', 'e-SFAX', '2.5', 7);
    expect(successToasts()).toContain('products.reorder.saved');
    expect(moved).toEqual(['saved']);
  });

  it('tells the page a point read before the product was saved elsewhere, keeping what was typed', async () => {
    facade.set.mockResolvedValue('changed');
    await open();
    await typeInto('SFAX', '3');

    q('product-reorder-save-SFAX')!.click();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(moved).toEqual(['changed']);
    expect(successToasts()).not.toContain('products.reorder.saved');
    expect((q('product-reorder-quantity-SFAX') as HTMLInputElement).value).toBe('3');
  });

  it('tells the page nothing when a point is refused', async () => {
    facade.set.mockResolvedValue('refused');
    await open();
    await typeInto('SFAX', '3');

    q('product-reorder-save-SFAX')!.click();
    await fixture.whenStable();

    expect(moved).toEqual([]);
  });

  it('reads the points again when another person changed them', async () => {
    await open();
    expect(facade.load).toHaveBeenCalledTimes(1);

    await announceSaved('product_reorder_point', 'p1', undefined, 'product_reorder_point.set');

    expect(facade.load).toHaveBeenCalledTimes(2);
  });

  it('takes a point saved by someone else where nothing was typed', async () => {
    await open();

    rows.set([row('SIEGE', '20.000'), row('SFAX', null)]);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect((q('product-reorder-quantity-SIEGE') as HTMLInputElement).value).toBe('20');
    expect(q('field-conflict-reorder-SIEGE')).toBeNull();
  });

  // What is being typed is kept, and the conflict says so.
  it('keeps a point typed over one saved since by someone else, and offers theirs', async () => {
    await open();
    await typeInto('SIEGE', '15');

    rows.set([row('SIEGE', '20.000'), row('SFAX', null)]);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect((q('product-reorder-quantity-SIEGE') as HTMLInputElement).value).toBe('15');
    expect(q('field-conflict-reorder-SIEGE')).not.toBeNull();
    expect(q('field-conflict-reorder-SFAX')).toBeNull();

    (q('field-take-theirs-reorder-SIEGE') as HTMLButtonElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect((q('product-reorder-quantity-SIEGE') as HTMLInputElement).value).toBe('20');
    expect(q('field-conflict-reorder-SIEGE')).toBeNull();
  });

  it('keeps the typed point over theirs when asked, still to be saved', async () => {
    await open();
    await typeInto('SIEGE', '15');
    rows.set([row('SIEGE', '20.000'), row('SFAX', null)]);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    (q('field-keep-mine-reorder-SIEGE') as HTMLButtonElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();

    expect(q('field-conflict-reorder-SIEGE')).toBeNull();
    expect((q('product-reorder-quantity-SIEGE') as HTMLInputElement).value).toBe('15');
    q('product-reorder-save-SIEGE')!.click();
    await fixture.whenStable();
    expect(facade.set).toHaveBeenCalledWith('c1', 'p1', 'e-SIEGE', '15', 7);
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

    expect(facade.set).toHaveBeenCalledWith('c1', 'p1', 'e-SIEGE', null, 7);
    expect(successToasts()).toContain('products.reorder.cleared');
    expect(moved).toEqual(['saved']);

    // Undo first: « Annuler » sets the point it held again, on the version the product page holds then.
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    expect(said[0].action?.key).toBe('products.reorder.undo');
    fixture.componentRef.setInput('version', 8);
    said[0].action!.run();
    await fixture.whenStable();
    expect(facade.set).toHaveBeenLastCalledWith('c1', 'p1', 'e-SIEGE', '12.000', 8);
  });

  it('shows somebody who may not write the points and changes nothing', async () => {
    await open(true);

    expect(q('product-reorder-value-SIEGE')?.textContent?.trim()).toBe('12');
    expect(q('product-reorder-value-SFAX')?.textContent?.trim()).toBe('Aucun seuil');
    expect(q('product-reorder-quantity-SIEGE')).toBeNull();
    expect(q('product-reorder-clear-SIEGE')).toBeNull();
  });
});
