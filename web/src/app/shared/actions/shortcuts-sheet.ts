// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogModule, MatDialogRef } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { ScreenActions } from './screen-actions';

/** A key that works on every screen, answered by the shell itself. */
export interface GlobalShortcut {
  readonly id: string;
  /** Shown as typed, not translated: a key's name is the same word in every language. */
  readonly keys: string;
  readonly label: string;
}

/**
 * "Ctrl K" rather than "⌘ K" on a Mac, and that is not a simplification: the shell answers `metaKey` OR `ctrlKey`,
 * so Ctrl K is true on every platform while ⌘ K would be false on most of them.
 */
export const GLOBAL_SHORTCUTS: readonly GlobalShortcut[] = [
  { id: 'palette', keys: 'Ctrl K', label: 'shell.shortcuts.palette' },
  { id: 'sidebar', keys: '[', label: 'shell.shortcuts.sidebar' },
  { id: 'help', keys: '?', label: 'shell.shortcuts.help' },
];

/**
 * What "?" opens (docs/SPEC.md § 7, 2026-09-16 point 7, row 45). The page's own keys come first, because they are
 * the half that changes; the keys that work everywhere follow, and are the only place the palette is named — a
 * person who has never pressed Ctrl K has nothing on screen telling them it exists.
 *
 * It reads the same declaration the toolbar and the dispatcher read, so a key listed here is a key that runs.
 */
@Component({
  selector: 'app-shortcuts-sheet',
  imports: [MatButtonModule, MatDialogModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="shortcuts-title">
      {{ 'shell.shortcuts.title' | translate }}
    </h2>
    <mat-dialog-content>
      <h3 class="twes-shortcuts-heading">{{ 'shell.shortcuts.page' | translate }}</h3>
      @if (page().length === 0) {
        <p data-testid="shortcuts-none">{{ 'shell.shortcuts.none' | translate }}</p>
      } @else {
        <dl class="twes-shortcuts">
          @for (action of page(); track action.id) {
            <div class="twes-shortcuts-row" [attr.data-testid]="'shortcut-' + action.id">
              <dt>{{ action.label | translate: action.labelParams }}</dt>
              <dd>
                <kbd>{{ action.shortcut?.toUpperCase() }}</kbd>
              </dd>
            </div>
          }
        </dl>
      }

      <h3 class="twes-shortcuts-heading">{{ 'shell.shortcuts.everywhere' | translate }}</h3>
      <dl class="twes-shortcuts">
        @for (shortcut of global; track shortcut.id) {
          <div class="twes-shortcuts-row" [attr.data-testid]="'shortcut-' + shortcut.id">
            <dt>{{ shortcut.label | translate }}</dt>
            <dd>
              <kbd>{{ shortcut.keys }}</kbd>
            </dd>
          </div>
        }
      </dl>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      <button mat-flat-button type="button" (click)="ref.close()" data-testid="shortcuts-close">
        {{ 'shell.shortcuts.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
  styles: `
    .twes-shortcuts-heading {
      font: var(--mat-sys-title-small);
      margin: 1rem 0 0.5rem;
    }
    .twes-shortcuts-heading:first-child {
      margin-top: 0;
    }
    .twes-shortcuts {
      display: flex;
      flex-direction: column;
      gap: 0.25rem;
      margin: 0;
    }
    .twes-shortcuts-row {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 1rem;
    }
    .twes-shortcuts-row dd {
      margin: 0;
    }
    kbd {
      border: 1px solid var(--mat-sys-outline-variant);
      border-radius: 0.25rem;
      font: var(--mat-sys-label-medium);
      padding: 0.125rem 0.375rem;
      white-space: nowrap;
    }
  `,
})
export class ShortcutsSheet {
  protected readonly ref = inject<MatDialogRef<ShortcutsSheet>>(MatDialogRef);
  private readonly screen = inject(ScreenActions);

  protected readonly global = GLOBAL_SHORTCUTS;
  /** The screen's own keys, live: what this sheet lists is exactly what pressing the key would run right now. */
  protected readonly page = computed(() => this.screen.withShortcut());
}
