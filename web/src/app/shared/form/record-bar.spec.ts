// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, computed, signal } from '@angular/core';
import { type ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideRouter, Router } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import type { PlannedAction } from '../actions/planned-actions';
import type { ScreenAction } from '../actions/screen-action';
import { Session, type SessionState } from '../session/session';
import { ThemeFacade } from '../theme/theme-facade';
import { WINDOW_CLASS, type WindowClass } from '../ui/window-class';
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
      actions: { coming: 'Coming to this screen' },
      document: { more_actions: 'More actions' },
      shell: { soon: 'Soon' },
      p: { quote: 'New quote' },
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
@Component({ selector: 'app-blank', template: '' })
class Blank {}

@Component({
  imports: [RecordBar],
  template: `<app-record-bar [changes]="changes()" [actions]="actions()" [planned]="planned()" />`,
})
class Host {
  readonly planned = signal<PlannedAction[]>([]);
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
          kind: 'definitif',
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

/** The bar where a record page draws it: beside a title, in the document header it measures. */
@Component({
  imports: [RecordBar],
  template: `<div class="twes-document-header">
    <h1>FRN-001 · A vendor with a long name</h1>
    <div><app-record-bar [changes]="0" [actions]="[]" [planned]="planned()" /></div>
  </div>`,
})
class HeaderHost {
  readonly planned = signal<PlannedAction[]>([]);
}

describe('RecordBar', () => {
  let fixture: ComponentFixture<Host>;
  const width = signal<WindowClass>('expanded');
  const showComing = signal(true);
  const me = signal<SessionState | null>(null);
  const quote: PlannedAction = { module: 'quotes', label: 'p.quote', icon: 'request_quote' };
  const inMenu = (prefix: string) =>
    [...document.body.querySelectorAll(`.cdk-overlay-container [data-testid^="${prefix}"]`)].map(
      (each) => each.getAttribute('data-testid'),
    );

  const q = (testId: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  }

  beforeEach(async () => {
    width.set('expanded');
    showComing.set(true);
    me.set({
      user: { id: 'u1' },
      company: { id: 'c1', countryCode: 'FR', currency: 'EUR' },
      plannedModules: [{ key: 'quotes', planned: 'v1' }],
    } as SessionState);
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [
        provideRouter([{ path: 'coming/:key', component: Blank }]),
        { provide: Session, useValue: { me } },
        { provide: ThemeFacade, useValue: { showComing } },
        { provide: WINDOW_CLASS, useValue: width },
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

  afterEach(() => {
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  // Audit 2026-10-06 V-12: on a phone a product's bar stacked its actions one per row before any content.
  it('keeps the next step in the bar on a phone and folds the others into its « ⋮ »', async () => {
    fixture.componentInstance.changes.set(2);
    width.set('compact');
    await settle();
    expect(q('record-save')).not.toBeNull();
    expect(q('record-revert')).toBeNull();

    q('record-more')!.click();
    await settle();
    expect(inMenu('record-menu-')).toEqual(['record-menu-revert']);
    (document.querySelector('[data-testid="record-menu-revert"]') as HTMLElement).click();
    await settle();
    expect(fixture.componentInstance.did).toEqual(['revert']);
  });

  it('draws no « ⋮ » on a wide window with nothing to fold', () => {
    expect(q('record-more')).toBeNull();
  });

  // Audit 2026-10-06 V-3 and V-12: what is coming sits last in the one menu, never as a group in the bar.
  it('lists what is coming in its « ⋮ », under its own heading, and opens its page', async () => {
    fixture.componentInstance.planned.set([quote]);
    await settle();
    expect(q('planned-actions')).toBeNull();

    q('record-more')!.click();
    await settle();
    expect(inMenu('record-planned-')).toEqual(['record-planned-heading', 'record-planned-quotes']);
    expect(document.querySelector('[data-testid="record-planned-quotes"]')?.textContent).toContain(
      'Soon',
    );
    (document.querySelector('[data-testid="record-planned-quotes"]') as HTMLElement).click();
    await settle();
    expect(TestBed.inject(Router).url).toBe('/coming/quotes');

    showComing.set(false);
    await settle();
    expect(q('record-more')).toBeNull();
  });

  describe('in a header it can measure', () => {
    const realObserver = globalThis.ResizeObserver;
    const realWidth = Object.getOwnPropertyDescriptor(Element.prototype, 'clientWidth')!;
    const realRect = Element.prototype.getBoundingClientRect;
    const measures: (() => void)[] = [];
    let measured: ComponentFixture<HeaderHost>;

    beforeEach(() => {
      // A vendor with a long name, as a browser drew it: a 960 px header, a 595 px title, 332.3 px of working
      // buttons, the 345.3 px « Bientôt » button and a 40 px « ⋮ », each after an 8 px gap. The row is as wide as
      // what is drawn in it now, so showing the planned button and emptying « ⋮ » both move it.
      measures.length = 0;
      globalThis.ResizeObserver = class {
        constructor(callback: () => void) {
          measures.push(callback);
        }
        observe(): void {
          // Measured again when a case says the layout moved.
        }
        disconnect(): void {
          // Nothing to let go of.
        }
        unobserve(): void {
          // Nothing to let go of.
        }
      } as unknown as typeof ResizeObserver;
      Object.defineProperty(Element.prototype, 'clientWidth', {
        configurable: true,
        get(this: Element) {
          return this.classList.contains('twes-document-header') ? 960 : 0;
        },
      });
      Element.prototype.getBoundingClientRect = function (this: Element) {
        if (this.tagName === 'H1') return { width: 595 } as DOMRect;
        if (this.hasAttribute('data-planned')) return { width: 345.3 } as DOMRect;
        if (this.hasAttribute('data-bar-more')) return { width: 40 } as DOMRect;
        const planned = this.querySelector('[data-planned]');
        const shown = planned !== null && !planned.classList.contains('twes-planned-measure');
        const more = this.querySelector('[data-testid="record-more"]') !== null;
        return { width: 332.3 + (shown ? 353.3 : 0) + (more ? 48 : 0) } as DOMRect;
      };
    });

    afterEach(() => {
      globalThis.ResizeObserver = realObserver;
      Object.defineProperty(Element.prototype, 'clientWidth', realWidth);
      Element.prototype.getBoundingClientRect = realRect;
    });

    it('keeps the planned button shown once it is, though « ⋮ » leaves the row with it', async () => {
      measured = TestBed.createComponent(HeaderHost);
      measured.componentInstance.planned.set([quote]);
      for (let pass = 0; pass < 4; pass++) {
        measured.detectChanges();
        await measured.whenStable();
        measured.detectChanges();
        // Each layout the bar draws resizes its row, and the row is watched: it is measured again.
        measures.forEach((measure) => measure());
      }
      const planned = (measured.nativeElement as HTMLElement).querySelector(
        '[data-testid="planned-action-quotes"]',
      )!;
      expect(planned.classList).not.toContain('twes-planned-measure');
      expect(
        (measured.nativeElement as HTMLElement).querySelector('[data-testid="record-more"]'),
      ).toBeNull();
    });
  });
});
