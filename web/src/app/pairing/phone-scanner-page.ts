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
import { PhotoShrinker } from '../shared/scan/photo-shrinker';
import { Feedback } from '../shared/feedback/feedback';

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
 * A send on its way, or kept after a lost network, with the id it was first sent with: the computer acts once on a
 * scan or photo id and once on an offered choice, so sending it again is safe.
 */
interface Outgoing {
  readonly id: string;
  readonly kind: 'scan' | 'photo' | 'choice';
  /** The code a scan sent, shown beside it. */
  readonly code: string | null;
  /** The echo a choice answers: once a newer one arrives the computer no longer takes it. */
  readonly echo: string | null;
  readonly state: 'sending' | 'unsent';
  readonly attempt: () => Promise<void>;
}

/**
 * A phone lent to a computer as a scanner, with no sign-in: it claims the link the computer showed, then scans — with
 * its camera or by hand — and each code acts once on the computer, under the computer's session; a photo it takes
 * goes to the computer the same way. What it shows back is only what the computer echoes: the outcome, the product's name and
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
  private readonly shrinker = inject(PhotoShrinker);
  private readonly feedback = inject(Feedback);
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
  protected readonly outgoing = signal<readonly Outgoing[]>([]);
  protected readonly unsent = computed(() =>
    this.outgoing().some((each) => each.state === 'unsent'),
  );
  private resending = false;
  /** Where the last photo the camera took stands: on its way, or why it was not sent; that it went is a toast. */
  protected readonly photo = signal<'idle' | 'sending' | 'unreadable' | 'refused'>('idle');
  protected code = '';

  constructor() {
    // Back online, what a drop kept goes again; an API that answers a later send does the same.
    const view = inject(DOCUMENT).defaultView;
    const online = () => void this.resend();
    view?.addEventListener('online', online);
    inject(DestroyRef).onDestroy(() => {
      this.connection?.disconnect();
      view?.removeEventListener('online', online);
    });
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
    const id = crypto.randomUUID();
    await this.deliver({
      id,
      kind: 'scan',
      code,
      echo: null,
      state: 'sending',
      attempt: () => this.api.scan(held.id, held.key, code, id),
    });
  }

  /** Sends again, in order and one at a time, what a lost network kept; stops at the first still not sent. */
  protected async resend(): Promise<void> {
    if (this.resending) return;
    this.resending = true;
    try {
      for (const entry of this.outgoing().filter((each) => each.state === 'unsent')) {
        if (this.stage() !== 'scanning') return;
        if (!this.outgoing().some((each) => each.id === entry.id && each.state === 'unsent'))
          continue;
        if (!(await this.deliver(entry))) return;
      }
    } finally {
      this.resending = false;
    }
  }

  /**
   * A photo the camera took, made smaller and sent to the computer, which says on the next echo what became of it. A
   * refused photo leaves the pairing as it was; a pairing that is unknown to the API or has ended ends it, as a scan's would.
   */
  protected async photographed(input: HTMLInputElement): Promise<void> {
    const taken = input.files?.[0];
    input.value = '';
    const held = this.held;
    if (taken === undefined || held === null || this.stage() !== 'scanning') return;
    this.photo.set('sending');
    let shrunk: Blob;
    try {
      shrunk = await this.shrinker.shrink(taken);
    } catch {
      this.photo.set('unreadable');
      return;
    }
    const id = crypto.randomUUID();
    await this.deliver({
      id,
      kind: 'photo',
      code: null,
      echo: null,
      state: 'sending',
      attempt: () => this.api.photo(held.id, held.key, id, shrunk),
    });
  }

  protected async choose(echo: PairingEcho, choice: string): Promise<void> {
    const held = this.held;
    if (held === null) return;
    const id = crypto.randomUUID();
    await this.deliver({
      id,
      kind: 'choice',
      code: null,
      echo: echo.id,
      state: 'sending',
      attempt: () => this.api.choose(held.id, held.key, echo.id, choice),
    });
  }

  /**
   * Sends one thing and says what became of it: gone once the API answered, kept unsent after a lost network, and a
   * refusal handled as before. Answers whether the next one may follow, which a lost network says it may not.
   */
  private async deliver(entry: Outgoing): Promise<boolean> {
    this.put({ ...entry, state: 'sending' });
    try {
      await entry.attempt();
    } catch (error) {
      if (error instanceof PairingRefused && error.reason === 'network') {
        if (entry.kind === 'photo') this.photo.set('idle');
        this.put({ ...entry, state: 'unsent' });
        return false;
      }
      this.drop(entry.id);
      if (entry.kind !== 'photo') {
        this.refused(error);
      } else {
        this.photo.set('refused');
        if (error instanceof PairingRefused && error.reason !== 'invalid') this.refused(error);
      }
      return true;
    }
    this.drop(entry.id);
    if (entry.kind === 'scan') this.sent.set(entry.code);
    if (entry.kind === 'photo') {
      this.photo.set('idle');
      this.feedback.success('scan.phone.page.photo_sent');
    }
    void this.resend();
    return true;
  }

  /** Adds or updates one send; nothing once the pairing is over, nor a choice the computer has moved on from. */
  private put(entry: Outgoing): void {
    if (this.stage() !== 'scanning') return;
    if (entry.kind === 'choice' && this.echoes()[0]?.id !== entry.echo) return;
    this.outgoing.update((all) =>
      all.some((each) => each.id === entry.id)
        ? all.map((each) => (each.id === entry.id ? entry : each))
        : [...all, entry],
    );
  }

  private drop(id: string): void {
    this.outgoing.update((all) => all.filter((each) => each.id !== id));
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
      this.outgoing.update((all) =>
        all.filter((each) => each.kind !== 'choice' || each.echo === echo.id),
      );
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
