// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { saidAlready } from '../shared/errors/said-already';
import { demoAnswer } from '../shared/testing/said-answers';
import { TextCatalogueApi } from './text-catalogue-api';
import { TextRefused } from './texts-types';

describe('TextCatalogueApi', () => {
  let http: HttpTestingController;
  let api: TextCatalogueApi;
  const company = { kind: 'company', companyId: 'c1' } as const;
  const installation = { kind: 'installation' } as const;
  const title = { domain: 'pdf', key: 'invoice.title' };

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    http = TestBed.inject(HttpTestingController);
    api = TestBed.inject(TextCatalogueApi);
  });

  afterEach(() => http.verify());

  it('asks a level’s catalogue for the words, the place, the changed ones and the page', async () => {
    const page = api.page(company, 'fr', {
      words: '  facture ',
      place: 'screen',
      changed: true,
      page: 2,
      itemsPerPage: 50,
    });
    const request = http.expectOne((each) => each.url === '/api/companies/c1/texts/fr/catalogue');
    expect(request.request.params.toString()).toBe(
      'page=2&itemsPerPage=50&q=facture&domain=screen&changed=1',
    );
    const row = {
      domain: 'pdf',
      key: 'invoice.title',
      original: 'Facture',
      installation: null,
      company: 'Facture client',
      version: 3,
      originalChanged: false,
    };
    request.flush({ items: [row], total: 1, page: 2, itemsPerPage: 50 });

    expect(await page).toEqual({ rows: [row], total: 1 });
  });

  it('asks the installation’s with nothing it was not given', async () => {
    const page = api.page(installation, 'ar', {
      words: ' ',
      place: null,
      changed: false,
      page: 1,
      itemsPerPage: 25,
    });
    const request = http.expectOne((each) => each.url === '/api/platform/texts/ar/catalogue');
    expect(request.request.params.toString()).toBe('page=1&itemsPerPage=25');
    request.flush({ items: [], total: 0, page: 1, itemsPerPage: 25 });
    expect(await page).toEqual({ rows: [], total: 0 });
  });

  it('writes and takes back a text at its level’s address', async () => {
    const written = api.write(installation, 'fr', title, 'Facture de vente', 0);
    const put = http.expectOne('/api/platform/texts/fr/pdf/invoice.title');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body).toEqual({ value: 'Facture de vente', version: 0 });
    put.flush({ value: 'Facture de vente', original: 'Facture', version: 1 });
    expect(await written).toEqual({ value: 'Facture de vente', version: 1 });

    const taken = api.reset(company, 'fr', { domain: 'screen:two-factor', key: 'auth.title' });
    const remove = http.expectOne('/api/companies/c1/texts/fr/screen:two-factor/auth.title');
    expect(remove.request.method).toBe('DELETE');
    remove.flush(null, { status: 204, statusText: 'No Content' });
    await taken;
  });

  it('says a refusal the API gives a reason for, and a text saved since', async () => {
    const placeholders = api.write(company, 'fr', title, 'Facture', 1);
    http
      .expectOne('/api/companies/c1/texts/fr/pdf/invoice.title')
      .flush(
        { error: 'placeholders', detail: 'Keep %number%.' },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    await expect(placeholders).rejects.toEqual(new TextRefused('placeholders'));

    const since = api.write(company, 'fr', title, 'Facture', 1);
    http
      .expectOne('/api/companies/c1/texts/fr/pdf/invoice.title')
      .flush(
        { type: '/problems/record-changed', title: 'Changed', status: 409, detail: 'x' },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(since).rejects.toEqual(new TextRefused('changed'));
  });

  it('lets any other failure through as it came, a reason it does not know included', async () => {
    const unknown = api.write(company, 'fr', title, 'Facture', 1);
    http
      .expectOne('/api/companies/c1/texts/fr/pdf/invoice.title')
      .flush(
        { error: 'invalid_body', detail: 'x' },
        { status: 422, statusText: 'Unprocessable Entity' },
      );
    await expect(unknown).rejects.not.toBeInstanceOf(TextRefused);

    const demo = api.reset(company, 'fr', title);
    const answer = demoAnswer();
    http
      .expectOne('/api/companies/c1/texts/fr/pdf/invoice.title')
      .flush(answer.error, { status: answer.status, statusText: answer.statusText });
    const error = await demo.catch((caught: unknown) => caught);
    expect(saidAlready(error)).toBe(true);
  });
});
