// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { MAT_DIALOG_DATA, MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { Router } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';
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
      (command) => this.translate.instant(command.labelKey) as string,
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

  protected run(command: Command): void {
    this.dialog.close();
    void this.router.navigateByUrl(command.route);
  }
}
