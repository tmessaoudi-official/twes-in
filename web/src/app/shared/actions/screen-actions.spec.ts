// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import type { ScreenAction } from './screen-action';
import { runAction } from './run-action';
import { ScreenActions } from './screen-actions';

function action(over: Partial<ScreenAction> = {}): ScreenAction {
  return { id: 'issue', label: 'invoices.issue', run: () => undefined, ...over };
}

describe('runAction', () => {
  it('runs a plain action at once, and asks nothing', () => {
    let ran = 0;
    let asked = 0;
    runAction(action({ run: () => (ran += 1) }), () => {
      asked += 1;
      return of(true);
    });
    expect([ran, asked]).toEqual([1, 0]);
  });

  it('asks first when the action says what to ask, and runs only on yes', () => {
    const confirm = { title: 't', message: 'm', confirmLabel: 'c', keepLabel: 'k' };
    let ran = 0;
    runAction(action({ confirm, run: () => (ran += 1) }), () => of(false));
    expect(ran).toBe(0);

    let askedWith: unknown = null;
    runAction(action({ confirm, run: () => (ran += 1) }), (asked) => {
      askedWith = asked;
      return of(true);
    });
    expect([ran, askedWith]).toEqual([1, confirm]);
  });

  it('does nothing for an action that is refused for now, without asking', () => {
    // A disabled control that still opens its dialog is a control that lies about being disabled.
    let ran = 0;
    let asked = 0;
    runAction(
      action({
        disabled: true,
        confirm: { title: 't', message: 'm', confirmLabel: 'c', keepLabel: 'k' },
        run: () => (ran += 1),
      }),
      () => {
        asked += 1;
        return of(true);
      },
    );
    expect([ran, asked]).toEqual([0, 0]);
  });

  it('treats a dismissed question as a no, not as an absent answer', () => {
    // A dialog closed with Escape answers `undefined`; running on anything but an explicit yes would make Escape
    // the confirming key of every destructive action on the screen.
    let ran = 0;
    runAction(
      action({
        confirm: { title: 't', message: 'm', confirmLabel: 'c', keepLabel: 'k' },
        run: () => (ran += 1),
      }),
      () => of(undefined),
    );
    expect(ran).toBe(0);
  });

  it('does nothing for an action that is an address rather than a command', () => {
    expect(() =>
      runAction(action({ run: undefined, href: '/x.pdf' }), () => of(true)),
    ).not.toThrow();
  });
});

describe('ScreenActions', () => {
  @Component({ template: '' })
  class Holder {
    readonly actions = signal<readonly ScreenAction[]>([]);
    constructor() {
      TestBed.inject(ScreenActions).declare(this.actions);
    }
  }

  function registry(): ScreenActions {
    return TestBed.inject(ScreenActions);
  }

  beforeEach(() => TestBed.configureTestingModule({ imports: [Holder] }));

  it('holds nothing until a screen declares something', () => {
    expect(registry().actions()).toEqual([]);
  });

  it('holds what the screen on view declared, and follows it as the screen recomputes', () => {
    const fixture = TestBed.createComponent(Holder);
    fixture.detectChanges();
    expect(registry().actions()).toEqual([]);

    fixture.componentInstance.actions.set([action()]);
    expect(
      registry()
        .actions()
        .map((each) => each.id),
    ).toEqual(['issue']);
  });

  it('forgets a screen the moment it is destroyed, so its keys do not fire on the next one', () => {
    const fixture = TestBed.createComponent(Holder);
    fixture.componentInstance.actions.set([action()]);
    fixture.detectChanges();
    expect(registry().actions()).toHaveLength(1);

    fixture.destroy();
    expect(registry().actions()).toEqual([]);
  });

  it('leaves out an action the screen says does not apply', () => {
    const fixture = TestBed.createComponent(Holder);
    fixture.componentInstance.actions.set([action(), action({ id: 'cancel', shown: false })]);
    fixture.detectChanges();
    expect(
      registry()
        .actions()
        .map((each) => each.id),
    ).toEqual(['issue']);
  });

  it('answers which action a keystroke asked for, and nothing for an unbound key', () => {
    const fixture = TestBed.createComponent(Holder);
    fixture.componentInstance.actions.set([action({ shortcut: 'i' }), action({ id: 'pay' })]);
    fixture.detectChanges();

    expect(registry().forKey('i')?.id).toBe('issue');
    expect(registry().forKey('p')).toBeUndefined();
  });

  it('refuses a declaration naming a key the browser owns, where the declaration is written', () => {
    const fixture = TestBed.createComponent(Holder);
    expect(() => {
      fixture.componentInstance.actions.set([action({ shortcut: 'Enter' })]);
      registry().actions();
    }).toThrow(/reserved/i);
  });

  it('refuses two actions of one screen claiming the same key', () => {
    // Which of them fires would otherwise depend on declaration order, and the loser is silently dead.
    const fixture = TestBed.createComponent(Holder);
    expect(() => {
      fixture.componentInstance.actions.set([
        action({ shortcut: 'i' }),
        action({ id: 'invoice', shortcut: 'i' }),
      ]);
      registry().actions();
    }).toThrow(/twice|already/i);
  });
});
