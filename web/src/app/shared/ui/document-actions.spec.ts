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
import { DocumentActions } from './document-actions';
import type { PlannedAction } from '../actions/planned-actions';
import type { ScreenAction } from '../actions/screen-action';
import { Session, type SessionState } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { WINDOW_CLASS, type WindowClass } from './window-class';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      document: { actions: 'Actions', more_actions: 'More actions' },
      actions: { final_group: 'Final', coming: 'Coming to this screen' },
      shell: { soon: 'Soon' },
      p: { email: 'Send by e-mail', repeat: 'Make recurring' },
      d: {
        save: 'Save draft',
        issue: 'Issue',
        pdf: 'PDF',
        duplicate: 'Duplicate',
        cancel: 'Cancel',
        cancel_title: 'Cancel this invoice?',
        cancel_message: 'A cancelled invoice cannot be issued again.',
        cancel_confirm: 'Cancel the invoice',
        keep: 'Keep it',
      },
    });
  }
}

@Component({ selector: 'app-blank', template: '' })
class Blank {}

@Component({
  imports: [DocumentActions],
  template: `<app-document-actions [actions]="actions()" [planned]="planned()" />`,
})
class Host {
  readonly actions = signal<ScreenAction[]>([]);
  readonly planned = signal<PlannedAction[]>([]);
}

describe('DocumentActions', () => {
  let fixture: ComponentFixture<Host>;
  const ran: string[] = [];
  const showComing = signal(true);
  const width = signal<WindowClass>('expanded');
  const me = signal<SessionState | null>(null);
  const email: PlannedAction = { module: 'mailing', label: 'p.email', icon: 'forward_to_inbox' };
  const repeat: PlannedAction = { module: 'recurring', label: 'p.repeat', icon: 'event_repeat' };

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`) as HTMLElement | null;

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  const issue: ScreenAction = {
    id: 'issue',
    label: 'd.issue',
    primary: true,
    run: () => ran.push('issue'),
  };
  const pdf: ScreenAction = { id: 'pdf', label: 'd.pdf', href: '/api/invoices/1/pdf' };
  const duplicate: ScreenAction = {
    id: 'duplicate',
    label: 'd.duplicate',
    run: () => ran.push('duplicate'),
  };
  const cancel: ScreenAction = {
    id: 'cancel',
    label: 'd.cancel',
    destructive: true,
    run: () => ran.push('cancel'),
    confirm: {
      kind: 'definitif',
      title: 'd.cancel_title',
      message: 'd.cancel_message',
      confirmLabel: 'd.cancel_confirm',
      keepLabel: 'd.keep',
    },
  };

  beforeEach(async () => {
    ran.length = 0;
    showComing.set(true);
    width.set('expanded');
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', countryCode: 'FR', currency: 'EUR' },
      plannedModules: [{ key: 'mailing', planned: 'v1' }],
    } as SessionState);
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([{ path: 'coming/:key', component: Blank }]),
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
        { provide: Session, useValue: { me } },
        { provide: ThemeFacade, useValue: { showComing } },
        { provide: WINDOW_CLASS, useValue: width },
      ],
    });
    fixture = TestBed.createComponent(Host);
    fixture.componentInstance.actions.set([issue, pdf, duplicate, cancel]);
    await settle();
  });

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  it('shows the next step and the frequent actions, and folds nothing else into the bar', () => {
    // Finding 3, measured: the next step sat below 2000 px of form. It is a visible button, not a menu entry.
    expect(q('document-action-issue')).not.toBeNull();
    expect(q('document-action-pdf')).not.toBeNull();
    expect(q('document-action-duplicate')).not.toBeNull();
  });

  it('draws the next step as the one filled button', () => {
    expect(q('document-action-issue')!.className).toContain('mat-mdc-unelevated-button');
    expect(q('document-action-duplicate')!.className).not.toContain('mat-mdc-unelevated-button');
  });

  it('keeps anything destructive out of the bar, whatever its frequency', () => {
    // A button under the pointer is the wrong place for cancelling an invoice.
    expect(q('document-action-cancel')).toBeNull();
    expect(q('document-more')).not.toBeNull();
  });

  it('opens the PDF in its own tab, as a real link', () => {
    const link = q('document-action-pdf')!;
    expect(link.tagName).toBe('A');
    expect(link.getAttribute('href')).toBe('/api/invoices/1/pdf');
    expect(link.getAttribute('target')).toBe('_blank');
    expect(link.getAttribute('rel')).toBe('noopener');
  });

  it('asks before running something destructive, and does nothing when the answer is no', async () => {
    q('document-more')!.click();
    await settle();
    q('document-menu-cancel')!.click();
    await settle();

    expect(q('confirm-message')?.textContent).toContain('cannot be issued again');
    q('confirm-keep')!.click();
    await settle();
    expect(ran).toEqual([]);
  });

  it('runs it once the answer is yes', async () => {
    q('document-more')!.click();
    await settle();
    q('document-menu-cancel')!.click();
    await settle();
    q('confirm-run')!.click();
    await settle();

    expect(ran).toEqual(['cancel']);
  });

  it('runs an action that asks nothing straight away', async () => {
    q('document-action-issue')!.click();
    await settle();
    expect(ran).toEqual(['issue']);
  });

  it('leaves out an action the state does not offer, and refuses one that cannot run for now', async () => {
    // Absent is "never here"; disabled is "not now" — a control that vanishes mid-save cannot be learnt.
    fixture.componentInstance.actions.set([
      { ...issue, disabled: true },
      { ...duplicate, shown: false },
    ]);
    await settle();

    expect((q('document-action-issue') as HTMLButtonElement).disabled).toBe(true);
    expect(q('document-action-duplicate')).toBeNull();
    expect(q('document-more')).toBeNull();
  });

  it('keeps what is final apart, last in the menu under its own heading (§ 7, 2026-09-25 22:17)', async () => {
    fixture.componentInstance.actions.set([issue, cancel, { ...duplicate, rare: true }]);
    await settle();
    q('document-more')!.click();
    await settle();

    const entries = [
      ...document.body.querySelectorAll<HTMLElement>(
        '[data-testid^="document-menu-"], [data-testid="document-menu-final"]',
      ),
    ].map((entry) => entry.getAttribute('data-testid'));
    expect(entries).toEqual([
      'document-menu-duplicate',
      'document-menu-final',
      'document-menu-cancel',
    ]);
    expect(q('document-menu-final')?.textContent).toContain('Final');
    expect(q('document-menu-cancel')?.getAttribute('data-kind')).toBe('definitif');
  });

  it('draws no final heading when nothing in the menu is final', async () => {
    fixture.componentInstance.actions.set([issue, { ...duplicate, rare: true }]);
    await settle();
    q('document-more')!.click();
    await settle();
    expect(q('document-menu-final')).toBeNull();
  });

  // Audit 2026-10-06 V-3, V-5 and V-29: the planned group, first in the bar, pushed the working actions onto a second
  // row on a desktop and drew a second « ⋯ » beside the « ⋮ » on a phone.
  it('lists what is coming last in its one menu, under its own heading, and draws none of it in the bar', async () => {
    fixture.componentInstance.actions.set([issue, cancel]);
    fixture.componentInstance.planned.set([email, repeat]);
    await settle();
    expect(q('planned-actions')).toBeNull();
    expect(document.body.querySelectorAll('[data-testid="document-more"]')).toHaveLength(1);

    q('document-more')!.click();
    await settle();
    const entries = [
      ...document.body.querySelectorAll<HTMLElement>(
        '[data-testid^="document-menu-"], [data-testid^="document-planned-"]',
      ),
    ].map((entry) => entry.getAttribute('data-testid'));
    // Only what the catalogue still plans: « recurring » is not planned for this session.
    expect(entries).toEqual([
      'document-menu-final',
      'document-menu-cancel',
      'document-planned-heading',
      'document-planned-mailing',
    ]);
    expect(q('document-planned-heading')?.textContent).toContain('Coming to this screen');
    expect(q('document-planned-mailing')?.textContent).toContain('Soon');

    q('document-planned-mailing')!.click();
    await settle();
    expect(TestBed.inject(Router).url).toBe('/coming/mailing');
  });

  it('opens a menu for what is coming alone, and leaves it out once what is coming is hidden', async () => {
    fixture.componentInstance.actions.set([issue]);
    fixture.componentInstance.planned.set([email]);
    await settle();
    expect(q('document-more')).not.toBeNull();

    showComing.set(false);
    await settle();
    expect(q('document-more')).toBeNull();
  });

  // Audit 2026-10-06 V-3: on a phone the bar wrapped into three rows, its « ⋮ » alone on one of them.
  it('keeps only the next step in the bar on a phone, the frequent actions first in its menu', async () => {
    width.set('compact');
    await settle();
    const bar = [...document.body.querySelectorAll('[data-testid^="document-action-"]')].map(
      (each) => each.getAttribute('data-testid'),
    );
    expect(bar).toEqual(['document-action-issue']);

    q('document-more')!.click();
    await settle();
    const menu = [...document.body.querySelectorAll('[data-testid^="document-menu-"]')].map(
      (each) => each.getAttribute('data-testid'),
    );
    expect(menu).toEqual([
      'document-menu-pdf',
      'document-menu-duplicate',
      'document-menu-final',
      'document-menu-cancel',
    ]);
  });

  it('keeps a save in the bar on a phone, drawn as its named icon beside the next step', async () => {
    const save: ScreenAction = {
      id: 'save',
      label: 'd.save',
      icon: 'save',
      keep: true,
      run: () => ran.push('save'),
    };
    fixture.componentInstance.actions.set([save, issue, pdf, duplicate, cancel]);
    width.set('compact');
    await settle();
    const bar = [...document.body.querySelectorAll('[data-testid^="document-action-"]')].map(
      (each) => each.getAttribute('data-testid'),
    );

    expect(bar).toEqual(['document-action-save', 'document-action-issue']);
    expect(q('document-action-save')!.getAttribute('aria-label')).toBe('Save draft');
    expect(q('document-action-save')!.textContent?.trim()).toBe('save');

    q('document-action-save')!.click();
    expect(ran).toEqual(['save']);
  });

  it('keeps a save in the bar on a phone when there is no next step yet, as on a new document', async () => {
    fixture.componentInstance.actions.set([
      { id: 'save', label: 'd.save', icon: 'save', keep: true, run: () => ran.push('save') },
    ]);
    width.set('compact');
    await settle();

    // In words, the page's one action: as a bare icon it was the least visible thing in its row (sweep, row 244).
    expect(q('document-action-save')).not.toBeNull();
    expect(q('document-action-save')!.textContent?.replace(/\s+/g, ' ').trim()).toBe(
      'save Save draft',
    );
  });

  it('names the "⋮" it draws', () => {
    expect(q('document-more')!.getAttribute('aria-label')).toBe('More actions');
  });
});
