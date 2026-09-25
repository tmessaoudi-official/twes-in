// SPDX-License-Identifier: AGPL-3.0-or-later

import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { LiveChanges } from '../shared/realtime/live-changes';
import { FirstStepsApi } from './first-steps-api';
import { FirstStepsHome } from './first-steps-home';
import type { FirstSteps } from './first-steps-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      first_steps: {
        title: 'Premiers pas',
        progress: '{{done}} sur {{total}}',
        done: 'Fait',
        steps: {
          company: { profile: 'Profil et matricule', members: 'Inviter un membre' },
          customers: { first: 'Un premier client' },
        },
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-25 22:17 (row 139): the home shows what is left to set up, until nothing is.
describe('FirstStepsHome', () => {
  const api = { read: vi.fn<(companyId: string) => Promise<FirstSteps>>() };
  const live = { reloadOn: vi.fn() };
  let fixture: ComponentFixture<FirstStepsHome>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function open(steps: FirstSteps): Promise<void> {
    api.read.mockResolvedValue(steps);
    fixture = TestBed.createComponent(FirstStepsHome);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(() => {
    api.read.mockReset();
    live.reloadOn.mockReset();
    TestBed.configureTestingModule({
      imports: [FirstStepsHome],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: FirstStepsApi, useValue: api },
        { provide: LiveChanges, useValue: live },
        { provide: AuthFacade, useValue: { me: () => ({ company: { id: 'k1' } }) } },
      ],
    });
  });

  it('lists the steps in the order the API gives, each left one leading to where it is done', async () => {
    await open({
      remaining: 2,
      steps: [
        { key: 'company.profile', done: true },
        { key: 'customers.first', done: false },
        { key: 'company.members', done: false },
      ],
    });

    expect(api.read).toHaveBeenCalledWith('k1');
    expect(live.reloadOn).toHaveBeenCalled();
    expect(q('home-first-steps')?.getAttribute('data-tour')).toBe('first-steps');
    expect(q('first-steps-progress')?.textContent).toContain('1 sur 3');
    const rows = [...fixture.nativeElement.querySelectorAll('[data-step]')] as HTMLElement[];
    expect(rows.map((row) => row.getAttribute('data-step'))).toEqual([
      'company.profile',
      'customers.first',
      'company.members',
    ]);
    expect(rows[0]?.querySelector('a')).toBeNull();
    expect(rows[0]?.getAttribute('data-done')).toBe('true');
    expect(rows[0]?.textContent).toContain('Fait');
    expect(rows[1]?.querySelector('a')?.getAttribute('href')).toBe('/customers/new');
    expect(rows[1]?.textContent).toContain('Un premier client');
    expect(rows[2]?.querySelector('a')?.getAttribute('href')).toBe('/members');
  });

  it('is gone once nothing remains', async () => {
    await open({ remaining: 0, steps: [{ key: 'company.profile', done: true }] });
    expect(q('home-first-steps')).toBeNull();
  });

  it('is gone for a member who may do none of the steps', async () => {
    await open({ remaining: 0, steps: [] });
    expect(q('home-first-steps')).toBeNull();
  });

  it('shows a step it has no address for, without a link', async () => {
    await open({ remaining: 1, steps: [{ key: 'later.step', done: false }] });
    const row = fixture.nativeElement.querySelector('[data-step="later.step"]') as HTMLElement;
    expect(row).not.toBeNull();
    expect(row.querySelector('a')).toBeNull();
  });
});
