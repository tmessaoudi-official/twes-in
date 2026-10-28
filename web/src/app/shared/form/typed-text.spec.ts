// SPDX-License-Identifier: AGPL-3.0-or-later

import { typedText } from './typed-text';

describe('typedText', () => {
  it('takes every space and invisible mark at either end, as the API does', () => {
    expect(typedText(' +216 71 900 868​ ')).toBe('+216 71 900 868');
    expect(typedText('﻿‎\tKGM \n')).toBe('KGM');
  });

  it('keeps what stands between the ends as typed', () => {
    expect(typedText(' Rue  de Marseille ')).toBe('Rue  de Marseille');
    expect(typedText('​​')).toBe('');
  });
});
