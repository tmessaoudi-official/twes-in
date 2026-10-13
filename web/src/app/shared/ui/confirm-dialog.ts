// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { ACTION_KIND_ICONS, isDanger, type ActionConfirm } from '../actions/screen-action';

/**
 * A typed word compared as a person means it: case, the spaces around and between words, the composition of an
 * accented letter and the apostrophe a keyboard produces (’ or ') make no difference.
 */
export function sameTyped(typed: string, expected: string): boolean {
  const plain = (text: string) =>
    text
      .normalize('NFC')
      .replace(/[\u2018\u2019\u02bc]/g, "'")
      .replace(/\s+/g, ' ')
      .trim()
      .toLocaleLowerCase();
  return expected.trim() !== '' && plain(typed) === plain(expected);
}

/**
 * The question asked before something destructive runs. A dialog rather than a second button appearing in place of
 * the first: the action now lives in a menu, and a menu entry that turns into two entries is a place to misclick.
 * Below the question it says whether what follows can be taken back (docs/SPEC.md § 7, 2026-09-25 22:17), apart
 * from the message, so the same words read the same on every confirmation. What cannot be taken back is confirmed
 * by a button in the danger colour, and the heaviest actions ask a word typed first (§ 7, 2026-10-13 16:43, 17:01).
 */
@Component({
  selector: 'app-confirm-dialog',
  imports: [
    MatButtonModule,
    MatDialogModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="confirm-title">{{ data.title | translate }}</h2>
    <mat-dialog-content>
      <p data-testid="confirm-message">{{ data.message | translate: data.messageParams }}</p>
      <p
        class="mt-3 flex items-start gap-2 text-sm"
        data-testid="confirm-kind"
        data-tour="action-kind"
        [attr.data-kind]="data.kind"
      >
        <mat-icon aria-hidden="true" class="shrink-0">{{ icons[data.kind] }}</mat-icon>
        <span
          ><strong>{{ 'actions.kind.' + data.kind | translate }}</strong> ·
          {{ 'actions.kind_help.' + data.kind | translate }}</span
        >
      </p>
      @if (expected(); as word) {
        <mat-form-field class="mt-3 w-full" subscriptSizing="dynamic">
          <mat-label>{{ 'actions.typed' | translate: { word } }}</mat-label>
          <input
            matInput
            autocomplete="off"
            spellcheck="false"
            [value]="typed()"
            (input)="typed.set($any($event.target).value)"
            (keydown.enter)="confirmTyped($event)"
            data-testid="confirm-typed"
          />
        </mat-form-field>
      }
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-button type="button" (click)="ref.close(false)" data-testid="confirm-keep">
        {{ data.keepLabel | translate }}
      </button>
      <button
        mat-flat-button
        type="button"
        [class.twes-danger]="danger"
        [disabled]="!ready()"
        (click)="ref.close(true)"
        data-testid="confirm-run"
      >
        {{ data.confirmLabel | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class ConfirmDialog {
  protected readonly ref = inject<MatDialogRef<ConfirmDialog, boolean>>(MatDialogRef);
  protected readonly data = inject<ActionConfirm>(MAT_DIALOG_DATA);
  protected readonly icons = ACTION_KIND_ICONS;
  protected readonly danger = isDanger(this.data.kind);

  private readonly translate = inject(TranslateService);
  protected readonly typed = signal('');
  /** The word to type, in the reader's language, or the name as it is; null when nothing is to be typed. */
  protected readonly expected = signal<string | null>(this.expectedWord());
  protected readonly ready = computed(() => {
    const expected = this.expected();
    return expected === null || sameTyped(this.typed(), expected);
  });

  protected confirmTyped(event: Event): void {
    event.preventDefault();
    if (this.ready()) this.ref.close(true);
  }

  private expectedWord(): string | null {
    const typed = this.data.typed;
    if (typed === undefined) return null;
    return 'name' in typed ? typed.name : this.translate.instant(typed.word);
  }
}
