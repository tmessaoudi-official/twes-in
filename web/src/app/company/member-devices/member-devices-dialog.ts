// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  inject,
  type OnInit,
  signal,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MAT_DIALOG_DATA, MatDialog, MatDialogModule } from '@angular/material/dialog';
import { TranslatePipe } from '@ngx-translate/core';
import { firstValueFrom } from 'rxjs';
import { deviceLabel } from '../../account/device-label';
import type { ActionConfirm } from '../../shared/actions/screen-action';
import { saidAlready } from '../../shared/errors/said-already';
import { RefusalPipe } from '../../shared/errors/refusal-pipe';
import type { UnknownRefusal } from '../../shared/errors/unknown-refusal';
import { Feedback } from '../../shared/feedback/feedback';
import { ReadFailed } from '../../shared/feedback/read-failed';
import { FormatFacade } from '../../shared/i18n/format-facade';
import { LiveChanges } from '../../shared/realtime/live-changes';
import { StepUp } from '../../shared/step-up/step-up';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import {
  type MemberDevice,
  MemberDevicesApi,
  type MemberDevicesError,
  MemberDevicesRefused,
} from './member-devices-api';

export interface MemberDevicesOf {
  readonly companyId: string;
  readonly userId: string;
  readonly name: string;
}

/**
 * « Appareils connectés » of a member, opened by the company's owner from the members list: the browsers where they are
 * signed in to this company, on what and from where, each signed out on its own or all at once, for a phone lost or a
 * till left open. Signing out is corrigeable: the member signs in again there, and is told by mail. Ending one asks no
 * question; ending them all says how many devices that is first, as « Déconnecter tous les autres » does at « Mon compte ».
 */
@Component({
  selector: 'app-member-devices-dialog',
  imports: [MatButtonModule, MatDialogModule, ReadFailed, RefusalPipe, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <h2 mat-dialog-title data-testid="member-devices-title">
      {{ 'members.devices.title' | translate: { name: data.name } }}
    </h2>
    <mat-dialog-content>
      <div class="flex flex-col gap-3 pt-1">
        <p class="m-0 text-sm text-on-surface-variant">
          {{ 'members.devices.intro' | translate: { name: data.name } }}
        </p>
        @if (error(); as code) {
          <app-read-failed
            testId="member-devices-error"
            retryTestId="member-devices-retry"
            [retryable]="readFailed()"
            (retry)="load()"
          >
            {{ code | refusal: 'members.devices.errors' }}
          </app-read-failed>
        }
        @if (devices(); as list) {
          @if (list.length === 0) {
            <p class="m-0" data-testid="member-devices-none">
              {{ 'members.devices.none' | translate: { name: data.name } }}
            </p>
          } @else {
            <ul class="m-0 flex list-none flex-col gap-2 p-0">
              @for (device of list; track device.id; let i = $index) {
                <li
                  class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-outline-variant p-3"
                  [attr.data-testid]="'member-device-' + i"
                >
                  <div class="flex min-w-0 flex-col">
                    <span class="font-medium" [attr.data-testid]="'member-device-' + i + '-label'">
                      {{ labelOf(device) || ('members.devices.unknown' | translate) }}
                    </span>
                    <span class="text-sm text-on-surface-variant">
                      {{
                        'members.devices.seen'
                          | translate
                            : { address: device.address, when: format.moment(device.lastSeenAt) }
                      }}
                    </span>
                  </div>
                  <button
                    mat-stroked-button
                    type="button"
                    [disabled]="busy()"
                    (click)="end(device)"
                    [attr.data-testid]="'member-device-' + i + '-end'"
                  >
                    {{ 'members.devices.end' | translate }}
                  </button>
                </li>
              }
            </ul>
          }
        }
      </div>
    </mat-dialog-content>
    <mat-dialog-actions align="end">
      @if ((devices()?.length ?? 0) > 1) {
        <button
          mat-stroked-button
          type="button"
          [disabled]="busy()"
          (click)="endAll()"
          data-testid="member-devices-end-all"
        >
          {{ 'members.devices.end_all' | translate }}
        </button>
      }
      <button mat-flat-button type="button" mat-dialog-close data-testid="member-devices-close">
        {{ 'members.devices.close' | translate }}
      </button>
    </mat-dialog-actions>
  `,
})
export class MemberDevicesDialog implements OnInit {
  protected readonly data = inject<MemberDevicesOf>(MAT_DIALOG_DATA);
  private readonly api = inject(MemberDevicesApi);
  private readonly dialog = inject(MatDialog);
  private readonly feedback = inject(Feedback);
  private readonly stepUp = inject(StepUp);
  protected readonly format = inject(FormatFacade);
  private readonly live = inject(LiveChanges);
  private readonly destroyRef = inject(DestroyRef);

  protected readonly devices = signal<MemberDevice[] | null>(null);
  protected readonly error = signal<MemberDevicesError | UnknownRefusal | null>(null);
  /** Whether `error` says the list could not be read, which « Réessayer » reads again; a refused sign-out is not. */
  protected readonly readFailed = signal(false);
  protected readonly busy = signal(false);

  ngOnInit(): void {
    // Another owner signing the member out is said as a change of the account; a sign-in is recorded outside any unit
    // of work and said of nothing, so a device signed in meanwhile shows on the next opening.
    this.live.reloadOn(
      ['user'],
      () => this.load(this.error() !== null && !this.readFailed()),
      this.destroyRef,
    );
    void this.load();
  }

  /** « Firefox · Linux », or nothing when the browser did not say. */
  protected labelOf(device: MemberDevice): string {
    const { browser, system } = deviceLabel(device.device);
    return [browser, system].filter((part) => part !== null).join(' · ');
  }

  /** Reads the list as on opening; after a refused sign-out, `keepRefusal` leaves that refusal said above it. */
  protected async load(keepRefusal = false): Promise<void> {
    try {
      this.devices.set(await this.api.list(this.data.companyId, this.data.userId));
      if (!keepRefusal) {
        this.error.set(null);
        this.readFailed.set(false);
      }
    } catch (error) {
      this.devices.set(null);
      this.said(error, true);
    }
  }

  protected async end(device: MemberDevice): Promise<void> {
    await this.run(async () => {
      await this.proved(() => this.api.end(this.data.companyId, this.data.userId, device.id));
      this.feedback.effect(
        'members.devices.ended',
        { name: this.data.name, device: this.labelOf(device) },
        'corrigeable',
      );
    });
  }

  protected async endAll(): Promise<void> {
    const ask: ActionConfirm = {
      kind: 'corrigeable',
      title: 'members.devices.end_all_title',
      message: 'members.devices.end_all_message',
      messageParams: { name: this.data.name },
      confirmLabel: 'members.devices.end_all',
      keepLabel: 'members.devices.keep',
    };
    const confirmed = await firstValueFrom(
      this.dialog
        .open<ConfirmDialog, ActionConfirm, boolean>(ConfirmDialog, {
          data: ask,
          autoFocus: 'dialog',
        })
        .afterClosed(),
    );
    if (confirmed !== true) return;
    await this.run(async () => {
      const ended = await this.proved(() => this.api.endAll(this.data.companyId, this.data.userId));
      this.feedback.effect(
        'members.devices.all_ended',
        { name: this.data.name, count: ended },
        'corrigeable',
      );
    });
  }

  /** Signing a member out waits for the owner to prove who is at the screen, asked once and tried again. */
  private proved<T>(call: () => Promise<T>): Promise<T> {
    return this.stepUp.retrying(
      call,
      (error) => error instanceof MemberDevicesRefused && error.code === 'step_up_required',
      'step_up.intro_sensitive',
    );
  }

  private async run(act: () => Promise<void>): Promise<void> {
    if (this.busy()) return;
    this.busy.set(true);
    let refused = false;
    try {
      await act();
      this.error.set(null);
    } catch (error) {
      this.said(error, false);
      refused = this.error() !== null;
    } finally {
      this.busy.set(false);
    }
    // Read again either way: a device already signed out elsewhere leaves the list as the refusal says.
    await this.load(refused);
  }

  private said(error: unknown, reading: boolean): void {
    if (saidAlready(error)) return;
    this.error.set(error instanceof MemberDevicesRefused ? error.code : 'network');
    this.readFailed.set(reading);
  }
}
