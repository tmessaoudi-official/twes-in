// SPDX-License-Identifier: AGPL-3.0-or-later

import type { Observable } from 'rxjs';
import type { ActionConfirm, ScreenAction } from './screen-action';

/**
 * Runs a declared action, asking first where the declaration says to ask (docs/SPEC.md § 7, 2026-09-17 point 4).
 *
 * The asking is a parameter rather than a dialog opened here, so the one rule — refused while disabled, asked
 * before what is irreversible, run otherwise — is the same whether the action was reached from the toolbar, from
 * a keyboard shortcut or from the palette, and can be tested without a dialog at all.
 */
export function runAction(
  action: ScreenAction,
  ask: (confirm: ActionConfirm) => Observable<boolean | undefined>,
): void {
  // An address is followed by the control that carries it; there is nothing here to run.
  if (action.disabled === true || action.run === undefined) return;

  if (action.confirm === undefined) {
    action.run();
    return;
  }

  // `=== true`, because a dialog dismissed with Escape answers `undefined` — which is a no, not an absent answer.
  ask(action.confirm).subscribe((confirmed) => {
    if (confirmed === true) action.run?.();
  });
}
