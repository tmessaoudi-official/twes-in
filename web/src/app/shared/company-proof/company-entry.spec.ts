// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MatDialog } from '@angular/material/dialog';
import { Subject } from 'rxjs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CompanyEntry } from './company-entry';
import { CompanyEntryProof, type EntryAsked } from './company-entry-proof';

describe('CompanyEntry', () => {
  const globex: EntryAsked = {
    companyId: 'c2',
    companyName: 'Globex',
    passkey: false,
    code: true,
    password: true,
  };
  const asked = signal<EntryAsked | null>(null);
  const reread = vi.fn<() => Promise<void>>();
  const open = vi.fn();
  let closed: Subject<boolean | undefined>;

  function answer(given: boolean | undefined): void {
    closed.next(given);
    closed.complete();
  }

  /** Lets the dialog's lazy import and the awaits before it settle. */
  async function opened(): Promise<void> {
    await vi.waitFor(() => expect(open).toHaveBeenCalled());
  }

  beforeEach(() => {
    asked.set(globex);
    closed = new Subject<boolean | undefined>();
    reread.mockReset().mockImplementation(async () => undefined);
    open.mockReset().mockImplementation(() => ({ afterClosed: () => closed }));
    TestBed.configureTestingModule({
      providers: [
        { provide: CompanyEntryProof, useValue: { asked, reread } },
        { provide: MatDialog, useValue: { open } },
      ],
    });
  });

  const entry = () => TestBed.inject(CompanyEntry);

  it('asks once for every request refused together, with what the company asks, and reads the session after', async () => {
    reread.mockImplementation(async () => asked.set(null));
    const first = entry().prove();
    const second = entry().prove();
    await opened();
    expect(open).toHaveBeenCalledOnce();
    expect(open.mock.calls[0][1]).toMatchObject({ data: globex });

    answer(true);

    await expect(first).resolves.toBe(true);
    await expect(second).resolves.toBe(true);
    expect(reread).toHaveBeenCalledOnce();
  });

  it('does not ask again after it was turned down, until the person asks for it', async () => {
    const turnedDown = entry().prove();
    await opened();
    answer(undefined);
    await expect(turnedDown).resolves.toBe(false);
    expect(entry().declined()).toBe(true);

    await expect(entry().prove()).resolves.toBe(false);
    expect(open).toHaveBeenCalledOnce();

    closed = new Subject<boolean | undefined>();
    const again = entry().prove(true);
    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(2));
    answer(true);
    await expect(again).resolves.toBe(true);
    expect(entry().declined()).toBe(false);
  });

  it('reads the session first when a refusal arrives for a proof that lapsed, and asks nothing when none is asked', async () => {
    asked.set(null);
    reread.mockImplementationOnce(async () => asked.set(globex));
    const lapsed = entry().prove();
    await opened();
    expect(reread).toHaveBeenCalledOnce();
    answer(true);
    await expect(lapsed).resolves.toBe(true);

    asked.set(null);
    reread.mockReset().mockImplementation(async () => undefined);
    await expect(entry().prove()).resolves.toBe(false);
    expect(open).toHaveBeenCalledOnce();
  });

  it('keeps the page open under a proof that lapsed, and asks again on the next refusal once turned down', async () => {
    asked.set(null);
    reread.mockImplementation(async () => {
      asked.set(globex);
      // Read again, the session says the proof is asked: the page stays while that is found out.
      expect(entry().blocking()).toBe(false);
    });
    const lapsed = entry().prove();
    await opened();
    expect(entry().blocking()).toBe(false);
    answer(false);
    await expect(lapsed).resolves.toBe(false);
    expect(entry().blocking()).toBe(false);
    expect(entry().declined()).toBe(false);

    closed = new Subject<boolean | undefined>();
    void entry().prove();
    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(2));
  });

  it('holds the pages of a company just entered until it is proved', () => {
    expect(entry().blocking()).toBe(true);
    asked.set(null);
    expect(entry().blocking()).toBe(false);
  });

  it('asks a company switched to even after another was turned down', async () => {
    const turnedDown = entry().prove();
    await opened();
    answer(false);
    await turnedDown;

    asked.set({ ...globex, companyId: 'c3', companyName: 'Initech' });
    closed = new Subject<boolean | undefined>();
    void entry().prove();
    await vi.waitFor(() => expect(open).toHaveBeenCalledTimes(2));
    expect(open.mock.calls[1][1]).toMatchObject({ data: { companyName: 'Initech' } });
  });
});
