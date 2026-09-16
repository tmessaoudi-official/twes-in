// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { LanguageFacade } from '../i18n/language-facade';
import { Brand, DEFAULT_BRAND_KIT, DefaultBrand } from './brand';
import { signal } from '@angular/core';

describe('DefaultBrand', () => {
  const current = signal<'fr' | 'en'>('fr');

  beforeEach(() => {
    current.set('fr');
    TestBed.configureTestingModule({
      providers: [
        { provide: Brand, useClass: DefaultBrand },
        { provide: LanguageFacade, useValue: { current } },
      ],
    });
  });

  it('serves the installation defaults: the name and the chosen tagline', () => {
    const brand = TestBed.inject(Brand);

    expect(brand.name()).toBe('twes-in');
    expect(brand.tagline()).toBe('Tout en lieu sûr. Tout tracé. Rien ne se perd.');
    expect(DEFAULT_BRAND_KIT.tagline.en).toBe('All kept safe. All on record. Nothing lost.');
  });

  it('says the tagline in the interface language, following a switch', () => {
    const brand = TestBed.inject(Brand);

    current.set('en');

    expect(brand.tagline()).toBe('All kept safe. All on record. Nothing lost.');
  });
});
