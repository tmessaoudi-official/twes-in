// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import { PRESENTATION } from '../shared/settings/settings-registry';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
import { ThemeFacade } from '../shared/theme/theme-facade';
import { SettingsArea } from './settings-area';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      nav: {
        members: 'Membres',
        taxes: 'Taxes',
        units: 'Unités',
        settings: 'Valeurs par défaut',
        company_profile: 'Profil de la société',
        company_security: 'Sécurité',
        establishments: 'Établissements',
        numbering: 'Numérotation',
        custom_fields: 'Champs personnalisés',
        modules: 'Modules',
        document_templates: 'Modèles de documents',
        alerts: 'Alertes',
        fiscal_preset: 'Préréglage fiscal',
        support_access: 'Accès du support',
        texts: 'Textes',
        sections: {
          company: 'Société',
          fiscal: 'Fiscalité',
          team: 'Équipe',
          customisation: 'Personnalisation',
        },
      },
      shell: {
        navigation: 'Navigation principale',
        settings_navigation: 'Paramètres de la société',
        settings: 'Paramètres',
        settings_filter: 'Filtrer les réglages',
        settings_back: 'Tous les paramètres',
        settings_none: 'Aucun réglage ne correspond.',
        soon: 'Bientôt',
      },
    });
  }
}

@Component({ template: '' })
class Blank {}

describe('SettingsArea', () => {
  const permissions = signal<readonly string[]>([]);
  const auth = {
    // Nobody's choices beyond this page's own memory: the settings read the session to know whose they are.
    me: signal(null),
    hasPermission: (permission: string) => permissions().includes(permission),
    hasModule: () => true,
  };
  // What is not built yet is off here, so the other cases read only the settings that work.
  const showComing = signal(false);

  beforeEach(async () => {
    permissions.set(['company.settings', 'fiscal.read', 'user.read']);
    showComing.set(false);
    await TestBed.configureTestingModule({
      imports: [SettingsArea],
      providers: [
        provideRouter([
          { path: 'company', component: Blank },
          { path: 'members', component: Blank },
        ]),
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
        { provide: ThemeFacade, useValue: { showComing } },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
      ],
    }).compileComponents();
  });

  async function render() {
    const fixture = TestBed.createComponent(SettingsArea);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    const byTestId = (id: string) => el.querySelector<HTMLElement>(`[data-testid="${id}"]`);
    const groups = () =>
      [...el.querySelectorAll<HTMLElement>('mat-nav-list')].map((list) => {
        const heading = el.querySelector(`#${list.getAttribute('aria-labelledby')}`);
        // What a screen reader hears: the fold chevron beside the name is hidden from it (row 152).
        const spoken = heading?.cloneNode(true) as Element | undefined;
        spoken?.querySelectorAll('[aria-hidden="true"]').forEach((hidden) => hidden.remove());
        return [
          heading?.id,
          spoken?.textContent?.trim(),
          [...list.querySelectorAll('a')].map((a) => a.getAttribute('data-testid')),
        ];
      });
    return { fixture, el, byTestId, groups };
  }

  it('lists the settings not built yet among the others, marked « Bientôt », while what is coming shows', async () => {
    // docs/SPEC.md § 7, 2026-09-25 17:22 and the round-6 settings board.
    showComing.set(true);
    const { byTestId, groups } = await render();
    expect(groups().map(([, , entries]) => entries)).toEqual([
      [
        'nav-company-profile',
        'nav-company-security',
        'nav-establishments',
        'nav-numbering',
        'nav-document-templates',
        'nav-settings',
        'nav-alerts',
      ],
      ['nav-taxes', 'nav-units', 'nav-fiscal-preset'],
      ['nav-members', 'nav-roles', 'nav-support-access'],
      ['nav-custom-fields', 'nav-modules', 'nav-texts'],
    ]);
    const templates = byTestId('nav-document-templates');
    expect(templates?.getAttribute('href')).toBe('/company/coming/document-templates');
    expect(templates?.querySelector('[data-testid="soon"]')?.textContent).toContain('Bientôt');
    expect(byTestId('nav-numbering')?.querySelector('[data-testid="soon"]')).toBeNull();
  });

  // docs/SPEC.md § 7, 2026-09-26 12:05 (row 152): the Paramètres list folds by section like the main menu.
  it('folds a section from its heading, and keeps it folded for the person', async () => {
    const { fixture, byTestId } = await render();
    const fold = byTestId('settings-fold-team')!;
    expect(fold.closest('h2')?.id).toBe('settings-section-team');
    expect(fold.getAttribute('aria-expanded')).toBe('true');
    expect(fold.getAttribute('aria-controls')).toBe('settings-list-team');

    fold.click();
    await fixture.whenStable();
    expect(fold.getAttribute('aria-expanded')).toBe('false');
    expect(
      (byTestId('settings-nav')?.querySelector('#settings-list-team') as HTMLElement).hidden,
    ).toBe(true);
    expect(
      (byTestId('settings-nav')?.querySelector('#settings-list-fiscal') as HTMLElement).hidden,
    ).toBe(false);
    expect(TestBed.inject(SettingsFacade).value(PRESENTATION.foldedSections)()).toEqual([
      'settings.team',
    ]);
  });

  it('opens a folded section when the page on view is in it', async () => {
    TestBed.inject(SettingsFacade).set(PRESENTATION.foldedSections, ['settings.team']);
    const { fixture, byTestId } = await render();
    const team = () =>
      byTestId('settings-nav')?.querySelector('#settings-list-team') as HTMLElement;
    expect(team().hidden).toBe(true);

    await TestBed.inject(Router).navigateByUrl('/members');
    await fixture.whenStable();
    expect(team().hidden).toBe(false);
  });

  it('groups the settings in one navigation of their own, beside the page', async () => {
    const { el, byTestId, groups } = await render();

    const nav = byTestId('settings-nav');
    expect(nav?.tagName).toBe('NAV');
    expect(nav?.getAttribute('aria-label')).toBe('Paramètres de la société');
    expect(groups()).toEqual([
      [
        'settings-section-company',
        'Société',
        [
          'nav-company-profile',
          'nav-company-security',
          'nav-establishments',
          'nav-numbering',
          'nav-settings',
        ],
      ],
      ['settings-section-fiscal', 'Fiscalité', ['nav-taxes', 'nav-units']],
      ['settings-section-team', 'Équipe', ['nav-members', 'nav-roles']],
      ['settings-section-customisation', 'Personnalisation', ['nav-custom-fields', 'nav-modules']],
    ]);
    expect(byTestId('nav-members')?.getAttribute('href')).toBe('/members');
    expect(el.querySelector('router-outlet')).not.toBeNull();
  });

  it('docks the list against the rail instead of floating it as a card', async () => {
    // Design review finding 7, measured: the list floated as a bordered card beside a full app menu and cut the
    // tables beside it. It is now one surface with the rail — a full-height column, no rounding, no gap — and the
    // page beside it keeps its own gutter, which main no longer supplies here.
    const { byTestId, el } = await render();

    const nav = byTestId('settings-nav');
    const classes = (nav?.className ?? '').split(/\s+/);
    expect(classes).not.toContain('rounded-xl');
    // A card is a box on all four sides; a dock is one edge. `border` alone is the box, `border-r` the edge.
    expect(classes).not.toContain('border');
    expect(classes).toContain('lg:border-r');
    expect(nav?.className).toMatch(/min-h-/);

    const row = el.querySelector('[data-testid="settings-area"]');
    expect(row?.className).not.toMatch(/gap-8/);
    expect(el.querySelector('[data-testid="settings-page"]')?.className).toMatch(/p-4|p-6/);
  });

  it('filters the settings by name, whatever the accents and the case, leaving out empty groups', async () => {
    const { fixture, el, byTestId, groups } = await render();
    const filter = byTestId('settings-filter') as HTMLInputElement;
    expect(filter.getAttribute('aria-label') ?? filter.labels?.[0]?.textContent).toContain(
      'Filtrer les réglages',
    );

    filter.value = 'PROFIL SOCIETE';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    await fixture.whenStable();
    expect(groups()).toEqual([['settings-section-company', 'Société', ['nav-company-profile']]]);

    // A group's name finds every setting in it.
    filter.value = 'fiscalite';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(groups()).toEqual([
      ['settings-section-fiscal', 'Fiscalité', ['nav-taxes', 'nav-units']],
    ]);

    filter.value = 'zzz';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    expect(groups()).toEqual([]);
    expect(el.textContent).toContain('Aucun réglage ne correspond.');
  });

  it('lists the sections alone at /company, and offers the way back to them from a page', async () => {
    const router = TestBed.inject(Router);
    await router.navigateByUrl('/company');
    const index = await render();
    expect(index.byTestId('settings-nav')?.getAttribute('data-at-index')).toBe('true');
    expect(index.byTestId('settings-back')).toBeNull();
    index.fixture.destroy();

    await router.navigateByUrl('/members');
    const page = await render();
    expect(page.byTestId('settings-nav')?.getAttribute('data-at-index')).toBe('false');
    expect(page.byTestId('settings-back')?.getAttribute('href')).toBe('/company');
    expect(page.byTestId('settings-back')?.textContent).toContain('Tous les paramètres');
  });

  it('shows a member only the settings they may open', async () => {
    // user.read opens the member list and nothing else: the roles entry sits in the same group but needs
    // company.settings, so a member sees the group with one entry in it.
    permissions.set(['user.read']);
    const { groups } = await render();
    expect(groups()).toEqual([['settings-section-team', 'Équipe', ['nav-members']]]);
  });
});
