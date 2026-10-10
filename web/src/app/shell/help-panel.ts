// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import type { Tour } from '../shared/tour/tour';
import type { GlossaryEntry } from './glossary';

export interface HelpPanelData {
  /** The guides this person may follow, those of the screen on view first. */
  readonly tours: readonly Tour[];
  readonly glossary: readonly GlossaryEntry[];
}

/** What the panel was closed for: a guide to start, the keyboard's list to open, or nothing. */
export type HelpChoice = { readonly tour: Tour } | 'shortcuts' | undefined;

/**
 * The « ? » of every screen: the guides that walk through a task, the words the screens use, and the keyboard's
 * shortcuts. It closes with the person's choice, and the shell acts on it, so a guide starts over the page itself.
 */
@Component({
  selector: 'app-help-panel',
  imports: [MatButtonModule, MatDialogModule, MatIconModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="help-title">{{ 'help.title' | translate }}</h2>
    <mat-dialog-content>
      <!-- Material's own display beats a layout class on mat-dialog-content: the layout goes on a div inside it. -->
      <div class="flex flex-col gap-6">
        <section class="flex flex-col gap-2" aria-labelledby="twes-help-guides">
          <h3 id="twes-help-guides" class="text-base font-semibold">
            {{ 'help.guides' | translate }}
          </h3>
          @if (data.tours.length === 0) {
            <p class="text-sm text-on-surface-variant" data-testid="help-no-guide">
              {{ 'help.no_guide' | translate }}
            </p>
          } @else {
            <ul class="flex flex-col gap-1">
              @for (tour of data.tours; track tour.key) {
                <li>
                  <button
                    mat-button
                    type="button"
                    (click)="ref.close({ tour })"
                    [attr.data-testid]="'help-guide-' + tour.key"
                  >
                    <mat-icon aria-hidden="true">help</mat-icon>
                    {{ tour.titleKey | translate }}
                  </button>
                </li>
              }
            </ul>
          }
        </section>

        <section class="flex flex-col gap-2" aria-labelledby="twes-help-glossary">
          <h3 id="twes-help-glossary" class="text-base font-semibold">
            {{ 'help.glossary' | translate }}
          </h3>
          <dl class="flex flex-col gap-3" data-testid="help-glossary">
            @for (entry of data.glossary; track entry.key) {
              <div [attr.data-testid]="'glossary-' + entry.key">
                <dt class="font-semibold">{{ entry.termKey | translate }}</dt>
                <dd class="text-sm">{{ entry.definitionKey | translate }}</dd>
              </div>
            }
          </dl>
        </section>
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button
        mat-stroked-button
        type="button"
        (click)="ref.close('shortcuts')"
        data-testid="help-shortcuts"
      >
        {{ 'help.shortcuts' | translate }}
      </button>
      <button mat-flat-button type="button" (click)="ref.close()" data-testid="help-close">
        {{ 'help.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class HelpPanel {
  protected readonly ref = inject<MatDialogRef<HelpPanel, HelpChoice>>(MatDialogRef);
  protected readonly data = inject<HelpPanelData>(MAT_DIALOG_DATA);
}
