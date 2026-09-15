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

/** The raw token of the newest link of that kind mailed to that address. */
async function tokenFor(
  request: APIRequestContext,
  to: string,
  path: 'invitations' | 'signup',
): Promise<string> {
  const pattern = new RegExp(`/${path}/([0-9a-f]{64})`);
  const found = pattern.exec(await mailTo(request, to, () => true));
  if (!found) {
    throw new Error(`the mail to ${to} carries no /${path}/ link`);
  }
  return found[1];
}

export function invitationTokenFor(request: APIRequestContext, to: string): Promise<string> {
  return tokenFor(request, to, 'invitations');
}

export function signupTokenFor(request: APIRequestContext, to: string): Promise<string> {
  return tokenFor(request, to, 'signup');
}
