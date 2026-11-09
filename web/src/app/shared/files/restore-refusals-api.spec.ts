// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { Injector } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { TextsParts } from '../i18n/texts-part';
import { FORM_REFUSAL_TEXTS } from '../form/form-refusal-texts';
import { restoreRefusalsIn } from './restore-refusals-api';

describe('restoreRefusalsIn', () => {
  const open = vi.fn<(name: string) => Promise<void>>();
  let injector: Injector;

  beforeEach(() => {
    open.mockReset().mockResolvedValue(undefined);
    TestBed.configureTestingModule({ providers: [{ provide: TextsParts, useValue: { open } }] });
    injector = TestBed.inject(Injector);
  });

  const refused = (status: number, reason: string) =>
    new HttpErrorResponse({
      status,
      statusText: String(status),
      error: {
        violations: [{ propertyPath: '', message: 'x', payload: { reason, params: { max: 10 } } }],
      },
    });

  it("sets a 422's refusal aside: its words are an upload's, the restore's code says why the file cannot come back", async () => {
    expect(await restoreRefusalsIn(injector, refused(422, 'too_many_files'))).toEqual([]);
    expect(open).not.toHaveBeenCalled();
  });

  it('reads a move the record refuses, which a 409 names, as every reader does', async () => {
    expect(await restoreRefusalsIn(injector, refused(409, 'expense_recorded'))).toEqual([
      { field: '', reason: 'expense_recorded', params: { max: 10 } },
    ]);
    expect(open).toHaveBeenCalledWith(FORM_REFUSAL_TEXTS);
  });

  it('reads nothing from a lost connection', async () => {
    expect(await restoreRefusalsIn(injector, new Error('offline'))).toEqual([]);
  });
});
