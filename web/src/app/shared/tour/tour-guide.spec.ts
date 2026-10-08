// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter, Router, RouterOutlet } from '@angular/router';
import { provideTranslateService } from '@ngx-translate/core';
import { RouteFocus } from '../a11y/route-focus';
import type { Tour } from './tour';
import { TourGuide } from './tour-guide';

@Component({
  selector: 'app-tour-list',
  template: `
    <h1>Factures</h1>
    <input data-tour="list-search" hidden />
    <input data-tour="list-search" data-testid="shown-search" />
  `,
})
class ListPage {}

@Component({
  selector: 'app-tour-form',
  template: `<h1>Nouvelle facture</h1>
    <form data-tour="form" data-testid="the-form"></form>`,
})
class FormPage {}

@Component({
  imports: [RouterOutlet, RouteFocus],
  template: `<button type="button" data-testid="started-here">Aide</button>
    <main appRouteFocus tabindex="-1"><router-outlet /></main>`,
})
class Shell {}

const tour: Tour = {
  key: 'first',
  titleKey: 't.title',
  commandKey: 't.command',
  steps: [
    { anchor: 'list-search', titleKey: 't.search', bodyKey: 't.search_body', route: '/list' },
    { anchor: 'form', titleKey: 't.form', bodyKey: 't.form_body', route: '/form' },
    { anchor: 'record-bar', titleKey: 't.bar', bodyKey: 't.bar_body' },
  ],
};

describe('TourGuide', () => {
  let fixture: ReturnType<typeof TestBed.createComponent<Shell>>;
  let guide: TourGuide;

  const q = (testId: string): HTMLElement | null =>
    document.body.querySelector(`[data-testid="${testId}"]`);

  async function settle(): Promise<void> {
    await fixture.whenStable();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await fixture.whenStable();
  }

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideRouter([
          { path: 'list', component: ListPage },
          { path: 'form', component: FormPage, canDeactivate: [() => leaving] },
        ]),
        provideTranslateService({ lang: 'fr' }),
      ],
    });
    leaving = true;
    fixture = TestBed.createComponent(Shell);
    guide = TestBed.inject(TourGuide);
    await TestBed.inject(Router).navigateByUrl('/list');
    await settle();
    q('started-here')!.focus();
  });

  afterEach(() => {
    guide.end();
    document.body.querySelectorAll('.cdk-overlay-container').forEach((overlay) => overlay.remove());
  });

  let leaving = true;

  it('points at the anchor that is on screen, when another layout hides one of the same name', async () => {
    await guide.start(tour);
    await settle();

    expect(q('shown-search')!.hasAttribute('data-tour-active')).toBe(true);
    expect(q('tour-card')).not.toBeNull();
    expect(q('tour-missing')).toBeNull();
  });

  it('opens the page a step stands on, and leaves the focus on the card rather than on the heading', async () => {
    await guide.start(tour);
    await guide.next();
    await settle();

    expect(TestBed.inject(Router).url).toBe('/form');
    expect(q('the-form')!.hasAttribute('data-tour-active')).toBe(true);
    expect(q('tour-card')!.contains(document.activeElement)).toBe(true);
  });

  it('stays on its step when the page refuses to be left, as one with unsaved changes does', async () => {
    await guide.start(tour);
    await guide.next();
    leaving = false;
    await guide.back();
    await settle();

    expect(guide.index()).toBe(1);
    expect(TestBed.inject(Router).url).toBe('/form');
  });

  it('says a step cannot be shown here rather than skipping it', async () => {
    await guide.start(tour);
    await guide.next();
    await guide.next();
    await settle();

    expect(guide.index()).toBe(2);
    expect(q('tour-missing')).not.toBeNull();
  });

  it('ends on Escape, unmarks what it pointed at and gives the focus back where the tour began', async () => {
    await guide.start(tour);
    await settle();
    q('tour-card')!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    await settle();

    expect(q('tour-card')).toBeNull();
    expect(document.querySelector('[data-tour-active]')).toBeNull();
    expect(document.activeElement).toBe(q('started-here'));
  });

  it('ends after its last step', async () => {
    await guide.start(tour);
    await guide.next();
    await guide.next();
    await guide.next();
    await settle();

    expect(guide.tour()).toBeNull();
    expect(q('tour-card')).toBeNull();
  });
});
