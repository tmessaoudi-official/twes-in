// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, computed, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { ScreenAction } from '../actions/screen-action';
import { RecordBar } from './record-bar';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      form: {
        save: 'Save',
        revert: 'Discard changes',
        unsaved: '{{count}} unsaved changes',
        one_unsaved: '1 unsaved change',
      },
      danger: {
        title: 'Sure?',
        message: 'Really?',
        run: 'Do it',
        keep: 'Keep',
      },
    });
  }
}

/**
 * The host declares what the bar draws, exactly as a record page does: one list, which also reaches the keyboard,
 * the palette and the "?" sheet. The bar decides only where each one is drawn.
 */
@Component({
  imports: [RecordBar],
  template: `<app-record-bar [changes]="changes()" [actions]="actions()" />`,
})
class Host {
  readonly changes = signal(0);
  readonly busy = signal(false);
  readonly did: string[] = [];
  readonly actions = computed<ScreenAction[]>(() => {
    const busy = this.busy();
    const changes = this.changes();
    return [
      {
        id: 'save',
        label: 'form.save',
        icon: 'save',
        primary: true,
        shortcut: 's',
        disabled: busy || changes === 0,
        run: () => this.did.push('save'),
      },
      {
        id: 'revert',
        label: 'form.revert',
        disabled: busy,
        shown: changes > 0,
        run: () => this.did.push('revert'),
      },
      {
        id: 'danger',
        label: 'form.revert',
        shown: this.dangerous(),
        run: () => this.did.push('danger'),
        confirm: {
          title: 'danger.title',
          message: 'danger.message',
          confirmLabel: 'danger.run',
          keepLabel: 'danger.keep',
        },
      },
    ];
  });
  readonly dangerous = signal(false);
}

describe('RecordBar', () => {
  let fixture: ComponentFixture<Host>;

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideTranslateService({
          lang: 'en',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('offers nothing to save while nothing has changed', () => {
    // A save that is always available teaches nothing about whether there is anything to save.
    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
    expect(q('record-revert')).toBeNull();
    expect(q('record-changes')).toBeNull();
  });

  it('comes alive once something changed, and says how much is unsaved', async () => {
    fixture.componentInstance.changes.set(3);
    await settle();

    expect((q('record-save') as HTMLButtonElement).disabled).toBe(false);
    expect(q('record-changes')?.textContent).toContain('3 unsaved changes');
    expect(q('record-revert')).not.toBeNull();
  });

  it('counts one change in the singular, since "1 unsaved changes" is not a sentence', async () => {
    fixture.componentInstance.changes.set(1);
    await settle();
    expect(q('record-changes')?.textContent).toContain('1 unsaved change');
  });

  it('announces the count, which is the only thing saying a form was left half-filled', async () => {
    fixture.componentInstance.changes.set(2);
    await settle();
    expect(q('record-changes')?.getAttribute('role')).toBe('status');
  });

  it('saves and discards through what the page declared, which owns the form', async () => {
    fixture.componentInstance.changes.set(1);
    await settle();

    q('record-save')!.click();
    q('record-revert')!.click();
    expect(fixture.componentInstance.did).toEqual(['save', 'revert']);
  });

  it('refuses both while a save is in flight, without taking them off the screen', async () => {
    fixture.componentInstance.changes.set(1);
    fixture.componentInstance.busy.set(true);
    await settle();

    expect((q('record-save') as HTMLButtonElement).disabled).toBe(true);
    expect((q('record-revert') as HTMLButtonElement).disabled).toBe(true);
    expect(q('record-changes')).not.toBeNull();
  });

  it('draws the next step last, where the hand ends up, and reads its label from the declaration', async () => {
    fixture.componentInstance.changes.set(1);
    await settle();

    const ids = [...fixture.nativeElement.querySelectorAll('button[data-testid^="record-"]')].map(
      (button: HTMLElement) => button.getAttribute('data-testid'),
    );
    expect(ids).toEqual(['record-revert', 'record-save']);
    expect(q('record-save')?.textContent).toContain('Save');
    expect(q('record-revert')?.textContent).toContain('Discard changes');
  });

  it('draws whatever the page declared, not a fixed pair', async () => {
    // The bar is not a save button with a count beside it: it is where a record page's declaration is drawn, so a
    // page that offers a third thing gets it here as well as in the palette.
    fixture.componentInstance.dangerous.set(true);
    await settle();
    expect(q('record-danger')).not.toBeNull();
  });

  it('asks before running what the declaration says to ask about', async () => {
    fixture.componentInstance.dangerous.set(true);
    await settle();

    q('record-danger')!.click();
    await settle();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      'Really?',
    );
    expect(fixture.componentInstance.did).toEqual([]);

    (document.querySelector('[data-testid="confirm-run"]') as HTMLElement).click();
    await settle();
    expect(fixture.componentInstance.did).toEqual(['danger']);
  });
});
