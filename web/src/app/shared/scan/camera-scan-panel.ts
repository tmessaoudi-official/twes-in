// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  ElementRef,
  inject,
  type OnInit,
  signal,
  viewChild,
} from '@angular/core';
import { MatButtonModule } from '@angular/material/button';
import { MatFormFieldModule } from '@angular/material/form-field';
import { MatInputModule } from '@angular/material/input';
import { MatDialogRef } from '@angular/material/dialog';
import { MatIconModule } from '@angular/material/icon';
import { TranslatePipe } from '@ngx-translate/core';
import { Label } from '../a11y/label';
import { SETTINGS_STORAGE } from '../settings/settings-facade';
import { BarcodeReader } from './barcode-reader';
import { Camera, type CameraDevice, CameraRefused, type CameraRefusal } from './camera';
import { CameraScanning } from './camera-scanning';
import { ScanBus } from './scan-bus';

/** Where this browser remembers the camera picked last (a per-viewer convenience, never needed to work). */
export const CAMERA_CHOICE_KEY = 'twes.scan.camera';

/** How often a frame is read: fast enough to feel instant, slow enough to leave the page its breath. */
const FRAME_EVERY_MS = 150;

/** The widest frame handed to the decoder; a larger one costs time and reads no better. */
const FRAME_MAX_WIDTH = 960;

/**
 * This device's camera as a scanner that keeps scanning (docs/SPEC.md § 7, 2026-09-23 09:45, slice 3): a small panel
 * that leaves the page usable beside it, the camera's picture, a choice of camera, and each code read sent where a
 * handheld scanner's goes — the screen on view acts on it, a beep and a buzz say it was read.
 */
@Component({
  selector: 'app-camera-scan-panel',
  imports: [
    MatButtonModule,
    MatFormFieldModule,
    MatInputModule,
    MatIconModule,
    TranslatePipe,
    Label,
  ],
  templateUrl: './camera-scan-panel.html',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CameraScanPanel implements OnInit {
  private readonly camera = inject(Camera);
  private readonly reader = inject(BarcodeReader);
  private readonly scans = inject(ScanBus);
  private readonly storage = inject(SETTINGS_STORAGE);
  protected readonly dialog = inject(MatDialogRef<CameraScanPanel>);
  private readonly video = viewChild.required<ElementRef<HTMLVideoElement>>('video');

  protected readonly devices = signal<readonly CameraDevice[]>([]);
  protected readonly chosen = signal<string | null>(null);
  protected readonly refused = signal<CameraRefusal | null>(null);
  protected readonly lastRead = signal<string | null>(null);

  private stream: MediaStream | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;
  private reading = false;
  private closed = false;
  private wakeLock: { release(): Promise<void> } | null = null;
  private readonly canvas = document.createElement('canvas');
  private readonly scanning = new CameraScanning({
    now: () => performance.now(),
    read: () => this.readFrame(),
    accept: (code) => this.accept(code),
  });

  constructor() {
    inject(DestroyRef).onDestroy(() => {
      this.closed = true;
      this.stop();
      if (this.timer !== null) clearTimeout(this.timer);
      void this.wakeLock?.release().catch(() => undefined);
      document.removeEventListener('visibilitychange', this.keepAwake);
    });
  }

  async ngOnInit(): Promise<void> {
    await this.start(this.storage.getItem(CAMERA_CHOICE_KEY));
    document.addEventListener('visibilitychange', this.keepAwake);
    void this.keepAwake();
    this.tick();
  }

  protected async choose(deviceId: string): Promise<void> {
    try {
      this.storage.setItem(CAMERA_CHOICE_KEY, deviceId);
    } catch {
      // Storage full or refused: the choice holds until the panel closes.
    }
    await this.start(deviceId);
  }

  private async start(deviceId: string | null): Promise<void> {
    this.stop();
    try {
      this.stream = await this.camera.open(deviceId);
    } catch (error) {
      const reason = error instanceof CameraRefused ? error.reason : 'unsupported';
      // The camera remembered is gone (unplugged, another device): any camera does.
      if (reason === 'none' && deviceId !== null) return this.start(null);
      this.refused.set(reason);
      return;
    }
    if (this.closed) {
      this.stop();
      return;
    }
    this.refused.set(null);
    this.chosen.set(deviceId);
    const video = this.video().nativeElement;
    video.srcObject = this.stream;
    void video.play?.()?.catch(() => undefined);
    // Named only once the person has allowed a camera: asked again now that they have.
    this.devices.set(await this.camera.devices());
  }

  private stop(): void {
    for (const track of this.stream?.getTracks() ?? []) track.stop();
    this.stream = null;
  }

  private tick(): void {
    if (this.closed) return;
    this.timer = setTimeout(async () => {
      if (!this.reading) {
        this.reading = true;
        await this.scanning.frame();
        this.reading = false;
      }
      this.tick();
    }, FRAME_EVERY_MS);
  }

  private async readFrame(): Promise<readonly string[]> {
    const video = this.video().nativeElement;
    if (this.stream === null || video.videoWidth === 0) return [];
    const scale = Math.min(1, FRAME_MAX_WIDTH / video.videoWidth);
    this.canvas.width = Math.round(video.videoWidth * scale);
    this.canvas.height = Math.round(video.videoHeight * scale);
    const context = this.canvas.getContext('2d', { willReadFrequently: true });
    if (context === null) return [];
    context.drawImage(video, 0, 0, this.canvas.width, this.canvas.height);
    return this.reader.read(context.getImageData(0, 0, this.canvas.width, this.canvas.height));
  }

  private accept(code: string): void {
    this.lastRead.set(code);
    signalRead();
    void this.scans.receive(code, 'camera');
  }

  /** The screen stays on while the camera scans; the browser drops the lock when the page is hidden. */
  private readonly keepAwake = async (): Promise<void> => {
    if (this.closed || document.visibilityState !== 'visible') return;
    const wakeLock = (
      navigator as { wakeLock?: { request(type: 'screen'): Promise<{ release(): Promise<void> }> } }
    ).wakeLock;
    try {
      this.wakeLock = (await wakeLock?.request('screen')) ?? null;
    } catch {
      // Refused (battery saver, an unsupported browser): the camera scans all the same.
      this.wakeLock = null;
    }
  };
}

/** A short beep and a buzz, as a handheld scanner gives: the person looks at the goods, not the screen. */
function signalRead(): void {
  navigator.vibrate?.(40);
  const Audio = (window as { AudioContext?: typeof AudioContext }).AudioContext;
  if (Audio === undefined) return;
  try {
    const audio = new Audio();
    const tone = audio.createOscillator();
    const volume = audio.createGain();
    tone.frequency.value = 1800;
    volume.gain.value = 0.08;
    tone.connect(volume).connect(audio.destination);
    tone.start();
    tone.stop(audio.currentTime + 0.08);
    tone.onended = () => void audio.close();
  } catch {
    // No audio output: the buzz and the screen say it.
  }
}
