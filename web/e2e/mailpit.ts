// SPDX-License-Identifier: AGPL-3.0-or-later
import type { APIRequestContext } from '@playwright/test';

export const MAILPIT = process.env['MAILPIT_URL'] ?? 'http://127.0.0.1:8092';

/** Polls Mailpit until the invitation for that address has arrived, and returns the raw token from its link. */
export async function invitationTokenFor(request: APIRequestContext, to: string): Promise<string> {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const listed = await request.get(`${MAILPIT}/api/v1/messages`);
    const { messages } = (await listed.json()) as {
      messages: { ID: string; To: { Address: string }[] }[];
    };
    const mine = messages.find((message) => message.To.some((address) => address.Address === to));
    if (mine) {
      const body = (await (await request.get(`${MAILPIT}/api/v1/message/${mine.ID}`)).json()) as {
        HTML?: string;
      };
      const found = /\/invitations\/([0-9a-f]{64})/.exec(String(body.HTML ?? ''));
      if (found) {
        return found[1];
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`no invitation mail for ${to} arrived at Mailpit`);
}
