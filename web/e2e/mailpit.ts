// SPDX-License-Identifier: AGPL-3.0-or-later
import type { APIRequestContext } from '@playwright/test';

export const MAILPIT = process.env['MAILPIT_URL'] ?? 'http://127.0.0.1:8092';

interface Listed {
  ID: string;
  Subject: string;
  To: { Address: string }[];
}

/** Polls Mailpit until a mail to that address whose subject passes the check has arrived, and returns its HTML. */
export async function mailTo(
  request: APIRequestContext,
  to: string,
  subject: (subject: string) => boolean = () => true,
): Promise<string> {
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const listed = await request.get(`${MAILPIT}/api/v1/messages`);
    const { messages } = (await listed.json()) as { messages: Listed[] };
    const mine = messages.find(
      (message) => message.To.some((address) => address.Address === to) && subject(message.Subject),
    );
    if (mine) {
      const body = (await (await request.get(`${MAILPIT}/api/v1/message/${mine.ID}`)).json()) as {
        HTML?: string;
      };
      return String(body.HTML ?? '');
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`no matching mail for ${to} arrived at Mailpit`);
}

/**
 * The raw token of the newest link of that kind mailed to that address. A token already used is passed as `unlike`
 * when a second mail is awaited: the worker sends mail asynchronously, so until the second one arrives the newest
 * mail is still the first. Mails of other kinds are passed over: a member is also mailed what their companies are
 * told, and one of those can arrive after the link.
 */
async function tokenFor(
  request: APIRequestContext,
  to: string,
  path: 'invitations' | 'signup',
  unlike?: string,
): Promise<string> {
  const pattern = new RegExp(`/${path}/([0-9a-f]{64})`);
  for (let attempt = 0; attempt < 20; attempt += 1) {
    const listed = await request.get(`${MAILPIT}/api/v1/messages`);
    const { messages } = (await listed.json()) as { messages: Listed[] };
    // Mailpit lists the newest first.
    for (const message of messages.filter((one) =>
      one.To.some((address) => address.Address === to),
    )) {
      const body = (await (
        await request.get(`${MAILPIT}/api/v1/message/${message.ID}`)
      ).json()) as {
        HTML?: string;
      };
      const found = pattern.exec(String(body.HTML ?? ''));
      if (found) {
        if (found[1] !== unlike) return found[1];
        break;
      }
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`no new /${path}/ link to ${to} arrived at Mailpit`);
}

export function invitationTokenFor(
  request: APIRequestContext,
  to: string,
  unlike?: string,
): Promise<string> {
  return tokenFor(request, to, 'invitations', unlike);
}

export function signupTokenFor(request: APIRequestContext, to: string): Promise<string> {
  return tokenFor(request, to, 'signup');
}
