// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TextsParts } from '../i18n/texts-part';
import { FORM_REFUSAL_TEXTS } from './form-refusal-texts';
import { formRefusalsIn } from './form-refusals-api';

describe('formRefusalsIn', () => {
  const open = vi.fn<(name: string) => Promise<void>>();
  let injector: Injector;

  beforeEach(() => {
    open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({ providers: [{ provide: TextsParts, useValue: { open } }] });
    injector = TestBed.inject(Injector);
  });

  const refused = (status: number, body: unknown) =>
    new HttpErrorResponse({ status, statusText: String(status), error: body });
  const twice = {
    violations: [{ propertyPath: 'choices', message: 'x', payload: { reason: 'choice_twice' } }],
  };

  it('reads the fields a 422 names and opens their texts before the form says them', async () => {
    expect(await formRefusalsIn(injector, refused(422, twice))).toEqual([
      { field: 'choices', reason: 'choice_twice', params: {} },
    ]);
    expect(open).toHaveBeenCalledWith(FORM_REFUSAL_TEXTS);
  });

  it('opens nothing for a refusal naming no field, or another answer', async () => {
    expect(await formRefusalsIn(injector, refused(422, { detail: 'x' }))).toEqual([]);
    expect(await formRefusalsIn(injector, refused(409, twice))).toEqual([]);
    expect(await formRefusalsIn(injector, new Error('offline'))).toEqual([]);
    expect(open).not.toHaveBeenCalled();
  });

  it('still names the fields when their texts cannot be fetched', async () => {
    open.mockRejectedValueOnce(new Error('offline'));
    expect(await formRefusalsIn(injector, refused(422, twice))).toEqual([
      { field: 'choices', reason: 'choice_twice', params: {} },
    ]);
  });
});
