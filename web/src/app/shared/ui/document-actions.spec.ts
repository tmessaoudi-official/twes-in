// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { DocumentActions } from './document-actions';
import type { ScreenAction } from '../actions/screen-action';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      document: { actions: 'Actions', more_actions: 'More actions' },
      actions: { final_group: 'Final' },
      d: {
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

@Component({
  imports: [DocumentActions],
  template: `<app-document-actions [actions]="actions()" />`,
})
class Host {
  readonly actions = signal<ScreenAction[]>([]);
}

describe('DocumentActions', () => {
  let fixture: ComponentFixture<Host>;
  const ran: string[] = [];

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
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([]),
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
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

  it('names the "⋮" it draws', () => {
    expect(q('document-more')!.getAttribute('aria-label')).toBe('More actions');
  });
});
