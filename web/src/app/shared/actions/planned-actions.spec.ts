// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { Session, type SessionState } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { WINDOW_CLASS, type WindowClass } from '../ui/window-class';
import { type PlannedAction, PlannedActions } from './planned-actions';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      shell: { soon: 'Bientôt' },
      actions: { coming: 'Bientôt sur cet écran', coming_more: 'Ce qui arrive ici' },
      p: {
        email: 'Envoyer par e-mail',
        whatsapp: 'Envoyer par WhatsApp',
        repeat: 'Rendre récurrente',
      },
    });
  }
}

@Component({
  imports: [PlannedActions],
  template: `<app-planned-actions [actions]="actions()" />`,
})
class Host {
  readonly actions = signal<readonly PlannedAction[]>([]);
}

// docs/SPEC.md § 7, 2026-09-26 10:08 (row 150, slice 5): what a screen will offer once its module ships, beside what
// it offers today, marked, focusable, and opening the module's « En construction » page.
describe('PlannedActions', () => {
  let fixture: ComponentFixture<Host>;
  const showComing = signal(true);
  const width = signal<WindowClass>('expanded');
  const me = signal<SessionState | null>(null);

  const email: PlannedAction = { module: 'mailing', label: 'p.email', icon: 'forward_to_inbox' };
  const whatsapp: PlannedAction = { module: 'whatsapp', label: 'p.whatsapp', icon: 'chat' };
  const repeat: PlannedAction = { module: 'recurring', label: 'p.repeat', icon: 'event_repeat' };

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    showComing.set(true);
    width.set('expanded');
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', countryCode: 'FR', currency: 'EUR' },
      plannedModules: [
        { key: 'mailing', planned: 'v1' },
        { key: 'recurring', planned: 'later' },
      ],
    });
    await TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([]),
        provideTranslateService({ fallbackLang: 'fr', lang: 'fr' }),
        provideTranslateLoader(StaticLoader),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: Session, useValue: { me } },
        { provide: ThemeFacade, useValue: { showComing } },
        { provide: WINDOW_CLASS, useValue: width },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(Host);
    fixture.componentInstance.actions.set([email, whatsapp, repeat]);
    await settle();
  });

  afterEach(() => fixture.destroy());

  it('draws what the API lists as planned, in the order the screen declared, and nothing else', () => {
    const drawn = [...document.body.querySelectorAll('[data-testid^="planned-action-"]')];
    expect(drawn.map((each) => each.getAttribute('data-testid'))).toEqual([
      'planned-action-mailing',
      'planned-action-recurring',
    ]);
    expect(q('planned-action-whatsapp')).toBeNull();
    expect(q('planned-actions')?.getAttribute('aria-label')).toBe('Bientôt sur cet écran');
  });

  it('marks each as not working yet while keeping it within the keyboard’s reach', () => {
    const button = q('planned-action-mailing') as HTMLButtonElement;
    expect(button.getAttribute('aria-disabled')).toBe('true');
    expect(button.disabled).toBe(false);
    expect(button.classList).toContain('twes-planned-action');
    expect(button.querySelector('[data-testid="soon"]')?.textContent?.trim()).toBe('Bientôt');
    expect(button.textContent).toContain('Envoyer par e-mail');
    button.focus();
    expect(document.activeElement).toBe(button);
  });

  it('opens the module’s « En construction » page and runs nothing', async () => {
    const go = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    q('planned-action-recurring')?.click();
    await settle();
    expect(go).toHaveBeenCalledWith('/coming/recurring');
  });

  it('draws nothing, not even its divider, when « Montrer ce qui arrive » is off or nothing is planned', async () => {
    showComing.set(false);
    await settle();
    expect(q('planned-actions')).toBeNull();

    showComing.set(true);
    fixture.componentInstance.actions.set([whatsapp]);
    await settle();
    expect(q('planned-actions')).toBeNull();

    me.set(null);
    fixture.componentInstance.actions.set([email]);
    await settle();
    expect(q('planned-actions')).toBeNull();
  });

  it('folds into its own « ⋯ » on a phone, each still marked', async () => {
    width.set('compact');
    await settle();
    expect(q('planned-action-mailing')).toBeNull();
    const more = q('planned-more') as HTMLButtonElement;
    expect(more.getAttribute('aria-label')).toBe('Ce qui arrive ici');
    expect(more.textContent).toContain('more_horiz');

    more.click();
    await settle();
    const item = q('planned-menu-mailing');
    expect(item?.querySelector('[data-testid="soon"]')?.textContent?.trim()).toBe('Bientôt');
    expect(q('planned-menu-recurring')).not.toBeNull();

    const go = vi.spyOn(TestBed.inject(Router), 'navigateByUrl').mockResolvedValue(true);
    item?.click();
    await settle();
    expect(go).toHaveBeenCalledWith('/coming/mailing');
  });
});
