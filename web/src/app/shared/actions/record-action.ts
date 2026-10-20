// SPDX-License-Identifier: AGPL-3.0-or-later

import type { IconName } from '../icons/icons';
import type { RowAction } from '../list/list-types';
import type { ActionConfirm, ScreenAction } from './screen-action';

/**
 * How often a person takes an action, which is what places it: the state's next step is the one filled button, last
 * in the bar and on its row; a frequent one is visible; a rare one waits in « ⋮ ».
 */
export type ActionFrequency = 'next' | 'frequent' | 'rare';

/** Where an action sits in « ⋮ »: the groups follow this order, a divider between each. */
export type ActionGroup = 'open' | 'create' | 'send' | 'state' | 'correct';

export const ACTION_GROUPS: readonly ActionGroup[] = ['open', 'create', 'send', 'state', 'correct'];

/** What an action that asks says, of the record it would act on: its kind comes from its effect. */
export type ActionPreview = Omit<ActionConfirm, 'kind'>;

/**
 * What an action does to the record decides whether it asks. Plain and annulable never ask (an annulable one is undone
 * from its toast); corrigeable and définitif ask, and cannot be written without the exact preview they ask with.
 */
export type RecordEffect<R> =
  | { kind: 'plain' }
  | { kind: 'annulable' }
  | { kind: 'corrigeable' | 'definitif'; preview: (record: R) => ActionPreview };

/**
 * What can be done to one record type, declared once beside its module and read by every surface that offers it: the
 * record's page bar, a sheet over its list, its list's rows, the keys and the palette. Every predicate is a function of
 * the record alone, so the row and the page cannot come to different answers about the same document.
 */
export interface RecordAction<R> {
  id: string;
  /** A translation key, or one chosen by the record: a draft's PDF is a preview. */
  label: string | ((record: R) => string);
  labelParams?: (record: R) => Record<string, string>;
  icon: IconName;
  group: ActionGroup;
  frequency: ActionFrequency | ((record: R) => ActionFrequency);
  effect: RecordEffect<R>;
  /** Whether it applies to this record, for this person; one that does not is absent. */
  shown: (record: R) => boolean;
  /** Applies, but not now: present and refused, since a control that vanishes cannot be learnt. */
  disabled?: (record: R) => boolean;
  /** Exactly one of `run`, `href` and `link` is given. */
  run?: (record: R) => void;
  /** A file the browser opens in its own tab: the PDF. */
  href?: (record: R) => string | null;
  link?: (record: R) => unknown[];
  /** A page key, pressed with no modifier; never bound on a row. */
  shortcut?: string;
}

function labelOf<R>(action: RecordAction<R>, record: R): string {
  return typeof action.label === 'string' ? action.label : action.label(record);
}

function frequencyOf<R>(action: RecordAction<R>, record: R): ActionFrequency {
  return typeof action.frequency === 'string' ? action.frequency : action.frequency(record);
}

function confirmOf<R>(action: RecordAction<R>, record: R): ActionConfirm | undefined {
  const effect = action.effect;
  return effect.kind === 'corrigeable' || effect.kind === 'definitif'
    ? { kind: effect.kind, ...effect.preview(record) }
    : undefined;
}

/**
 * The actions bound to the record on view, as the screen actions its bar, the keys and the palette read; none while
 * no record is on view, and every one refused while the screen is busy with it.
 */
export function onRecord<R>(
  actions: readonly RecordAction<R>[],
  record: R | null | undefined,
  busy: boolean,
): ScreenAction[] {
  return actions.map((action): ScreenAction => {
    if (record === null || record === undefined) {
      // Nothing to name it after: absent, so no reader draws it.
      return {
        id: action.id,
        label: typeof action.label === 'string' ? action.label : '',
        shown: false,
      };
    }
    const frequency = frequencyOf(action, record);
    const href = action.href?.(record);
    const run = action.run;
    return {
      id: action.id,
      label: labelOf(action, record),
      labelParams: action.labelParams?.(record),
      icon: action.icon,
      group: action.group,
      primary: frequency === 'next',
      next: frequency === 'next',
      rare: frequency === 'rare',
      destructive: action.effect.kind === 'definitif',
      undoable: action.effect.kind === 'annulable',
      shown: action.shown(record) && href !== null,
      disabled: busy || (action.disabled?.(record) ?? false),
      href: href ?? undefined,
      link: action.link?.(record),
      run: run === undefined ? undefined : () => run(record),
      confirm: confirmOf(action, record),
      shortcut: action.shortcut,
    };
  });
}

/** The same actions bound to every row of a list: what each row can take, asked of that row. */
export function onRows<R>(actions: readonly RecordAction<R>[]): RowAction<R>[] {
  return actions.map((action) => {
    const run = action.run;
    const href = action.href;
    return {
      id: action.id,
      label: typeof action.label === 'string' ? action.label : '',
      labelOf: (row: R) => labelOf(action, row),
      labelParams: action.labelParams,
      icon: action.icon,
      group: action.group,
      next: (row: R) => frequencyOf(action, row) === 'next',
      rare: (row: R) => frequencyOf(action, row) === 'rare',
      destructive: action.effect.kind === 'definitif',
      undoable: action.effect.kind === 'annulable',
      shown: (row: R) => action.shown(row) && (href === undefined || href(row) !== null),
      disabled: action.disabled,
      href: href === undefined ? undefined : (row: R) => href(row) ?? '',
      link: action.link,
      run: run === undefined ? undefined : (row: R) => run(row),
      confirm:
        action.effect.kind === 'corrigeable' || action.effect.kind === 'definitif'
          ? (row: R) => confirmOf(action, row)!
          : undefined,
    };
  });
}

/** A « ⋮ »'s entries in the order of their groups, those of no group last; the declared order kept within each. */
export function menuOrder<A extends { group?: ActionGroup }>(actions: readonly A[]): A[] {
  const rank = (action: A) =>
    action.group === undefined ? ACTION_GROUPS.length : ACTION_GROUPS.indexOf(action.group);
  return actions
    .map((action, index) => ({ action, index }))
    .sort((a, b) => rank(a.action) - rank(b.action) || a.index - b.index)
    .map(({ action }) => action);
}
