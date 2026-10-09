// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, effect, input, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';

/**
 * The eye beside a password field: it shows what was typed, so a person can check it before sending, and hides it
 * again. Sending the form hides it first, so the browser offers to save a password rather than plain text. Placed
 * as the field's `matSuffix`, given the input it acts on (docs/SPEC.md § 7: every password field has one).
 */
@Component({
  selector: 'app-password-toggle',
  imports: [MatButtonModule, MatIconModule, Label, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      mat-icon-button
      type="button"
      [appLabel]="(shown() ? 'form.password.hide' : 'form.password.show') | translate"
      [attr.aria-pressed]="shown()"
      (click)="toggle()"
      data-testid="password-toggle"
    >
      <mat-icon aria-hidden="true">{{ shown() ? 'visibility_off' : 'visibility' }}</mat-icon>
    </button>
  `,
})
export class PasswordToggle {
  readonly field = input.required<HTMLInputElement>();
  protected readonly shown = signal(false);

  constructor() {
    effect((onCleanup) => {
      const form = this.field().form;
      if (form === null) {
        return;
      }
      const hide = (): void => this.show(false);
      // Capture: the form's own submit handler then reads a password field, as the browser does.
      form.addEventListener('submit', hide, true);
      onCleanup(() => form.removeEventListener('submit', hide, true));
    });
  }

  protected toggle(): void {
    this.show(!this.shown());
  }

  private show(shown: boolean): void {
    this.shown.set(shown);
    this.field().type = shown ? 'text' : 'password';
  }
}
