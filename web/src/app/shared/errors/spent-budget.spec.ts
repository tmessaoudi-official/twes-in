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
        {
          provide: FormatFacade,
          useValue: {
            time: (at: string) => at.slice(11, 16),
            day: (day: string) => `day ${day}`,
          },
        },
        { provide: TextsParts, useValue: { open } },
      ],
    });
    TestBed.inject(TranslateService).setTranslation('fr', {
      spent_budget: {
        retry_at: 'Réessayez à {{time}}.',
        retry_on: 'Réessayez le {{day}} à {{time}}.',
        retry_later: 'Réessayez plus tard.',
      },
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
      // Read while the clock still says that day: the sentence names the day when it is not today's.
      expect(open).toHaveBeenCalledWith(SPENT_BUDGET_TEXTS);
      expect(TestBed.inject(SpentBudget).retry()).toBe('Réessayez à 14:32.');
    } finally {
      vi.useRealTimers();
    }
  });

  it('says to try later when the answer gave no wait, and forgets the one before', async () => {
    await answered(429, { 'Retry-After': '60' });
    expect(TestBed.inject(SpentBudget).retry()).toMatch(/^Réessayez à /);

    await answered(429, {});

    expect(TestBed.inject(SpentBudget).retry()).toBe('Réessayez plus tard.');
  });

  it('opens its texts for an answer giving no wait too, so the screen never ends on « Trop de tentatives. »', async () => {
    await answered(429, {});

    expect(open).toHaveBeenCalledWith(SPENT_BUDGET_TEXTS);
    expect(TestBed.inject(SpentBudget).retry()).toBe('Réessayez plus tard.');
  });

  it('names the day when the moment to try again is not today', async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date(2026, 9, 30, 23, 0, 0));
    try {
      await answered(429, { 'Retry-After': String(2 * 3600) });
      const at = new Date(2026, 9, 31, 1, 0, 0).toISOString();
      expect(TestBed.inject(SpentBudget).retry()).toBe(
        `Réessayez le day 2026-10-31 à ${at.slice(11, 16)}.`,
      );
    } finally {
      vi.useRealTimers();
    }
  });

  it('leaves the wait alone on any other refusal', async () => {
    await answered(429, { 'Retry-After': '60' });

    await answered(401, { 'Retry-After': '999' });

    expect(TestBed.inject(SpentBudget).retry()).not.toBe('');
    expect(open).toHaveBeenCalledTimes(1);
  });
});
