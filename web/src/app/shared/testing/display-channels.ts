// SPDX-License-Identifier: AGPL-3.0-or-later

import type { DisplayChannel } from '../customer-display/customer-display';

/**
 * BroadcastChannel as specs need it: every channel opened with a name hears what another channel of that name posts,
 * never what it posts itself, and a message is copied as the browser copies it (structured clone).
 */
export class FakeDisplayChannels {
  private readonly open = new Set<{ name: string; hear: (data: unknown) => void }>();
  /** Every name a channel was opened with, in order. */
  readonly names: string[] = [];

  readonly opener = (name: string): DisplayChannel => {
    this.names.push(name);
    const self: { name: string; hear: (data: unknown) => void } = { name, hear: () => undefined };
    this.open.add(self);
    return {
      post: (data) => {
        const copy = structuredClone(data);
        for (const other of [...this.open]) {
          if (other !== self && other.name === name) other.hear(copy);
        }
      },
      listen: (hear) => {
        self.hear = hear;
      },
      close: () => {
        this.open.delete(self);
      },
    };
  };

  /** How many channels are open now. */
  get count(): number {
    return this.open.size;
  }
}
