// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';

/** One camera of this device, as the person picks it. */
export interface CameraDevice {
  readonly id: string;
  /** Empty until the person has allowed the camera once: browsers name no device before that. */
  readonly label: string;
}

/** Why the camera could not be opened, each said differently to the person. */
export type CameraRefusal = 'denied' | 'none' | 'busy' | 'unsupported';

export class CameraRefused extends Error {
  constructor(readonly reason: CameraRefusal) {
    super(`camera ${reason}`);
  }
}

/** The port to this device's cameras (docs/SPEC.md § 7, 2026-09-23 09:45, slice 3). */
export abstract class Camera {
  /** Whether this browser can open a camera at all: it needs a secure origin and the media devices API. */
  abstract available(): boolean;
  abstract devices(): Promise<readonly CameraDevice[]>;
  /** The chosen camera, or the one facing away from the person when none was chosen; throws `CameraRefused`. */
  abstract open(deviceId: string | null): Promise<MediaStream>;
}

@Injectable()
export class BrowserCamera extends Camera {
  available(): boolean {
    return (
      typeof navigator !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
  }

  async devices(): Promise<readonly CameraDevice[]> {
    const all = await navigator.mediaDevices.enumerateDevices();
    return all
      .filter((device) => device.kind === 'videoinput')
      .map((device) => ({ id: device.deviceId, label: device.label }));
  }

  async open(deviceId: string | null): Promise<MediaStream> {
    if (!this.available()) throw new CameraRefused('unsupported');
    try {
      return await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: {
          ...(deviceId === null
            ? { facingMode: { ideal: 'environment' } }
            : { deviceId: { exact: deviceId } }),
          width: { ideal: 1280 },
          height: { ideal: 720 },
        },
      });
    } catch (error) {
      throw new CameraRefused(refusalOf(error));
    }
  }
}

/** The browser's reasons, as the DOMException names them. */
function refusalOf(error: unknown): CameraRefusal {
  const name = error instanceof DOMException ? error.name : '';
  if (name === 'NotAllowedError' || name === 'SecurityError') return 'denied';
  if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'none';
  if (name === 'NotReadableError' || name === 'AbortError') return 'busy';
  return 'unsupported';
}
