// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { TranslatePipe } from '@ngx-translate/core';
import { Feedback } from '../shared/feedback/feedback';
import { FormatFacade } from '../shared/i18n/format-facade';
import { type ConnectedDevice, ConnectedDevicesApi } from './connected-devices-api';
import { deviceLabel } from './device-label';

/**
 * « Appareils connectés »: where the account is signed in, and the means to end a session left open somewhere else.
 * Ending a session is corrigeable: the person signs in again there. The browser asking is named and has no button,
 * since ending it is signing out, which the account menu already does.
 */
@Component({
  selector: 'app-connected-devices',
  imports: [MatButtonModule, TranslatePipe],
  templateUrl: './connected-devices.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConnectedDevices implements OnInit {
  private readonly api = inject(ConnectedDevicesApi);
  private readonly feedback = inject(Feedback);
  protected readonly format = inject(FormatFacade);

  protected readonly devices = signal<ConnectedDevice[] | null>(null);
  protected readonly unreachable = signal(false);
  protected readonly busy = signal(false);
  protected readonly others = computed(() => (this.devices() ?? []).filter((d) => !d.current));

  ngOnInit(): void {
    void this.load();
  }

  /** « Firefox · Linux », or nothing when the browser did not say. */
  protected labelOf(device: ConnectedDevice): string {
    const { browser, system } = deviceLabel(device.device);
    return [browser, system].filter((part) => part !== null).join(' · ');
  }

  protected async end(device: ConnectedDevice): Promise<void> {
    await this.run(async () => {
      await this.api.end(device.id);
      this.feedback.effect(
        'account.devices.ended',
        { device: this.labelOf(device) },
        'corrigeable',
      );
    });
  }

  protected async endOthers(): Promise<void> {
    await this.run(async () => {
      const ended = await this.api.endOthers();
      this.feedback.effect('account.devices.others_ended', { count: ended }, 'corrigeable');
    });
  }

  private async run(act: () => Promise<void>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    try {
      await act();
    } catch {
      this.feedback.failure('account.devices.failed');
    } finally {
      this.busy.set(false);
    }
    await this.load();
  }

  private async load(): Promise<void> {
    try {
      this.devices.set(await this.api.list());
      this.unreachable.set(false);
    } catch {
      this.unreachable.set(true);
    }
  }
}
