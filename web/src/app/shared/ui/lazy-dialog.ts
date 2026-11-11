// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable, Injector, inject } from '@angular/core';
import type { ComponentType } from '@angular/cdk/portal';
import type { MatDialogConfig, MatDialogRef } from '@angular/material/dialog';

/**
 * Opens a dialog from a service the first page reaches (step-up, company entry, unsaved changes, the install offer):
 * Material's dialog is fetched with the dialog's own component the first time one is asked for, so neither rides the
 * first page, which has no room for them (scripts/gates/first-page-imports.sh). A screen fetched lazily injects
 * `MatDialog` itself. Offline, a dialog never fetched cannot open: the promise rejects and nothing is shown.
 */
@Injectable({ providedIn: 'root' })
export class LazyDialog {
  private readonly injector = inject(Injector);

  async open<C, D = unknown, R = unknown>(
    load: () => Promise<ComponentType<C>>,
    config?: MatDialogConfig<D>,
  ): Promise<MatDialogRef<C, R>> {
    const [{ MatDialog }, component] = await Promise.all([
      import('@angular/material/dialog'),
      load(),
    ]);
    return this.injector.get(MatDialog).open<C, D, R>(component, config);
  }
}
