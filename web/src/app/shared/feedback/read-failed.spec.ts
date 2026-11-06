// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslateService } from '@ngx-translate/core';
import { ReadFailed } from './read-failed';

@Component({
  imports: [ReadFailed],
  template: `
    <app-read-failed
      testId="x-error"
      retryTestId="x-retry"
      [retryable]="retryable()"
      (retry)="asked = asked + 1"
      >Could not be read</app-read-failed
    >
  `,
})
class Host {
  readonly retryable = signal(true);
  asked = 0;
}

describe('ReadFailed', () => {
  function render() {
    TestBed.configureTestingModule({
      imports: [Host],
      providers: [provideTranslateService({ lang: 'fr', fallbackLang: 'fr' })],
    });
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const q = (testId: string): HTMLElement | null =>
      fixture.nativeElement.querySelector(`[data-testid="${testId}"]`);
    return { fixture, q };
  }

  it('says what failed as an alert and offers to read again, which the page does', () => {
    const { fixture, q } = render();

    expect(q('x-error')?.getAttribute('role')).toBe('alert');
    expect(q('x-error')?.textContent?.trim()).toBe('Could not be read');
    expect(q('x-retry')?.textContent?.trim()).toBe('feedback.retry');
    q('x-retry')!.click();
    expect(fixture.componentInstance.asked).toBe(1);
  });

  it('offers no « Réessayer » under a refused save, which reading again would not undo', () => {
    const { fixture, q } = render();
    fixture.componentInstance.retryable.set(false);
    fixture.detectChanges();

    expect(q('x-error')?.textContent?.trim()).toBe('Could not be read');
    expect(q('x-retry')).toBeNull();
  });
});
