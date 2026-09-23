// SPDX-License-Identifier: AGPL-3.0-or-later

import { DOCUMENT } from '@angular/common';
import {
  ChangeDetectionStrategy,
  Component,
  computed,
  DestroyRef,
  inject,
  Injectable,
  InjectionToken,
  type OnInit,
  signal,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatIconModule } from '@angular/material/icon';
import { MatInputModule } from '@angular/material/input';
import { TranslatePipe } from '@ngx-translate/core';
import { REALTIME_CONNECTOR, type RealtimeConnection } from '../shared/realtime/realtime-connector';
import { Camera } from '../shared/scan/camera';
import { CameraView } from '../shared/scan/camera-view';
import {
  type PairingEcho,
  PairingApi,
  type PairingRefusal,
  PairingRefused,
} from '../shared/scan/pairing-api';
import { PageMemoryStorage, type SettingsStorage } from '../shared/settings/settings-facade';

/** Where this phone keeps its pairing for a reload of this tab: the tab's own session storage, never shared. */
export const PAIRING_STORAGE = new InjectionToken<SettingsStorage>('PAIRING_STORAGE', {
  providedIn: 'root',
  factory: () => {
    try {
      return inject(DOCUMENT).defaultView?.sessionStorage ?? new PageMemoryStorage();
    } catch {
      // Site data blocked: a reload then needs a new link, which is all it costs.
      return new PageMemoryStorage();
    }
  },
});

export const PAIRING_STORAGE_KEY = 'twes.scan.phone';

/** What the page needs from its address: the link after `#`, taken off at once, and where the realtime server is. */
@Injectable({ providedIn: 'root' })
export class PairingAddress {
  private readonly document = inject(DOCUMENT);

  /** The link travels after `#`, which no server log or Referer carries; reading it takes it off the address. */
  takeLink(): string | null {
    const location = this.document.location;
    const link = location.hash.replace(/^#/, '');
    if (link === '') return null;
    this.document.defaultView?.history.replaceState(null, '', location.pathname);
    return link;
  }

  socket(): string {
    const { protocol, host } = this.document.location;
    return `${protocol === 'https:' ? 'wss' : 'ws'}://${host}/connection/websocket`;
  }
}

/** How many answers the phone keeps on screen, newest first. */
const ECHOES_KEPT = 10;

interface Held {
  readonly id: string;
  readonly key: string;
}

type Stage = 'loading' | 'scanning' | 'ended' | { refused: PairingRefusal };

/**
 * A phone lent to a computer as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4), with no sign-in: it claims the
 * link the computer showed, then scans — with its camera or by hand — and each code acts once on the computer, under
 * the computer's session. What it shows back is only what the computer echoes: the outcome, the product's name and
 * customer price, and the same choices as the computer's card, a tap on which sends the choice there.
 */
@Component({
  selector: 'app-phone-scanner-page',
  imports: [
    FormsModule,
    MatButtonModule,
    MatFormFieldModule,
    MatIconModule,
    MatInputModule,
    TranslatePipe,
    CameraView,
  ],
  templateUrl: './phone-scanner-page.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PhoneScannerPage implements OnInit {
  private readonly api = inject(PairingApi);
  private readonly connector = inject(REALTIME_CONNECTOR);
  private readonly storage = inject(PAIRING_STORAGE);
  private readonly address = inject(PairingAddress);
  protected readonly cameraAvailable = inject(Camera).available();
  private held: Held | null = null;
  private connection: RealtimeConnection | null = null;

  protected readonly stage = signal<Stage>('loading');
  protected readonly refusal = computed(() => {
    const stage = this.stage();
    return typeof stage === 'object' ? stage.refused : null;
  });
  protected readonly echoes = signal<readonly PairingEcho[]>([]);
  protected readonly sent = signal<string | null>(null);
  protected code = '';

  constructor() {
    inject(DestroyRef).onDestroy(() => this.connection?.disconnect());
  }

  async ngOnInit(): Promise<void> {
    const link = this.address.takeLink();
    try {
      this.held = link === null ? this.stored() : await this.api.claim(link);
    } catch (error) {
      this.stage.set({ refused: error instanceof PairingRefused ? error.reason : 'network' });
      return;
    }
    if (this.held === null) {
      this.stage.set({ refused: 'unknown' });
      return;
    }
    this.store(this.held);
    this.listen(this.held);
    this.stage.set('scanning');
  }

  protected submit(): void {
    const code = this.code.trim();
    this.code = '';
    if (code !== '') void this.send(code);
  }

  protected async send(code: string): Promise<void> {
    const held = this.held;
    if (held === null || this.stage() !== 'scanning') return;
    this.sent.set(code);
    try {
      await this.api.scan(held.id, held.key, code, crypto.randomUUID());
    } catch (error) {
      this.refused(error);
    }
  }

  protected async choose(echo: PairingEcho, choice: string): Promise<void> {
    const held = this.held;
    if (held === null) return;
    try {
      await this.api.choose(held.id, held.key, echo.id, choice);
    } catch (error) {
      this.refused(error);
    }
  }

  private listen(held: Held): void {
    this.connection = this.connector(
      this.address.socket(),
      () => this.api.realtimeToken(held.id, held.key),
      (data) => this.heard(data),
    );
  }

  private heard(data: unknown): void {
    if (typeof data !== 'object' || data === null) return;
    const type = (data as { type?: unknown }).type;
    if (type === 'ended') {
      this.stop();
    } else if (type === 'echo') {
      // Held to its shape by the API before it was published: the computer cannot send the phone anything else.
      const echo = data as PairingEcho;
      this.echoes.update((echoes) => [echo, ...echoes].slice(0, ECHOES_KEPT));
    }
  }

  /** Anything but a lost network means this pairing is over: the computer let it go, or the key is not this one. */
  private refused(error: unknown): void {
    if (error instanceof PairingRefused && error.reason !== 'network') this.stop();
  }

  private stop(): void {
    this.connection?.disconnect();
    this.connection = null;
    this.held = null;
    try {
      this.storage.removeItem(PAIRING_STORAGE_KEY);
    } catch {
      // Storage refused: the key it held is dead on the API's side anyway.
    }
    this.stage.set('ended');
  }

  private stored(): Held | null {
    try {
      const raw = this.storage.getItem(PAIRING_STORAGE_KEY);
      const held = raw === null ? null : (JSON.parse(raw) as Partial<Held>);
      return typeof held?.id === 'string' && typeof held.key === 'string'
        ? { id: held.id, key: held.key }
        : null;
    } catch {
      return null;
    }
  }

  private store(held: Held): void {
    try {
      this.storage.setItem(PAIRING_STORAGE_KEY, JSON.stringify(held));
    } catch {
      // Storage refused: a reload of this tab will need a new link.
    }
  }
}
