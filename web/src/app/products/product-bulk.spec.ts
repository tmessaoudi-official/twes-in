// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { AddToDocuments } from '../shared/documents/add-to-documents';
import { ProductBulk } from './product-bulk';

describe('ProductBulk', () => {
  const addTo = { kinds: vi.fn(() => ['invoice']), open: vi.fn(async () => undefined) };

  beforeEach(() => {
    addTo.kinds.mockReset().mockReturnValue(['invoice']);
    addTo.open.mockClear();
  });

  function bar(ids: string[]) {
    TestBed.configureTestingModule({
      providers: [provideTranslateService({ lang: 'fr' })],
    });
    // The bar provides « Ajouter à… » itself, so the stub replaces its own provider.
    TestBed.overrideComponent(ProductBulk, {
      add: { providers: [{ provide: AddToDocuments, useValue: addTo }] },
    });
    const fixture = TestBed.createComponent(ProductBulk);
    fixture.componentRef.setInput('ids', ids);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('puts the selected products on a document, in the order they were ticked', () => {
    const shown = bar(['p2', 'p1']);
    (shown.querySelector('[data-testid="products-bulk-add-to"]') as HTMLElement).click();
    expect(addTo.open).toHaveBeenCalledExactlyOnceWith(['p2', 'p1']);
  });

  it('offers no « Ajouter à… » to someone who may write no document', () => {
    addTo.kinds.mockReturnValue([]);
    const shown = bar(['p1']);
    expect(shown.querySelector('[data-testid="products-bulk-add-to"]')).toBeNull();
    expect(shown.querySelector('[data-testid="products-bulk-labels"]')).not.toBeNull();
  });

  it('opens the sheet of every selected product’s labels, in the order they were ticked, in a tab of its own', () => {
    TestBed.configureTestingModule({
      providers: [provideTranslateService({ lang: 'fr' })],
    });
    // The bar provides « Ajouter à… » itself, so the stub replaces its own provider.
    TestBed.overrideComponent(ProductBulk, {
      add: { providers: [{ provide: AddToDocuments, useValue: addTo }] },
    });
    const opened = vi
      .spyOn(TestBed.inject(DOCUMENT).defaultView!, 'open')
      .mockImplementation(() => null);
    const fixture = TestBed.createComponent(ProductBulk);
    fixture.componentRef.setInput('ids', ['p2', 'p 1']);
    fixture.detectChanges();

    (
      fixture.nativeElement.querySelector('[data-testid="products-bulk-labels"]') as HTMLElement
    ).click();

    expect(opened).toHaveBeenCalledExactlyOnceWith(
      '/print/product-labels?id=p2&id=p%201',
      '_blank',
    );
  });
});
