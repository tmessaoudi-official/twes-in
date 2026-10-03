// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { CountBadge, type CountKind } from './count-badge';

@Component({
  imports: [CountBadge],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<app-count-badge
    [count]="count()"
    [kind]="kind()"
    [label]="'3 en attente'"
    [max]="max()"
  />`,
})
class Host {
  readonly count = signal(3);
  readonly kind = signal<CountKind>('waiting');
  readonly max = signal<number | null>(null);
}

describe('CountBadge', () => {
  async function render() {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [{ provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } }],
    });
    const fixture = TestBed.createComponent(Host);
    await fixture.whenStable();
    const el = () => fixture.nativeElement.querySelector('app-count-badge') as HTMLElement;
    const settle = async () => {
      fixture.detectChanges();
      await fixture.whenStable();
    };
    return { fixture, el, settle };
  }

  it('is one image saying what it counts in words, the icon and the number being only its picture', async () => {
    const { el } = await render();
    const pill = el().querySelector('[role="img"]')!;

    expect(pill.getAttribute('aria-label')).toBe('3 en attente');
    expect(pill.querySelector('mat-icon')?.getAttribute('aria-hidden')).toBe('true');
    expect(pill.querySelector('[data-count]')?.textContent?.trim()).toBe('3');
  });

  it('draws a different icon for each kind, so the number is never alone', async () => {
    const { fixture, el, settle } = await render();
    const icon = () => el().querySelector('mat-icon')?.textContent?.trim();

    expect(icon()).toBe('schedule');
    fixture.componentInstance.kind.set('attention');
    await settle();
    expect(icon()).toBe('error');
    fixture.componentInstance.kind.set('total');
    await settle();
    expect(icon()).toBe('format_list_numbered');
  });

  it('shows nothing at zero for what waits, and the zero for a total', async () => {
    const { fixture, el, settle } = await render();
    fixture.componentInstance.count.set(0);
    await settle();
    expect(el().querySelector('[role="img"]')).toBeNull();

    fixture.componentInstance.kind.set('total');
    await settle();
    expect(el().querySelector('[data-count]')?.textContent?.trim()).toBe('0');
  });

  it('caps what waits at 99 by default, but never a total', async () => {
    const { fixture, el, settle } = await render();
    fixture.componentInstance.count.set(130);
    await settle();
    expect(el().querySelector('[data-count]')?.textContent?.trim()).toBe('99+');

    fixture.componentInstance.kind.set('total');
    await settle();
    expect(el().querySelector('[data-count]')?.textContent?.trim()).toBe('130');
  });

  it('writes « 9+ » past its limit and still says the real count in words', async () => {
    const { fixture, el, settle } = await render();
    fixture.componentInstance.max.set(9);
    fixture.componentInstance.count.set(12);
    await settle();

    expect(el().querySelector('[data-count]')?.textContent?.trim()).toBe('9+');
    expect(el().querySelector('[role="img"]')?.getAttribute('aria-label')).toBe('3 en attente');
  });
});
