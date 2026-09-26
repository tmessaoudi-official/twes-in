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
 *
 * What a consequential action does to the record is its KIND (docs/SPEC.md § 7, 2026-09-25 22:17): a confirmation
 * must say it, so `ActionConfirm.kind` is required and a new confirmation cannot be written without choosing one.
 */
export interface ScreenAction {
  id: string;
  /** A translation key; also the accessible name where the control shows only an icon. */
  label: string;
  labelParams?: Record<string, string>;
  icon?: string;
  /** The state's next step, drawn filled. At most one action carries it. */
  primary?: boolean;
  /**
   * What E runs, whichever screen this is (docs/SPEC.md § 7, 2026-09-24 22:51): the step that moves the record on,
   * Émettre or Encaisser, never a save. Declared rather than read from `primary`, which a record page's save carries.
   * When several are offered at once, the first one declared is the one E runs.
   */
  next?: boolean;
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

/**
 * Whether what an action did can be taken back (docs/SPEC.md § 7, 2026-09-25 22:17): `annulable` is undone from its
 * toast; `corrigeable` is not undone but corrected afterwards, by a counter-document such as a credit note;
 * `definitif` can be neither. The same word is said on the confirmation, in the menu and on the toast.
 */
export type ActionKind = 'annulable' | 'corrigeable' | 'definitif';

export const ACTION_KINDS: readonly ActionKind[] = ['annulable', 'corrigeable', 'definitif'];

/** The icon said beside a kind's word wherever it appears, so the three are told apart at a glance. */
export const ACTION_KIND_ICONS: Readonly<Record<ActionKind, string>> = {
  annulable: 'undo',
  corrigeable: 'edit_note',
  definitif: 'lock',
};

/** An action's kind, from its confirmation; an action that asks nothing has none. */
export function kindOf(action: { confirm?: ActionConfirm }): ActionKind | undefined {
  return action.confirm?.kind;
}

/**
 * The kind a screen declared for one of its actions, read so the toast after it says the word its confirmation said:
 * one declaration, never a second literal beside the call.
 */
export function kindAmong(actions: readonly ScreenAction[], id: string): ActionKind | undefined {
  const action = actions.find((each) => each.id === id);
  return action === undefined ? undefined : kindOf(action);
}

export interface ActionConfirm {
  /** What the action does to the record, said beside the question with the matching icon. */
  kind: ActionKind;
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
