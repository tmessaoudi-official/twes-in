// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Observable } from 'rxjs';
import type { ActionConfirm } from './screen-action';

/**
 * As much of an action as running it needs. `ScreenAction` is one; a `RowAction` bound to its row is another, which
 * is what lets a row's own delete follow the same rule rather than a second one written beside the table (row 106).
 */
export interface RunnableAction {
  disabled?: boolean;
  run?: () => void;
  confirm?: ActionConfirm;
  before?: () => Promise<boolean>;
  confirmNow?: () => ActionConfirm;
}

/**
 * Runs a declared action, asking first where the declaration says to ask (docs/SPEC.md § 7, 2026-09-17 point 4).
 *
 * The asking is a parameter rather than a dialog opened here, so the one rule — refused while disabled, asked
 * before what is irreversible, run otherwise — is the same whether the action was reached from the toolbar, from
 * a keyboard shortcut, from the palette or from a row's own controls, and can be tested without a dialog at all.
 */
export function runAction(
  action: RunnableAction,
  ask: (confirm: ActionConfirm) => Observable<boolean | undefined>,
): void {
  // An address is followed by the control that carries it; there is nothing here to run.
  if (action.disabled === true || action.run === undefined) return;

  if (action.confirm === undefined) {
    action.run();
    return;
  }
  const confirm = action.confirm;
  const before = action.before;
  if (before === undefined) {
    askThenRun(action, confirm, ask);
    return;
  }
  // What comes first may change what the question says: issuing saves what is typed, then quotes what was saved.
  void before().then((go) => {
    if (go) askThenRun(action, action.confirmNow?.() ?? confirm, ask);
  });
}

function askThenRun(
  action: RunnableAction,
  confirm: ActionConfirm,
  ask: (confirm: ActionConfirm) => Observable<boolean | undefined>,
): void {
  // `=== true`, because a dialog dismissed with Escape answers `undefined` — which is a no, not an absent answer.
  ask(confirm).subscribe((confirmed) => {
    if (confirmed === true) action.run?.();
  });
}
