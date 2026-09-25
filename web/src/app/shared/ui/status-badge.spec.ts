// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import type { StatusTone } from '../theme/accent-theme';
import { StatusBadge } from './status-badge';

@Component({
  imports: [StatusBadge],
  template: `<app-status-badge [tone]="tone()" [struck]="struck()">Livré</app-status-badge>`,
})
class Host {
  readonly tone = signal<StatusTone>('warning');
  readonly struck = signal(false);
}

describe('StatusBadge', () => {
  it('shows its label beside a dot, in the colours of its tone', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const badge: HTMLElement = fixture.nativeElement.querySelector('app-status-badge');

    expect(badge.textContent?.trim()).toBe('Livré');
    expect(badge.getAttribute('data-tone')).toBe('warning');
    expect(badge.style.getPropertyValue('--status-bg')).toBe('var(--twes-status-warning-bg)');
    expect(badge.style.getPropertyValue('--status-fg')).toBe('var(--twes-status-warning-fg)');
    expect(badge.style.getPropertyValue('--status-dot')).toBe('var(--twes-status-warning-dot)');
    expect(badge.querySelector('[data-part="dot"]')?.getAttribute('aria-hidden')).toBe('true');

    fixture.componentInstance.tone.set('success');
    fixture.detectChanges();

    expect(badge.getAttribute('data-tone')).toBe('success');
    expect(badge.style.getPropertyValue('--status-bg')).toBe('var(--twes-status-success-bg)');
  });

  it('strikes the label of a withdrawn status', () => {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const badge: HTMLElement = fixture.nativeElement.querySelector('app-status-badge');
    expect(badge.classList.contains('is-struck')).toBe(false);

    fixture.componentInstance.struck.set(true);
    fixture.detectChanges();

    expect(badge.classList.contains('is-struck')).toBe(true);
  });
});
