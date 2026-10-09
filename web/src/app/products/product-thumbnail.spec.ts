// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import { ProductThumbnail } from './product-thumbnail';

describe('ProductThumbnail', () => {
  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [ProductThumbnail],
      providers: [
        provideHttpClient(),
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'c1' } }) } },
      ],
    });
  });

  function shown(photoId: string | null) {
    const fixture = TestBed.createComponent(ProductThumbnail);
    fixture.componentRef.setInput('productId', 'p1');
    fixture.componentRef.setInput('photoId', photoId);
    fixture.componentRef.setInput('side', 40);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('draws the main photo’s small copy at the side asked, saying nothing a name beside it does not', () => {
    const element = shown('ph1');
    const image = element.querySelector('img');

    expect(image?.getAttribute('src')).toBe(
      '/api/companies/c1/products/p1/photos/ph1/content?size=small',
    );
    expect(image?.getAttribute('alt')).toBe('');
    expect(element.style.width).toBe('40px');
  });

  it('keeps its place with a plain mark when the product has no photo', () => {
    const element = shown(null);

    expect(element.querySelector('img')).toBeNull();
    expect(element.querySelector('[data-testid="product-thumbnail-none"]')).not.toBeNull();
    expect(element.style.height).toBe('40px');
  });
});
