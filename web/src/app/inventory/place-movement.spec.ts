// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Feedback } from '../shared/feedback/feedback';
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { InventoryFacade } from './inventory-facade';
import type {
  StockLevelRow,
  StockLocationRow,
  StockMovementInput,
  StockProductOption,
} from './inventory-types';
import { type MovementAsked, PlaceMovement, quantityOf } from './place-movement';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({});
  }
}

const line: StockLevelRow = {
  id: 'p1:r2',
  productId: 'p1',
  productReference: 'CAB-HDMI',
  productName: 'Câble HDMI 2 m',
  unitCode: 'C62',
  unitName: 'Unité',
  unitDecimals: 0,
  locationId: 'r2',
  locationCode: 'R2',
  locationName: 'Accessoires',
  establishmentId: 'e1',
  quantity: '173.000',
  lotId: null,
  lotCode: null,
  lotExpiresOn: null,
  lotReleased: false,
  mainPhotoId: null,
};

function place(id: string, code: string): StockLocationRow {
  return {
    id,
    code,
    name: code,
    kind: 'rack',
    establishmentId: 'e1',
    parentId: null,
    isDefault: id === 'r1',
    childCount: 0,
    movementCount: 0,
  };
}

const product: StockProductOption = {
  id: 'p1',
  reference: 'CAB-HDMI',
  name: 'Câble HDMI 2 m',
  unitCode: 'C62',
  unitDecimals: 0,
  homeLocationId: null,
  tracking: 'none',
};

@Component({
  imports: [PlaceMovement],
  template: `<app-place-movement
    companyId="c1"
    [asked]="asked()"
    [destination]="destination()"
    (heading)="headed.push($event)"
    (closed)="closed = closed + 1"
  />`,
})
class Host {
  readonly asked = signal<MovementAsked>({ operation: 'move', line });
  readonly destination = signal<string | null>(null);
  headed: (string | null)[] = [];
  closed = 0;
}

describe('PlaceMovement', () => {
  const facade = {
    locations: signal([
      place('r1', 'R1'),
      place('r2', 'R2'),
      place('r2-b1', 'R2-B1'),
      place('r3', 'R3'),
    ]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal<string | null>(null).asReadonly(),
    clearError: vi.fn(),
    pickProducts: vi.fn(),
    record: vi.fn(),
    reloadContents: vi.fn(),
  };
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
  const recorded = (): StockMovementInput[] =>
    facade.record.mock.calls.map((call) => call[1] as StockMovementInput);
  const said = (): RecordedFeedback['said'] => (TestBed.inject(Feedback) as RecordedFeedback).said;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  async function open(asked: MovementAsked = { operation: 'move', line }): Promise<void> {
    fixture = TestBed.createComponent(Host);
    fixture.componentInstance.asked.set(asked);
    await settle();
  }

  function input(id: string): HTMLInputElement {
    return q('place-movement-form')?.querySelector(
      `input[data-testid="field-${id}"]`,
    ) as HTMLInputElement;
  }

  async function submit(): Promise<void> {
    (q('place-movement-save') as HTMLButtonElement).click();
    await settle();
  }

  beforeEach(() => {
    facade.clearError.mockReset();
    facade.pickProducts.mockReset().mockResolvedValue([product]);
    facade.record.mockReset().mockResolvedValue(true);
    facade.reloadContents.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: InventoryFacade, useValue: facade },
        {
          provide: AuthFacade,
          useValue: {
            me: () => ({ user: { id: 'u1' }, company: { id: 'c1' } }),
            hasPermission: () => true,
            hasModule: () => true,
          },
        },
        { provide: Session, useExisting: AuthFacade },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
  });

  it('reads a quantity as its unit counts it, and offers nothing to move from a line holding none', () => {
    expect(quantityOf('173.000', 0)).toBe('173');
    expect(quantityOf('2.500', 3)).toBe('2.500');
    expect(quantityOf('2.500', 1)).toBe('2.5');
    expect(quantityOf('-4.000', 0)).toBe('');
    expect(quantityOf('0.000', 2)).toBe('');
  });

  /** The product is asked by id, so a tracked one's form asks its lot, as the Stock page's does. */
  it('asks the product of the line by id before drawing its form', async () => {
    await open();

    expect(facade.pickProducts).toHaveBeenCalledWith('c1', { ids: ['p1'] });
    expect(q('place-movement-form')).not.toBeNull();
  });

  /** The goods are where the line says, which may be a bin under the place chosen, never the place itself. */
  it('moves the whole of what the line holds from where the line is, to the place touched on the plan', async () => {
    await open({
      operation: 'move',
      line: { ...line, locationId: 'r2-b1', locationCode: 'R2-B1' },
    });
    fixture.componentInstance.destination.set('r3');
    await settle();
    await submit();

    expect(recorded()).toEqual([
      {
        operation: 'move',
        productId: 'p1',
        locationId: 'r2-b1',
        toLocationId: 'r3',
        quantity: '173',
      },
    ]);
  });

  it('says what moved where, offers to put it back with a counter-movement, and reads the place again', async () => {
    await open();
    fixture.componentInstance.destination.set('r3');
    await settle();
    await submit();

    expect(facade.reloadContents).toHaveBeenCalledWith('c1');
    expect(fixture.componentInstance.closed).toBe(1);
    const toast = said().at(-1);
    expect(toast?.key).toBe('inventory.plan.move.moved');
    expect(toast?.params).toEqual({
      quantity: '173',
      product: 'Câble HDMI 2 m',
      from: 'R2',
      to: 'R3',
    });
    expect(toast?.action?.key).toBe('inventory.plan.move.undo');

    toast?.action?.run();
    await settle();
    expect(recorded().at(-1)).toEqual({
      operation: 'move',
      productId: 'p1',
      locationId: 'r3',
      toLocationId: 'r2',
      quantity: '173',
    });
    expect(said().at(-1)?.key).toBe('inventory.plan.move.undone');
  });

  /** A counter-movement refused — the goods moved on meanwhile — is said, never lost in a toast's click. */
  it('says so when putting it back is refused', async () => {
    await open();
    fixture.componentInstance.destination.set('r3');
    await settle();
    await submit();
    facade.record.mockResolvedValue(false);

    said().at(-1)?.action?.run();
    await settle();

    expect(said().at(-1)).toMatchObject({
      kind: 'failure',
      key: 'inventory.plan.move.undo_failed',
    });
  });

  /** A lot moves as itself: the counter-movement names it too. */
  it('names the lot of a tracked line, both ways', async () => {
    facade.pickProducts.mockResolvedValue([{ ...product, tracking: 'lot' }]);
    await open({ operation: 'move', line: { ...line, lotId: 'l1', lotCode: 'L-0925' } });
    fixture.componentInstance.destination.set('r3');
    await settle();
    await submit();

    expect(recorded()[0]?.lotCode).toBe('L-0925');
    said().at(-1)?.action?.run();
    await settle();
    expect(recorded().at(-1)?.lotCode).toBe('L-0925');
  });

  it('tells the plan where the goods are heading as the destination is chosen in the form', async () => {
    await open();
    fixture.componentInstance.destination.set('r3');
    await settle();

    expect(fixture.componentInstance.headed.at(-1)).toBe('r3');
  });

  /** A count is what was found, typed by the person: the quantity on record is not proposed as the answer. */
  it('counts the line where it is, with the quantity left to type', async () => {
    await open({ operation: 'count', line });
    expect(input('quantity').value).toBe('');
    input('quantity').value = '170';
    input('quantity').dispatchEvent(new Event('input'));
    await submit();

    expect(recorded()).toEqual([
      expect.objectContaining({
        operation: 'count',
        productId: 'p1',
        locationId: 'r2',
        quantity: '170',
      }),
    ]);
    expect(said().at(-1)?.key).toBe('inventory.stock.recorded');
    expect(fixture.componentInstance.closed).toBe(1);
  });

  it('receives the product where the line is', async () => {
    await open({ operation: 'receive', line });
    input('quantity').value = '12';
    input('quantity').dispatchEvent(new Event('input'));
    await submit();

    expect(recorded()).toEqual([
      expect.objectContaining({
        operation: 'receive',
        productId: 'p1',
        locationId: 'r2',
        quantity: '12',
      }),
    ]);
  });

  it('goes back to what the place holds without recording anything', async () => {
    await open();
    (q('place-movement-back') as HTMLButtonElement).click();
    await settle();

    expect(fixture.componentInstance.closed).toBe(1);
    expect(facade.record).not.toHaveBeenCalled();
  });

  it('stays open, and reads nothing again, when the API refuses the movement', async () => {
    facade.record.mockResolvedValue(false);
    await open();
    fixture.componentInstance.destination.set('r3');
    await settle();
    await submit();

    expect(fixture.componentInstance.closed).toBe(0);
    expect(facade.reloadContents).not.toHaveBeenCalled();
    expect(q('place-movement-form')).not.toBeNull();
  });
});
