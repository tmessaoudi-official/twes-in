// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MAT_DIALOG_DATA, MatDialog, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
import { runAction } from '../shared/actions/run-action';
import { ConfirmDialog } from '../shared/ui/confirm-dialog';
import { COMMAND_GROUPS, type Command, matchCommands } from './commands';

export interface CommandPaletteData {
  readonly commands: readonly Command[];
}

/**
 * The command palette the shell opens with Ctrl K: one field that narrows the commands the user may run, the
 * keyboard moving through them as a combobox over a listbox (WAI-ARIA APG), Enter or a click running one.
 */
@Component({
  selector: 'app-command-palette',
  imports: [MatIconModule, TranslatePipe],
  templateUrl: './command-palette.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CommandPalette {
  private readonly data = inject<CommandPaletteData>(MAT_DIALOG_DATA);
  private readonly dialog = inject(MatDialogRef<CommandPalette>);
  /** The confirming dialog a destructive action opens; the palette's own ref closes before it. */
  private readonly confirm = inject(MatDialog);
  private readonly router = inject(Router);
  private readonly translate = inject(TranslateService);
  /** Re-reads the labels when the translations arrive or the language changes. */
  private readonly language = toSignal(this.translate.onLangChange, { initialValue: null });

  protected readonly query = signal('');
  protected readonly active = signal(0);
  protected readonly matches = computed(() => {
    this.language();
    return matchCommands(
      this.data.commands,
      (command) => this.translate.instant(command.labelKey, command.labelParams) as string,
      this.query(),
    );
  });
  protected readonly groups = computed(() =>
    COMMAND_GROUPS.map((group) => ({
      group,
      commands: this.matches().filter((command) => command.group === group),
    })).filter((entry) => entry.commands.length > 0),
  );
  protected readonly activeCommand = computed(() => this.matches()[this.active()] ?? null);

  protected optionId(command: Command): string {
    return `command-${command.key}`;
  }

  protected onInput(value: string): void {
    this.query.set(value);
    this.active.set(0);
  }

  protected onKeydown(event: KeyboardEvent): void {
    const count = this.matches().length;
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (count === 0) return;
      const step = event.key === 'ArrowDown' ? 1 : -1;
      this.active.update((index) => (index + step + count) % count);
      document.getElementById(this.optionId(this.matches()[this.active()]))?.scrollIntoView?.({
        block: 'nearest',
      });
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const command = this.activeCommand();
      if (command !== null) this.run(command);
    }
  }

  /**
   * A destination is navigated to; an action the screen declared is run by the same `runAction` the toolbar uses,
   * so a destructive action reached from here asks exactly as it asks from the bar.
   */
  protected run(command: Command): void {
    this.dialog.close();
    if (command.group !== 'screen') {
      void this.router.navigateByUrl(command.route);
      return;
    }
    const action = command.action;
    if (action.href !== undefined) {
      window.open(action.href, '_blank', 'noopener');
      return;
    }
    if (action.link !== undefined) {
      void this.router.navigate(action.link);
      return;
    }
    runAction(action, (confirm) =>
      this.confirm.open(ConfirmDialog, { data: confirm, autoFocus: 'dialog' }).afterClosed(),
    );
  }
}
