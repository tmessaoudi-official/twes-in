// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * How long a code must have been out of the camera's view before it counts again. A camera sees the same label on
 * every frame for as long as it is held up, where a handheld scanner reads it once per trigger; a till counts the
 * second item when it is presented again, so the label must first have left the view. Short enough for a cashier's
 * pace, long enough that a frame or two lost to blur is not a new item.
 */
export const CAMERA_ABSENT_MS = 700;

/** What the scanning loop needs from the world: a clock, a reading of the current frame, and where a code goes. */
export interface CameraScanningPorts {
  readonly now: () => number;
  /** The codes on the frame showing now, composed as a handheld scanner would type them. */
  readonly read: () => Promise<readonly string[]>;
  readonly accept: (code: string) => void;
}

/**
 * The camera as a scanner that never stops (docs/SPEC.md § 7, 2026-09-23 09:45, slice 3): each frame is read, and a
 * code counts when it comes into view — once while it stays there, again once it has left and come back.
 */
export class CameraScanning {
  /** When each code was last seen on a frame. */
  private readonly lastSeen = new Map<string, number>();

  constructor(private readonly ports: CameraScanningPorts) {}

  /** Reads one frame. A frame that cannot be read is skipped: the next one is a moment away. */
  async frame(): Promise<void> {
    let codes: readonly string[];
    try {
      codes = await this.ports.read();
    } catch {
      return;
    }
    const now = this.ports.now();
    for (const code of new Set(codes)) {
      const last = this.lastSeen.get(code);
      this.lastSeen.set(code, now);
      if (last === undefined || now - last >= CAMERA_ABSENT_MS) this.ports.accept(code);
    }
  }
}
