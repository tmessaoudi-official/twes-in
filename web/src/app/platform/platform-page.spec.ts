// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { PlatformFacade } from './platform-facade';
import { PlatformPage } from './platform-page';
import type {
  PlatformAccountRow,
  PlatformCompanyRow,
  PlatformError,
  PlatformSignup,
} from './platform-types';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      platform: {
        title: 'Plateforme',
        signup: {
          title: 'Inscriptions',
          enabled: 'Inscriptions ouvertes',
          approval_required: 'Approbation requise',
        },
        waiting: {
          title: 'En attente',
          none: 'Aucune entreprise en attente.',
          owners: 'Propriétaires : {{owners}}',
          approve: 'Approuver',
          reject: 'Refuser',
        },
        accounts: {
          title: 'Comptes',
          search: 'Adresse ou nom',
          find: 'Rechercher',
          none: 'Aucun compte trouvé.',
          active: 'Actif',
          inactive: 'Désactivé',
          operator: 'Opérateur',
          end_sessions: 'Terminer les sessions',
          deactivate: 'Désactiver',
          reactivate: 'Réactiver',
        },
        errors: {
          not_found: 'Introuvable',
          refused: 'Refusé',
          network: 'Injoignable',
          own_account: 'Pas le vôtre',
        },
      },
    });
  }
}

const account: PlatformAccountRow = {
  id: 'u1',
  email: 'nadia@acme.test',
  displayName: 'Nadia',
  active: true,
  platformOperator: false,
  createdAt: '2026-09-15T10:00:00+00:00',
};

const row: PlatformCompanyRow = {
  id: 'c1',
  name: 'Nouvelle Société',
  countryCode: 'TN',
  status: 'pending',
  createdAt: '2026-09-15T10:00:00+00:00',
  owners: ['nadia@example.test'],
};

describe('PlatformPage', () => {
  const waiting = signal<readonly PlatformCompanyRow[]>([row]);
  const signup = signal<PlatformSignup | null>({ enabled: false, approvalRequired: true });
  const busy = signal(false);
  const error = signal<PlatformError | null>(null);
  const accounts = signal<readonly PlatformAccountRow[]>([account]);
  const facade = {
    waiting,
    accounts,
    signup,
    busy,
    error,
    load: vi.fn(),
    approve: vi.fn(),
    reject: vi.fn(),
    setSignup: vi.fn(),
    findAccounts: vi.fn(),
    actOnAccount: vi.fn(),
  };

  beforeEach(async () => {
    waiting.set([row]);
    signup.set({ enabled: false, approvalRequired: true });
    error.set(null);
    accounts.set([account]);
    Object.values(facade)
      .filter((value) => typeof value === 'function' && 'mockReset' in value)
      .forEach((fn) => (fn as ReturnType<typeof vi.fn>).mockReset().mockResolvedValue(true));
    await TestBed.configureTestingModule({
      imports: [PlatformPage],
      providers: [
        { provide: PlatformFacade, useValue: facade },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(PlatformPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const query = <T extends HTMLElement>(id: string) =>
      el.querySelector<T>(`[data-testid="${id}"]`);
    const switchOf = (id: string) =>
      query(id)!.querySelector('button[role="switch"]') as HTMLButtonElement;
    return { fixture, query, switchOf };
  }

  it('loads the platform when it opens', async () => {
    await render();

    expect(facade.load).toHaveBeenCalled();
  });

  it('shows each waiting company with its country and owners', async () => {
    const { query } = await render();

    const line = query('waiting-Nouvelle Société')!;
    expect(line.textContent).toContain('Nouvelle Société');
    expect(line.textContent).toContain('TN');
    expect(line.textContent).toContain('nadia@example.test');
    expect(query('platform-waiting-empty')).toBeNull();
  });

  it('says so when no company waits', async () => {
    waiting.set([]);

    const { query } = await render();

    expect(query('platform-waiting-empty')?.textContent).toContain('Aucune entreprise en attente.');
  });

  it('approves or rejects the company of that line', async () => {
    const { fixture, query } = await render();

    query<HTMLButtonElement>('approve-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.approve).toHaveBeenCalledWith('c1');

    query<HTMLButtonElement>('reject-Nouvelle Société')!.click();
    await fixture.whenStable();
    expect(facade.reject).toHaveBeenCalledWith('c1');
  });

  it('shows the two signup switches as the platform holds them and turns one', async () => {
    const { fixture, switchOf } = await render();

    expect(switchOf('platform-signup-enabled').getAttribute('aria-checked')).toBe('false');
    expect(switchOf('platform-approval-required').getAttribute('aria-checked')).toBe('true');

    switchOf('platform-signup-enabled').click();
    await fixture.whenStable();

    expect(facade.setSignup).toHaveBeenCalledWith('signup.enabled', true);
  });

  it('finds accounts from what the operator typed', async () => {
    const { fixture, query } = await render();

    const search = query<HTMLInputElement>('platform-account-search')!;
    search.value = 'acme';
    search.dispatchEvent(new Event('input'));
    query<HTMLButtonElement>('platform-account-find')!.click();
    await fixture.whenStable();

    expect(facade.findAccounts).toHaveBeenCalledWith('acme');
  });

  it('ends the sessions of an active account or deactivates it', async () => {
    const { fixture, query } = await render();

    const line = query('account-nadia@acme.test')!;
    expect(line.textContent).toContain('Nadia');
    expect(line.textContent).toContain('Actif');
    expect(query('reactivate-nadia@acme.test')).toBeNull();

    query<HTMLButtonElement>('end-sessions-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'end-sessions');

    query<HTMLButtonElement>('deactivate-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'deactivate');
  });

  it('only reactivates a deactivated account, which has no session left to end', async () => {
    accounts.set([{ ...account, active: false }]);

    const { fixture, query } = await render();

    expect(query('account-nadia@acme.test')!.textContent).toContain('Désactivé');
    expect(query('deactivate-nadia@acme.test')).toBeNull();
    expect(query('end-sessions-nadia@acme.test')).toBeNull();
    query<HTMLButtonElement>('reactivate-nadia@acme.test')!.click();
    await fixture.whenStable();
    expect(facade.actOnAccount).toHaveBeenCalledWith('u1', 'reactivate');
  });

  it('names a refusal', async () => {
    error.set('not_found');

    const { query } = await render();

    expect(query('platform-error')?.textContent).toContain('Introuvable');
  });
});
