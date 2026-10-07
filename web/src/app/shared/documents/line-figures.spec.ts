// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  type TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { FormatFacade } from '../i18n/format-facade';
import type { LineFigures } from './document-figures';
import { LineFiguresView } from './line-figures';

const figures: LineFigures = {
  amount: '37.500',
  discount: '3.750',
  net: '33.750',
  documentDiscount: '3.750',
  taxes: [
    { code: 'FODEC', base: '30.000', amount: '0.300' },
    { code: 'VAT', base: '30.300', amount: '5.757' },
  ],
  total: '36.057',
};

// shared/ reads no feature's files, the translations included: the strings these figures show, inline.
class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      document_figures: {
        toggle: 'Détail de la ligne {{position}}',
        net: 'Net HT',
        total: 'Total TTC',
        amount: 'Quantité × prix',
        discount: 'Remise de la ligne',
        document_discount: 'Part de la remise du document',
        tax: '{{name}} · base {{base}}',
      },
    });
  }
}

describe('LineFiguresView', () => {
  function render(changes: Partial<LineFigures> = {}) {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'fr', fallbackLang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: FormatFacade, useValue: { amount: (value: string) => `~${value}` } },
      ],
    });
    const fixture = TestBed.createComponent(LineFiguresView);
    fixture.componentRef.setInput('figures', { ...figures, ...changes });
    fixture.componentRef.setInput('scale', 3);
    fixture.componentRef.setInput('position', 1);
    fixture.componentRef.setInput('testId', 'line-0');
    fixture.componentRef.setInput('taxName', (code: string) =>
      code === 'VAT' ? 'TVA 19 %' : null,
    );
    fixture.detectChanges();
    const root = fixture.nativeElement as HTMLElement;
    const q = (id: string) => root.querySelector(`[data-testid="${id}"]`);
    return { fixture, q };
  }

  it('is folded to the net and what the line adds, and unfolds every step to them', () => {
    const { fixture, q } = render();
    const toggle = q('line-0-toggle') as HTMLButtonElement;
    expect(q('line-0-net')?.textContent?.trim()).toBe('~33.750');
    expect(q('line-0-total')?.textContent?.trim()).toBe('~36.057');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.getAttribute('aria-label')).toBe('Détail de la ligne 1');
    expect(q('line-0-details')).toBeNull();

    toggle.click();
    fixture.detectChanges();
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.getAttribute('aria-controls')).toBe('line-0-details');
    const details = q('line-0-details')?.textContent ?? '';
    expect(details).toContain('Quantité × prix~37.500');
    expect(details).toContain('Remise de la ligne~-3.750');
    expect(q('line-0-document-discount')?.textContent?.trim()).toBe('~-3.750');
    expect(q('line-0-figure-tax-VAT')?.textContent?.trim()).toBe('~5.757');
    expect(details).toContain('TVA 19 % · base ~30.300');
    // A tax the line's own taxes do not name is shown by its code.
    expect(details).toContain('FODEC · base ~30.000');
  });

  it('leaves out a discount the line does not have', () => {
    const { fixture, q } = render({ discount: '0.000', documentDiscount: '0.000' });
    (q('line-0-toggle') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(q('line-0-document-discount')).toBeNull();
    expect(q('line-0-details')?.textContent).not.toContain('Remise');
  });
});
