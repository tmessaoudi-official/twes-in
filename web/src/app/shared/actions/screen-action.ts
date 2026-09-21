// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * What a screen offers, declared once (docs/SPEC.md § 7, 2026-09-16 point 7 and 2026-09-19 23:17, row 45). The
 * toolbar beside the title, the command palette, the keyboard shortcut and the "?" sheet all read this one list,
 * so an action cannot exist in one of them and be missing from another.
 *
 * `shown` and `disabled` are values rather than predicates: a screen recomputes its whole declaration when what it
 * depends on changes, so there is nothing for a reader to call. An action that cannot apply is absent; one that
 * cannot run *for now* — a save in flight — is present and refused, because a control that vanishes mid-save is a
 * control nobody can learn.
 *
 * The class of an action is DERIVED, not declared twice: an action carrying `confirm` is a confirm action, and one
 * without is plain. The ruling's third class, `undo` — an "Annuler" toast over a server-side bin — has no field
 * here on purpose: no bin exists in the API, and a class nothing can produce is a promise, not a type.
 */
export interface ScreenAction {
  id: string;
  /** A translation key; also the accessible name where the control shows only an icon. */
  label: string;
  labelParams?: Record<string, string>;
  icon?: string;
  /** The state's next step, drawn filled. At most one action carries it. */
  primary?: boolean;
  /** Folded into "⋮" rather than shown. Destructive actions are always folded, whatever this says. */
  rare?: boolean;
  /** Drawn as destructive, never a visible button, and asks before it runs when `confirm` is given. */
  destructive?: boolean;
  /** An address rather than a command: the PDF, which opens in its own tab. */
  href?: string;
  /** A `routerLink` array, for an action that navigates inside the app. */
  link?: unknown[];
  /** What it does. Exactly one of `href`, `link` and `run` is given. */
  run?: () => void;
  disabled?: boolean;
  /** Absent means shown. */
  shown?: boolean;
  /** What to ask before running it: translation keys for the question and the word on its confirming button. */
  confirm?: ActionConfirm;
  /**
   * One character, pressed with no modifier at all. It is compared against what the keyboard PRODUCED, so the same
   * declaration works on AZERTY and QWERTY; `refuseReservedShortcut` rejects a key the browser already answers.
   */
  shortcut?: string;
}

export interface ActionConfirm {
  title: string;
  message: string;
  /**
   * What the message interpolates, so the question can NAME what is about to go: asked over a row, "Supprimer
   * Zone 1 ?" says which one where a bare "Supprimer ?" leaves a person counting rows (row 106).
   */
  messageParams?: Record<string, string>;
  confirmLabel: string;
  /** The word on the button that does nothing, which is the one a person reaches for by mistake. */
  keepLabel: string;
}
