// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { refusedFor } from '../shared/errors/said-already';
import { StepUp } from '../shared/step-up/step-up';
import { demoAnswer } from '../shared/testing/said-answers';
import { TextCatalogueApi } from './text-catalogue-api';
import { TextsFacade } from './texts-facade';
import { TextRefused } from './texts-types';

describe('TextsFacade', () => {
  const api = { page: vi.fn(), write: vi.fn(), reset: vi.fn() };
  const proof = { request: vi.fn() };
  let facade: TextsFacade;
  const company = { kind: 'company', companyId: 'c1' } as const;
  const installation = { kind: 'installation' } as const;
  const title = { domain: 'pdf', key: 'invoice.title' };
  const query = { words: '', place: null, changed: false, page: 1, itemsPerPage: 25 };
  const stepUpAnswer = new HttpErrorResponse({
    status: 403,
    error: { error: 'step_up_required' },
  });

  beforeEach(() => {
    for (const each of [api.page, api.write, api.reset, proof.request]) each.mockReset();
    TestBed.configureTestingModule({
      providers: [
        { provide: TextCatalogueApi, useValue: api },
        {
          provide: StepUp,
          useFactory: () => {
            const real = new StepUp();
            real.request = proof.request;
            return real;
          },
        },
      ],
    });
    facade = TestBed.inject(TextsFacade);
  });

  it('keeps the page the latest read answered, and an empty one when it fails', async () => {
    api.page.mockResolvedValueOnce({ rows: [{ key: 'a' }], total: 7 });
    await facade.load(company, 'fr', query);
    expect(facade.total()).toBe(7);
    expect(facade.failed()).toBe(false);

    api.page.mockRejectedValueOnce(new Error('down'));
    await facade.load(company, 'fr', query);
    expect(facade.rows()).toEqual([]);
    expect(facade.failed()).toBe(true);
  });

  it('asks the operator to prove who is at the screen before the installation’s text, and then writes it', async () => {
    api.write
      .mockRejectedValueOnce(stepUpAnswer)
      .mockResolvedValueOnce({ value: 'Facture de vente', version: 1 });
    proof.request.mockResolvedValue(true);

    const outcome = await facade.write(installation, 'fr', title, 'Facture de vente', 0);

    expect(proof.request).toHaveBeenCalledWith('step_up.intro_change');
    expect(outcome).toEqual({ kept: true, answer: { value: 'Facture de vente', version: 1 } });
  });

  it('never asks a company’s writer for a proof the API does not ask', async () => {
    api.reset.mockRejectedValueOnce(stepUpAnswer);

    const outcome = await facade.reset(company, 'fr', title);

    expect(proof.request).not.toHaveBeenCalled();
    expect(outcome).toEqual({ kept: false, refusal: 'unavailable' });
  });

  it('answers a refusal’s reason, and nothing to say for what an interceptor has said', async () => {
    api.write.mockRejectedValueOnce(refusedFor(new Error('422'), new TextRefused('plural')));
    expect(await facade.write(company, 'fr', title, 'x', 1)).toEqual({
      kept: false,
      refusal: 'plural',
    });

    api.write.mockRejectedValueOnce(demoAnswer());
    expect(await facade.write(company, 'fr', title, 'x', 1)).toEqual({
      kept: false,
      refusal: null,
    });
  });
});
