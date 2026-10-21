// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ErrorHandler } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { RequestActivity } from '../feedback/request-activity';
import { EFFECT_SAYERS, EFFECTS_HEADER, type SaidEffect } from './effects';
import { effectsInterceptor } from './effects-interceptor';

describe('effectsInterceptor', () => {
  const prices = vi.fn();
  const counts = vi.fn();
  const handleError = vi.fn();
  let settledAtOnce = true;
  let settle: () => void = () => undefined;

  beforeEach(() => {
    prices.mockReset();
    counts.mockReset();
    handleError.mockReset();
    settledAtOnce = true;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([effectsInterceptor])),
        provideHttpClientTesting(),
        { provide: ErrorHandler, useValue: { handleError } },
        {
          provide: RequestActivity,
          useValue: {
            settled: () =>
              settledAtOnce
                ? Promise.resolve()
                : new Promise<void>((resolve) => (settle = resolve)),
          },
        },
        {
          provide: EFFECT_SAYERS,
          useValue: { kinds: ['product.price_followed'], say: prices },
          multi: true,
        },
        {
          provide: EFFECT_SAYERS,
          useValue: { kinds: ['stock.counted'], say: counts },
          multi: true,
        },
      ],
    });
  });

  function answer(url: string, header: string | null, status = 200): void {
    const http = TestBed.inject(HttpClient);
    http.post(url, {}).subscribe({ error: () => undefined });
    TestBed.inject(HttpTestingController)
      .expectOne(url)
      .flush(status === 200 ? {} : { title: 'refused' }, {
        status,
        statusText: status === 200 ? 'OK' : 'Conflict',
        headers: header === null ? {} : { [EFFECTS_HEADER]: header },
      });
  }

  const aTurn = (): Promise<void> => new Promise((resolve) => setTimeout(resolve, 0));

  it('hands each sayer the effects of its own kinds, whichever page sent the request', async () => {
    const followed: SaidEffect = {
      kind: 'product.price_followed',
      id: 'p1',
      params: { previousPrice: '1250.0000' },
    };
    const counted: SaidEffect = { kind: 'stock.counted', id: 'm1', params: {} };

    answer('/api/companies/a/stock-movements', JSON.stringify([followed, counted]));

    await vi.waitFor(() => expect(prices).toHaveBeenCalledExactlyOnceWith([followed]));
    expect(counts).toHaveBeenCalledExactlyOnceWith([counted]);
    expect(handleError).not.toHaveBeenCalled();
  });

  it('says what a request did only once the requests it set off have answered, so the page says its outcome first', async () => {
    settledAtOnce = false;
    answer(
      '/api/companies/a/stock-movements',
      '[{"kind":"product.price_followed","id":"p1","params":{}}]',
    );
    await aTurn();
    expect(prices).not.toHaveBeenCalled();

    settle();

    await vi.waitFor(() => expect(prices).toHaveBeenCalledOnce());
  });

  it('hands over what a refused request still did, as one that failed after saving', async () => {
    answer(
      '/api/companies/a/stock-movements',
      '[{"kind":"product.price_followed","id":"p1","params":{}}]',
      409,
    );

    await vi.waitFor(() => expect(prices).toHaveBeenCalledOnce());
  });

  it('says nothing when the answer names no effect', async () => {
    answer('/api/companies/a/customers', null);
    await aTurn();

    expect(prices).not.toHaveBeenCalled();
    expect(counts).not.toHaveBeenCalled();
    expect(handleError).not.toHaveBeenCalled();
  });

  it('reports an effect no screen says, since the API said it for someone to hear', async () => {
    answer(
      '/api/companies/a/stock-movements',
      '[{"kind":"stock.carried","id":null,"params":{},"count":3}]',
    );

    await vi.waitFor(() => expect(handleError).toHaveBeenCalledOnce());
    expect(String(handleError.mock.calls[0]?.[0])).toContain('stock.carried');
  });

  it('reports a header it cannot read rather than failing the request', () => {
    let answered = false;
    const http = TestBed.inject(HttpClient);
    http.post('/api/x', {}).subscribe(() => (answered = true));
    TestBed.inject(HttpTestingController)
      .expectOne('/api/x')
      .flush({}, { headers: { [EFFECTS_HEADER]: '{"kind":' } });

    expect(answered).toBe(true);
    expect(handleError).toHaveBeenCalledOnce();
  });

  it('reports a sayer that failed, the request having succeeded', async () => {
    prices.mockRejectedValue(new Error('offline'));

    answer(
      '/api/companies/a/stock-movements',
      '[{"kind":"product.price_followed","id":"p1","params":{}}]',
    );

    await vi.waitFor(() => expect(handleError).toHaveBeenCalledOnce());
  });
});
