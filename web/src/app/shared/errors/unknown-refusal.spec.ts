// SPDX-License-Identifier: AGPL-3.0-or-later

import { codeText, refusalKey, unreadPart } from './unknown-refusal';

describe('a part another feature reads for a screen', () => {
  it('is the network when that feature could not reach the API, never a part refused', () => {
    expect(unreadPart('custom_fields', 'network')).toBe('network');
  });

  it('names the part and the code that feature answered, an answer it had no words for included', () => {
    expect(unreadPart('stock_locations', 'not_found')).toEqual({
      unread: 'stock_locations',
      code: 'not_found',
    });
    expect(unreadPart('article_defaults', { unknown: 'brand_new' })).toEqual({
      unread: 'article_defaults',
      code: 'brand_new',
    });
  });

  it("is said in the part's own sentence, its code a parameter, and written as its code", () => {
    const part = { unread: 'vendors', code: 'invalid' } as const;

    expect(refusalKey('products.errors', part)).toEqual({
      key: 'unread.vendors',
      params: { code: 'invalid' },
    });
    expect(codeText(part)).toBe('invalid');
  });
});
