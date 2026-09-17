// SPDX-License-Identifier: AGPL-3.0-or-later

import { InjectionToken } from '@angular/core';
import { Centrifuge } from 'centrifuge';

export interface RealtimeConnection {
  disconnect(): void;
}

/**
 * Opens one realtime connection. The server subscribes it to its channels from the token (docs/SPEC.md § 7,
 * 2026-09-13), so the caller names no channel: it hears what was published on any of them.
 */
export type RealtimeConnector = (
  url: string,
  getToken: () => Promise<string>,
  onPublication: (data: unknown) => void,
) => RealtimeConnection;

/** Centrifugo's own client: asks for a token when it connects and again whenever the token expires. */
export const centrifugeConnector: RealtimeConnector = (url, getToken, onPublication) => {
  const client = new Centrifuge(url, { getToken: () => getToken() });
  client.on('publication', (context) => onPublication(context.data));
  client.connect();
  return { disconnect: () => client.disconnect() };
};

export const REALTIME_CONNECTOR = new InjectionToken<RealtimeConnector>('REALTIME_CONNECTOR', {
  providedIn: 'root',
  factory: () => centrifugeConnector,
});
