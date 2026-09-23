// SPDX-License-Identifier: AGPL-3.0-or-later

import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { CameraView } from './camera-view';
import { ScanBus } from './scan-bus';

export { CAMERA_CHOICE_KEY } from './camera-view';

/**
 * This device's camera beside the page (docs/SPEC.md § 7, 2026-09-23 09:45, slice 3): a small panel that leaves the
 * page usable, each code it reads sent where a handheld scanner's goes — the screen on view acts on it.
 */
@Component({
  selector: 'app-camera-scan-panel',
  imports: [MatButtonModule, MatIconModule, TranslatePipe, Label, CameraView],
  templateUrl: './camera-scan-panel.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CameraScanPanel {
  protected readonly dialog = inject(MatDialogRef<CameraScanPanel>);
  protected readonly scans = inject(ScanBus);
}
