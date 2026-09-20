// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * What a document screen offers, declared once (docs/SPEC.md § 7, 2026-09-19 23:17, design review finding 3:
 * "Facturer ce bon" and "Enregistrer le paiement" sat below 2000 px of form). The bar beside the title shows the
 * state's next step as its one primary button and the frequent actions beside it, and folds the rest into "⋮".
 *
 * `shown` and `disabled` are values rather than predicates: a screen recomputes its whole declaration when what it
 * depends on changes, so there is nothing for the bar to call. An action that cannot apply is absent; one that
 * cannot run *for now* — a save in flight — is present and refused, because a control that vanishes mid-save is a
 * control nobody can learn.
 */
export interface DocumentAction {
  id: string;
  /** A translation key; also the accessible name where the button shows only an icon. */
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
  confirm?: DocumentConfirm;
}

export interface DocumentConfirm {
  title: string;
  message: string;
  confirmLabel: string;
  /** The word on the button that does nothing, which is the one a person reaches for by mistake. */
  keepLabel: string;
}
