// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import {
  provideTranslateCompiler,
  provideTranslateLoader,
  provideTranslateService,
  type TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { PluralCompiler } from '../i18n/plural-compiler';
import { TextsParts } from '../i18n/texts-part';
import { Session } from '../session/session';
import { UNOFFERED_TEXTS, UnofferedProducts } from './unoffered-products';
import { type UnofferedCounts, UnofferedProductsApi } from './unoffered-products-api';

/**
 * Words of the part's shape, as `TextsParts.open` would lay them into the root: a shared spec reads no feature's files,
 * and the part's own sentences are held in each language by `i18n-parity.spec.ts`.
 */
const WORDS = {
  unoffered: {
    deactivated:
      '{{count}} {count, plural, one {switched off: turn it on} other {switched off: turn them on}}.',
    services: '{{count}} {count, plural, one {service} other {services}}: goods only.',
    not_stocked: '{{count}} {count, plural, one {product} other {products}} not stocked.',
    see_deactivated: 'See the switched off',
    see_products: 'See them',
  },
};

class PartLoader implements TranslateLoader {
  getTranslation() {
    return of(WORDS);
  }
}

describe('UnofferedProducts', () => {
  let granted: Set<string>;
  let modules: Set<string>;
  let answer: () => Promise<UnofferedCounts>;
  let asked: { companyId: string; selector: string; words: string }[];
  let opened: string[];
  let said: UnofferedProducts;

  beforeEach(() => {
    granted = new Set(['product.read']);
    modules = new Set(['products']);
    asked = [];
    opened = [];
    answer = () => Promise.resolve({ deactivated: 0, services: 0, notStocked: 0 });
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService({ lang: 'en', compiler: provideTranslateCompiler(PluralCompiler) }),
        provideTranslateLoader(PartLoader),
        {
          provide: Session,
          useValue: {
            hasPermission: (permission: string) => granted.has(permission),
            hasModule: (module: string) => modules.has(module),
          },
        },
        {
          provide: TextsParts,
          useValue: { open: (name: string) => (opened.push(name), Promise.resolve()) },
        },
        {
          provide: UnofferedProductsApi,
          useValue: {
            unoffered: (companyId: string, selector: string, words: string) => {
              asked.push({ companyId, selector, words });
              return answer();
            },
          },
        },
      ],
    });
    said = TestBed.inject(UnofferedProducts);
  });

  it('says the products switched off, their count in its form, with the way to them', async () => {
    const why = await said.said({ deactivated: 2, services: 0, notStocked: 0 }, ' café ');

    expect(opened).toEqual([UNOFFERED_TEXTS]);
    expect(why?.sentences).toEqual(['2 switched off: turn them on.']);
    expect(why?.link).toEqual({
      label: 'See the switched off',
      path: '/products',
      query: { q: 'café', status: 'inactive' },
    });
    const one = await said.said({ deactivated: 1, services: 0, notStocked: 0 }, 'café');
    expect(one?.sentences).toEqual(['1 switched off: turn it on.']);
  });

  it('says every reason a stock selector hid, and leads to the products whose stock is not kept', async () => {
    const why = await said.said({ deactivated: 0, services: 2, notStocked: 1 }, 'café');

    expect(why?.sentences).toEqual(['2 services: goods only.', '1 product not stocked.']);
    expect(why?.link).toEqual({ label: 'See them', path: '/products', query: { q: 'café' } });

    const services = await said.said({ deactivated: 0, services: 3, notStocked: 0 }, 'café');
    expect(services?.link).toBeNull();
  });

  it('offers no way to the products to a person who may not read them, or while the module is off', async () => {
    granted.clear();
    expect(
      (await said.said({ deactivated: 2, services: 0, notStocked: 0 }, 'café'))?.link,
    ).toBeNull();
    granted.add('product.read');
    modules.clear();
    expect(
      (await said.said({ deactivated: 2, services: 0, notStocked: 0 }, 'café'))?.link,
    ).toBeNull();
  });

  it('says nothing more than « none found » when the words hide nothing', async () => {
    expect(await said.said({ deactivated: 0, services: 0, notStocked: 0 }, 'café')).toBeNull();
    expect(opened).toEqual([]);
  });

  it('asks the selector’s own API for the company acted for, and only for words', async () => {
    let company: string | null = null;
    const explain = said.explainer(() => company, 'stock-options');

    expect(await explain('café')).toBeNull();
    company = 'c1';
    expect(await explain('  ')).toBeNull();
    expect(asked).toEqual([]);

    answer = () => Promise.resolve({ deactivated: 1, services: 0, notStocked: 0 });
    expect((await explain('café'))?.sentences).toHaveLength(1);
    expect(asked).toEqual([{ companyId: 'c1', selector: 'stock-options', words: 'café' }]);
  });

  it('counts a catalogue reader’s switched-off products alone', async () => {
    const counted: string[] = [];
    const explain = said.deactivatedExplainer((words) => (counted.push(words), Promise.resolve(3)));

    expect((await explain('café'))?.sentences).toEqual(['3 switched off: turn them on.']);
    expect(counted).toEqual(['café']);
    expect(await said.deactivatedExplainer(() => Promise.resolve(null))('café')).toBeNull();
  });

  it('says only « none found » when the reasons cannot be read, the failure being said where every read’s is', async () => {
    answer = () => Promise.reject(new Error('unreachable'));
    expect(await said.explainer(() => 'c1', 'invoice-options')('café')).toBeNull();
  });
});
