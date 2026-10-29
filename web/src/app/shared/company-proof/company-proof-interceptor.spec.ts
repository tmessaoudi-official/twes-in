// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanyEntry } from './company-entry';
import { companyProofInterceptor, companyProofRequired } from './company-proof-interceptor';

describe('companyProofInterceptor', () => {
  const entry = { prove: vi.fn<() => Promise<boolean>>() };

  beforeEach(() => {
    entry.prove.mockReset();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([companyProofInterceptor])),
        provideHttpClientTesting(),
        { provide: CompanyEntry, useValue: entry },
      ],
    });
  });

  const http = () => TestBed.inject(HttpClient);
  const backend = () => TestBed.inject(HttpTestingController);
  const refusal = { detail: 'company_proof_required' };
  const forbidden = { status: 403, statusText: 'Forbidden' };

  it('asks for the proof and sends the request again once it is given', async () => {
    entry.prove.mockResolvedValue(true);
    const answer = firstValueFrom(http().get<{ rows: number }>('/api/companies/c2/customers'));

    backend().expectOne('/api/companies/c2/customers').flush(refusal, forbidden);
    await Promise.resolve();
    await Promise.resolve();
    backend().expectOne('/api/companies/c2/customers').flush({ rows: 3 });

    await expect(answer).resolves.toEqual({ rows: 3 });
    expect(entry.prove).toHaveBeenCalledOnce();
  });

  it('hands the refusal to whoever asked when the proof is turned down, and sends nothing again', async () => {
    entry.prove.mockResolvedValue(false);
    const answer = firstValueFrom(http().get('/api/companies/c2/customers'));

    backend().expectOne('/api/companies/c2/customers').flush(refusal, forbidden);

    await expect(answer).rejects.toBeInstanceOf(HttpErrorResponse);
    backend().verify();
  });

  it('leaves every other refusal alone', async () => {
    const answer = firstValueFrom(http().get('/api/companies/c2/customers'));
    backend()
      .expectOne('/api/companies/c2/customers')
      .flush({ detail: 'step_up_required: change' }, forbidden);

    await expect(answer).rejects.toBeInstanceOf(HttpErrorResponse);
    expect(entry.prove).not.toHaveBeenCalled();
  });

  it('reads the refusal as API Platform writes it and as a plain controller does, and only on a 403', () => {
    const answer = (body: unknown, status = 403) =>
      new HttpErrorResponse({ error: body, status, statusText: 'x' });

    expect(companyProofRequired(answer({ detail: 'company_proof_required' }))).toBe(true);
    expect(companyProofRequired(answer({ error: 'company_proof_required' }))).toBe(true);
    expect(companyProofRequired(answer({ detail: 'company_proof_required' }, 404))).toBe(false);
    expect(companyProofRequired(answer({ detail: 'forbidden' }))).toBe(false);
    expect(companyProofRequired(answer(null))).toBe(false);
  });
});
