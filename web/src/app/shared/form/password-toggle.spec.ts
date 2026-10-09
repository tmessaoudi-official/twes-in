// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MATERIAL_ANIMATIONS } from '@angular/material/core';
import { provideTranslateService } from '@ngx-translate/core';
import { beforeEach, describe, expect, it } from 'vitest';
import { PasswordToggle } from './password-toggle';

@Component({
  imports: [PasswordToggle],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form (submit)="$event.preventDefault(); submitted = submitted + 1">
      <input #pw type="password" value="Carthage-2026!" data-testid="pw" />
      <app-password-toggle [field]="pw" />
    </form>
  `,
})
class Host {
  submitted = 0;
}

describe('PasswordToggle', () => {
  let fixture: ComponentFixture<Host>;

  const field = (): HTMLInputElement => fixture.nativeElement.querySelector('[data-testid="pw"]');
  const button = (): HTMLButtonElement =>
    fixture.nativeElement.querySelector('[data-testid="password-toggle"]');
  const settle = async (): Promise<void> => {
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  };

  beforeEach(async () => {
    TestBed.configureTestingModule({
      providers: [
        provideTranslateService(),
        { provide: MATERIAL_ANIMATIONS, useValue: { animationsDisabled: true } },
      ],
    });
    fixture = TestBed.createComponent(Host);
    await settle();
  });

  it('starts hidden, offering to show what was typed', () => {
    expect(field().type).toBe('password');
    expect(button().getAttribute('aria-label')).toBe('form.password.show');
    expect(button().getAttribute('aria-pressed')).toBe('false');
    expect(button().textContent).toContain('visibility');
    expect(button().textContent).not.toContain('visibility_off');
  });

  it('shows what was typed, then hides it again', async () => {
    button().click();
    await settle();
    expect(field().type).toBe('text');
    expect(field().value).toBe('Carthage-2026!');
    expect(button().getAttribute('aria-label')).toBe('form.password.hide');
    expect(button().getAttribute('aria-pressed')).toBe('true');
    expect(button().textContent).toContain('visibility_off');

    button().click();
    await settle();
    expect(field().type).toBe('password');
    expect(button().getAttribute('aria-label')).toBe('form.password.show');
  });

  it('never submits the form it sits in', async () => {
    expect(button().type).toBe('button');
    button().click();
    await settle();
    expect(fixture.componentInstance.submitted).toBe(0);
  });

  it('hides the password again when the form is sent, so the browser saves it as a password', async () => {
    button().click();
    await settle();
    field().form?.dispatchEvent(new Event('submit', { cancelable: true }));
    await settle();
    expect(fixture.componentInstance.submitted).toBe(1);
    expect(field().type).toBe('password');
    expect(button().getAttribute('aria-pressed')).toBe('false');
  });
});
