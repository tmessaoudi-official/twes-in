// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { Session } from '../shared/session/session';
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
      },
    });
  }
}

@Component({ template: '' })
class Blank {}

describe('SettingsArea', () => {
  const permissions = signal<readonly string[]>([]);
  const auth = {
    hasPermission: (permission: string) => permissions().includes(permission),
    hasModule: () => true,
  };

  beforeEach(async () => {
    permissions.set(['company.settings', 'fiscal.read', 'user.read']);
    await TestBed.configureTestingModule({
      imports: [SettingsArea],
      providers: [
        provideRouter([
          { path: 'company', component: Blank },
          { path: 'members', component: Blank },
        ]),
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
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
        return [
          heading?.id,
          heading?.textContent?.trim(),
          [...list.querySelectorAll('a')].map((a) => a.getAttribute('data-testid')),
        ];
      });
    return { fixture, el, byTestId, groups };
  }

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
      ['settings-section-team', 'Équipe', ['nav-members']],
      ['settings-section-customisation', 'Personnalisation', ['nav-custom-fields', 'nav-modules']],
    ]);
    expect(byTestId('nav-members')?.getAttribute('href')).toBe('/members');
    expect(el.querySelector('router-outlet')).not.toBeNull();
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
    permissions.set(['user.read']);
    const { groups } = await render();
    expect(groups()).toEqual([['settings-section-team', 'Équipe', ['nav-members']]]);
  });
});
