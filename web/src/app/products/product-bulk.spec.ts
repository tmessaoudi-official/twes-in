// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { ProductBulk } from './product-bulk';

describe('ProductBulk', () => {
  it('opens the sheet of every selected product’s labels, in the order they were ticked, in a tab of its own', () => {
    TestBed.configureTestingModule({ providers: [provideTranslateService({ lang: 'fr' })] });
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
