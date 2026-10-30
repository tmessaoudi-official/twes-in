// SPDX-License-Identifier: AGPL-3.0-or-later

import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService, TranslateService } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { FormatFacade } from '../i18n/format-facade';
import { TextsParts } from '../i18n/texts-part';
import { SPENT_BUDGET_TEXTS, SpentBudget } from './spent-budget';
import { spentBudgetInterceptor } from './spent-budget-interceptor';

describe('SpentBudget', () => {
  const open = vi.fn<(name: string) => Promise<void>>(async () => undefined);

  beforeEach(() => {
    open.mockClear();
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([spentBudgetInterceptor])),
        provideHttpClientTesting(),
        provideTranslateService({ lang: 'fr' }),
        // The hour as the screen writes it, from the moment it is given.
        { provide: FormatFacade, useValue: { time: (at: string) => at.slice(11, 16) } },
        { provide: TextsParts, useValue: { open } },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', {
      spent_budget: { retry_at: 'Réessayez à {{time}}.' },
    });
  });

  afterEach(() => TestBed.inject(HttpTestingController).verify());

  async function answered(status: number, headers: Record<string, string>): Promise<void> {
    const sent = firstValueFrom(TestBed.inject(HttpClient).post('/api/auth/mfa/verify', {}));
    TestBed.inject(HttpTestingController)
      .expectOne('/api/auth/mfa/verify')
      .flush({ error: 'too_many_attempts' }, { status, statusText: String(status), headers });
    await sent.catch(() => undefined);
    // The texts are opened before the sentence is worded.
    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  it('says the hour a spent budget may be tried again at, from the seconds the API gives', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-30T14:30:00Z'));
    try {
      await answered(429, { 'Retry-After': '120' });
    } finally {
      vi.useRealTimers();
    }

    expect(open).toHaveBeenCalledWith(SPENT_BUDGET_TEXTS);
    expect(TestBed.inject(SpentBudget).retry()).toBe('Réessayez à 14:32.');
  });

  it('says nothing of a wait the answer did not give, and forgets the one before', async () => {
    await answered(429, { 'Retry-After': '60' });
    expect(TestBed.inject(SpentBudget).retry()).not.toBe('');

    await answered(429, {});

    expect(TestBed.inject(SpentBudget).retry()).toBe('');
  });

  it('leaves the wait alone on any other refusal', async () => {
    await answered(429, { 'Retry-After': '60' });

    await answered(401, { 'Retry-After': '999' });

    expect(TestBed.inject(SpentBudget).retry()).not.toBe('');
    expect(open).toHaveBeenCalledTimes(1);
  });
});
