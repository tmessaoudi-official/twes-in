// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { MAT_DIALOG_DATA, MatDialog, MatDialogRef } from '@angular/material/dialog';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { FieldRefusal } from '../shared/form/field-refusals';
import { RefusalFigures } from '../shared/form/refusal-figures';
import { TextsParts } from '../shared/i18n/texts-part';
import { ShortSaleDialog, type ShortSaleDialogData, ShortSales } from './short-sale-dialog';
import { shortSaleIn } from './short-sales';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      stock_moves: {
        short: {
          title_confirm: 'Vendre au-delà du stock enregistré ?',
          title_refused: 'Stock insuffisant',
          intro_confirm: 'Cette vente prend plus que le stock enregistré.',
          intro_refused: 'Comptez le stock ou recevez la marchandise.',
          line: '{{name}} ({{product}}) : il en faut {{wanted}} {{unit}}, {{on_hand}} {{unit}} en stock',
          count: 'Compter',
          receive: 'Recevoir',
          ask_writer: 'Une personne qui gère le stock peut le compter.',
          confirm: 'Vendre quand même',
          keep: 'Garder le brouillon',
          close: 'Fermer',
        },
      },
      actions: { kind: { corrigeable: 'Corrigeable' }, kind_help: { corrigeable: 'Se corrige.' } },
    });
  }
}

const short = (reason: string, index: number, product: string): FieldRefusal => ({
  field: `lines[${index}].quantity`,
  reason,
  params: {
    product,
    name: `Vis ${product}`,
    wanted: '3.000',
    on_hand: '1.000',
    unit: 'pièce',
    product_id: `p-${product}`,
    location_id: 'l1',
  },
});

describe('shortSaleIn', () => {
  it('reads the lines a sale beyond the stock names, and whether it may be confirmed', () => {
    const sale = shortSaleIn([
      { field: 'customerId', reason: 'customer_inactive', params: {} },
      short('stock_short_to_confirm', 0, 'V6'),
      short('stock_short_to_confirm', 2, 'V8'),
    ]);

    expect(sale?.confirmable).toBe(true);
    expect(sale?.lines.map((line) => line.productId)).toEqual(['p-V6', 'p-V8']);
    expect(sale?.lines[0]).toEqual({
      productId: 'p-V6',
      locationId: 'l1',
      params: {
        product: 'V6',
        name: 'Vis V6',
        wanted: '3.000',
        on_hand: '1.000',
        unit: 'pièce',
      },
    });
  });

  it('is refused, not to confirm, as soon as one line is refused', () => {
    const sale = shortSaleIn([
      short('stock_short_to_confirm', 0, 'V6'),
      short('stock_short', 1, 'V8'),
    ]);

    expect(sale?.confirmable).toBe(false);
  });

  it('is nothing where no line is short', () => {
    expect(
      shortSaleIn([{ field: 'lines[0].quantity', reason: 'positive', params: {} }]),
    ).toBeNull();
    expect(shortSaleIn([])).toBeNull();
  });

  it('names a product once, however many lines refuse it', () => {
    const sale = shortSaleIn([short('stock_short', 0, 'V6'), short('stock_short', 3, 'V6')]);

    expect(sale?.lines).toHaveLength(1);
  });
});

describe('ShortSaleDialog', () => {
  const closed: unknown[] = [];

  function open(data: ShortSaleDialogData): HTMLElement {
    TestBed.configureTestingModule({
      imports: [ShortSaleDialog],
      providers: [
        provideRouter([]),
        provideTranslateService({ lang: 'fr', loader: provideTranslateLoader(StaticLoader) }),
        { provide: MAT_DIALOG_DATA, useValue: data },
        { provide: MatDialogRef, useValue: { close: (value: unknown) => closed.push(value) } },
      ],
    });
    const fixture = TestBed.createComponent(ShortSaleDialog);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const sale = shortSaleIn([short('stock_short_to_confirm', 0, 'V6')]);
  const q = (host: HTMLElement, testId: string): HTMLElement | null =>
    host.querySelector(`[data-testid="${testId}"]`);

  beforeEach(() => (closed.length = 0));

  it('names each short line, and confirms the sale only on its own button', () => {
    const host = open({ lines: sale?.lines ?? [], confirmable: true, mayWrite: true });

    expect(q(host, 'short-sale-title')?.textContent).toContain('Vendre au-delà');
    expect(q(host, 'short-sale-line')?.textContent).toContain(
      'Vis V6 (V6) : il en faut 3.000 pièce, 1.000 pièce en stock',
    );
    expect(q(host, 'short-sale-kind')?.getAttribute('data-kind')).toBe('corrigeable');
    q(host, 'short-sale-keep')?.click();
    q(host, 'short-sale-confirm')?.click();
    expect(closed).toEqual([false, true]);
  });

  it('offers a count and a receipt of the product at its place to whoever keeps the stock', () => {
    const host = open({ lines: sale?.lines ?? [], confirmable: false, mayWrite: true });

    expect(q(host, 'short-sale-title')?.textContent).toContain('Stock insuffisant');
    expect(q(host, 'short-sale-confirm')).toBeNull();
    expect(q(host, 'short-sale-count')?.getAttribute('href')).toBe(
      '/stock/locations/l1?product=p-V6',
    );
    expect(q(host, 'short-sale-receive')?.getAttribute('href')).toBe(
      '/stock?operation=receive&product=p-V6&location=l1',
    );
    expect(q(host, 'short-sale-ask')).toBeNull();
    q(host, 'short-sale-close')?.click();
    expect(closed).toEqual([false]);
    // Following either link leaves the dialog with the page, never open over the count sheet or the receipt.
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    q(host, 'short-sale-count')?.click();
    q(host, 'short-sale-receive')?.click();
    expect(closed).toEqual([false, false, false]);
    expect(navigate).toHaveBeenCalledTimes(2);
  });

  it('tells whoever does not keep the stock who can', () => {
    const host = open({ lines: sale?.lines ?? [], confirmable: false, mayWrite: false });

    expect(q(host, 'short-sale-count')).toBeNull();
    expect(q(host, 'short-sale-receive')).toBeNull();
    expect(q(host, 'short-sale-ask')?.textContent).toContain('peut le compter');
  });
});

describe('ShortSales', () => {
  let answer: boolean | undefined;
  let permissions: string[];
  const opened: ShortSaleDialogData[] = [];
  const texts = { open: vi.fn<(name: string) => Promise<void>>() };

  function shortSales(): ShortSales {
    TestBed.configureTestingModule({
      providers: [
        {
          provide: MatDialog,
          useValue: {
            open: (_component: unknown, config: { data: ShortSaleDialogData }) => {
              opened.push(config.data);
              return { afterClosed: () => of(answer) };
            },
          },
        },
        { provide: TextsParts, useValue: texts },
        {
          provide: RefusalFigures,
          useValue: { written: (params: object) => ({ ...params, wanted: '3', on_hand: '1' }) },
        },
        {
          provide: AuthFacade,
          useValue: {
            hasModule: (key: string) => key === 'inventory',
            hasPermission: (permission: string) => permissions.includes(permission),
          },
        },
      ],
    });
    return TestBed.inject(ShortSales);
  }

  beforeEach(() => {
    answer = true;
    permissions = ['stock.write'];
    opened.length = 0;
    texts.open.mockReset().mockResolvedValue(undefined);
  });

  it('asks nothing where no line is short', async () => {
    expect(await shortSales().confirmed([{ field: 'customerId', reason: 'x', params: {} }])).toBe(
      false,
    );
    expect(opened).toEqual([]);
  });

  it('opens its texts, says the figures as the reader reads them, and answers what was chosen', async () => {
    const sales = shortSales();

    expect(await sales.confirmed([short('stock_short_to_confirm', 0, 'V6')])).toBe(true);
    expect(texts.open).toHaveBeenCalledWith('stock-moves');
    expect(opened[0]?.lines[0]?.params.wanted).toBe('3');
    expect(opened[0]?.mayWrite).toBe(true);

    answer = undefined;
    expect(await sales.confirmed([short('stock_short_to_confirm', 0, 'V6')])).toBe(false);
  });

  it('never confirms a refused sale, whatever the dialog closes with', async () => {
    permissions = [];

    expect(await shortSales().confirmed([short('stock_short', 0, 'V6')])).toBe(false);
    expect(opened[0]?.confirmable).toBe(false);
    expect(opened[0]?.mayWrite).toBe(false);
  });

  it('leaves the refusals to the lines when its texts cannot be fetched', async () => {
    texts.open.mockRejectedValue(new Error('offline'));

    expect(await shortSales().confirmed([short('stock_short_to_confirm', 0, 'V6')])).toBe(false);
    expect(opened).toEqual([]);
  });
});
