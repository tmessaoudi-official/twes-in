// SPDX-License-Identifier: AGPL-3.0-or-later
import { FormControl, Validators } from '@angular/forms';

import { lengthSaid, lengthSaidOf } from './length-said';

describe('lengthSaid', () => {
  it('says nothing of a value that fits, even at its limit', () => {
    expect(lengthSaid('abc', 3)).toBeNull();
    expect(lengthSaid(null, 3)).toBeNull();
  });

  it('says the limit and the count of a value past it', () => {
    expect(lengthSaid('abcd', 3)).toEqual({
      key: 'form.errors.max_length',
      params: { max: 3 },
      count: '4 / 3',
    });
  });

  it('reads a control refused by its maximum, the value kept whole', () => {
    const control = new FormControl('x'.repeat(41), { validators: Validators.maxLength(40) });
    expect(control.value).toHaveLength(41);
    expect(lengthSaidOf(control)).toEqual({
      key: 'form.errors.max_length',
      params: { max: 40 },
      count: '41 / 40',
    });
    control.setValue('x'.repeat(40));
    expect(lengthSaidOf(control)).toBeNull();
  });

  it('says nothing of a control refused for another reason only', () => {
    const control = new FormControl('', {
      validators: [Validators.required, Validators.maxLength(40)],
    });
    expect(control.hasError('required')).toBe(true);
    expect(lengthSaidOf(control)).toBeNull();
  });
});
