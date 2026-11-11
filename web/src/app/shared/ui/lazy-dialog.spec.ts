// SPDX-License-Identifier: AGPL-3.0-or-later

import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { describe, expect, it, vi } from 'vitest';
import { LazyDialog } from './lazy-dialog';

@Component({ selector: 'app-asked', template: '' })
class Asked {}

// A service the first page reaches opens its dialog through LazyDialog, so Material's dialog is fetched with the
// dialog's own component when first asked for and not with the first page (scripts/gates/first-page-imports.sh).
describe('LazyDialog', () => {
  function lazyDialog(open: (...args: unknown[]) => unknown): LazyDialog {
    TestBed.configureTestingModule({ providers: [{ provide: MatDialog, useValue: { open } }] });
    return TestBed.inject(LazyDialog);
  }

  it('opens the component it loads through the application’s dialog, with the configuration given', async () => {
    const ref = { id: 'asked' };
    const open = vi.fn(() => ref);
    const dialogs = lazyDialog(open);

    const opened = await dialogs.open(async () => Asked, {
      data: { intro: 'step_up.intro' },
      autoFocus: 'dialog',
    });

    expect(opened).toBe(ref);
    expect(open).toHaveBeenCalledExactlyOnceWith(Asked, {
      data: { intro: 'step_up.intro' },
      autoFocus: 'dialog',
    });
  });

  it('loads the component only when asked', async () => {
    const load = vi.fn(async () => Asked);
    const dialogs = lazyDialog(() => ({}));

    expect(load).not.toHaveBeenCalled();
    await dialogs.open(load);
    expect(load).toHaveBeenCalledOnce();
  });

  it('opens nothing and passes the failure on when the component cannot be fetched', async () => {
    const open = vi.fn();
    const dialogs = lazyDialog(open);
    const offline = new TypeError('Failed to fetch dynamically imported module');

    await expect(dialogs.open(() => Promise.reject(offline))).rejects.toBe(offline);
    expect(open).not.toHaveBeenCalled();
  });
});
