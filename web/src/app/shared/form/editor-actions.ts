// SPDX-License-Identifier: AGPL-3.0-or-later

import { computed, type Signal } from '@angular/core';
import type { ScreenAction } from '../actions/screen-action';

export interface EditorActionsOptions {
  /** What is typed in the open editor and not saved, which `listRecord` counts. */
  readonly changes: Signal<number>;
  readonly busy: Signal<boolean>;
  /** The editor's own submit: the form shows what it refuses, and hands on what it saves. */
  readonly save: () => void;
  /** Closes the editor, dropping what was typed; nothing asks, as going back on a record page asks nothing. */
  readonly close: () => void;
  /** The close's words: the page's own « Fermer ». */
  readonly closeLabel: string;
  readonly saveLabel: string;
}

/**
 * What the bar on an editor opened inside a list offers: its save, active once something changed, and its close.
 * The bar sits on the editor's card rather than beside the page's title: the card is short and on screen, and a save
 * beside the title would read as saving the whole list.
 */
export function editorActions(options: EditorActionsOptions): Signal<ScreenAction[]> {
  return computed(() => {
    const busy = options.busy();
    return [
      { id: 'cancel', label: options.closeLabel, run: options.close },
      {
        id: 'save',
        label: options.saveLabel,
        icon: 'save',
        primary: true,
        disabled: busy || options.changes() === 0,
        run: options.save,
      },
    ];
  });
}
