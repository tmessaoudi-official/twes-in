// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import type { PlannedModule } from '../auth/auth-types';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { ComingPage } from './coming-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      nav: { sections: { sell: 'Vendre' } },
      modules: { register: 'Caisse', works: 'Travaux', venue: 'Salle' },
      shell: { soon: 'Bientôt' },
      coming: {
        version: { v1: 'Version 1', later: 'Plus tard' },
        planned: 'Prévu pour',
        plan_row: 'Ligne du plan',
        home: 'Retour à l’accueil',
        hide: 'Masquer ce qui arrive',
        unknown: 'Rien n’est prévu à cette adresse.',
        register: {
          heading: 'La caisse est en construction',
          does: 'La vente au comptoir sur un seul écran.',
          plan: '§ 8 · 82 — la vente au comptoir',
          meanwhile: 'En attendant, une vente au comptoir se fait depuis une facture :',
          meanwhile_link: 'Émettre et encaisser',
        },
        works: {
          heading: 'Les travaux sont en construction',
          does: 'Les chantiers.',
          plan: '§ 8 · 80',
        },
        venue: {
          heading: 'La salle est en construction',
          does: 'Le plan de la salle.',
        },
      },
    });
  }
}

// docs/SPEC.md § 7, 2026-09-25 17:22: every entry not built yet opens one shared page saying what it will do, when,
// the plan row that builds it and what to use meanwhile.
describe('ComingPage', () => {
  const showComing = signal(true);
  const theme = { showComing, setShowComing: vi.fn((show: boolean) => showComing.set(show)) };
  // The API's catalogue (row 150): the planned modules the signed-in state names.
  const planned = signal<readonly PlannedModule[]>([
    { key: 'register', planned: 'v1' },
    { key: 'works', planned: 'v1' },
    { key: 'venue', planned: 'later' },
  ]);
  const auth = { me: () => ({ plannedModules: planned() }) };
  let fixture: ComponentFixture<ComingPage>;

  async function render(key: string): Promise<HTMLElement> {
    await TestBed.configureTestingModule({
      imports: [ComingPage],
      providers: [
        provideRouter([]),
        provideTranslateService({
          fallbackLang: 'fr',
          loader: provideTranslateLoader(StaticLoader),
        }),
        { provide: ThemeFacade, useValue: theme },
        { provide: AuthFacade, useValue: auth },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(ComingPage);
    fixture.componentRef.setInput('key', key);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const byTestId = (root: HTMLElement, id: string) =>
    root.querySelector<HTMLElement>(`[data-testid="${id}"]`);

  beforeEach(() => {
    showComing.set(true);
    theme.setShowComing.mockClear();
    planned.set([
      { key: 'register', planned: 'v1' },
      { key: 'works', planned: 'v1' },
      { key: 'venue', planned: 'later' },
    ]);
  });

  it('says what the entry will do, for which version, the plan row and what to use meanwhile', async () => {
    const root = await render('register');
    expect(byTestId(root, 'coming-heading')?.textContent).toContain(
      'La caisse est en construction',
    );
    expect(byTestId(root, 'coming-crumb')?.textContent).toContain('Vendre');
    expect(byTestId(root, 'coming-crumb')?.textContent).toContain('Bientôt');
    expect(byTestId(root, 'coming-does')?.textContent).toContain('La vente au comptoir');
    expect(byTestId(root, 'coming-version')?.textContent).toContain('Version 1');
    expect(byTestId(root, 'coming-plan')?.textContent).toContain('§ 8 · 82');
    const meanwhile = byTestId(root, 'coming-meanwhile');
    expect(meanwhile?.textContent).toContain('En attendant');
    expect(meanwhile?.querySelector('a')?.getAttribute('href')).toBe('/invoices/new');
    expect(meanwhile?.querySelector('a')?.textContent).toContain('Émettre et encaisser');
  });

  it('draws no « En attendant » when nothing does the job today', async () => {
    const root = await render('works');
    expect(byTestId(root, 'coming-heading')?.textContent).toContain('Les travaux');
    expect(byTestId(root, 'coming-meanwhile')).toBeNull();
  });

  it('hides what is coming for this person and goes home', async () => {
    const root = await render('register');
    const navigate = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    byTestId(root, 'coming-hide')?.click();
    expect(theme.setShowComing).toHaveBeenCalledWith(false);
    expect(navigate).toHaveBeenCalledWith('/');
  });

  it('names no plan row for a planned module no § 8 row builds yet, and gives the version the API says', async () => {
    const root = await render('venue');
    expect(byTestId(root, 'coming-heading')?.textContent).toContain('La salle');
    expect(byTestId(root, 'coming-version')?.textContent).toContain('Plus tard');
    expect(byTestId(root, 'coming-plan')).toBeNull();
    expect(root.textContent).not.toContain('Ligne du plan');
  });

  it('says nothing is planned once the API no longer lists the module as planned', async () => {
    planned.set([{ key: 'works', planned: 'v1' }]);
    const root = await render('register');
    expect(byTestId(root, 'coming-unknown')).not.toBeNull();
  });

  it('says nothing is planned at an address no entry names', async () => {
    const root = await render('nothing');
    expect(byTestId(root, 'coming-unknown')?.textContent).toContain('Rien n’est prévu');
    expect(byTestId(root, 'coming-heading')).toBeNull();
  });
});
