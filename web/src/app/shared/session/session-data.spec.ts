// SPDX-License-Identifier: AGPL-3.0-or-later

import { Injectable } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SessionData, sessionSignal } from './session-data';

@Injectable({ providedIn: 'root' })
class Held {
  readonly rows = sessionSignal<readonly string[]>([]);
  readonly companies = sessionSignal<readonly string[]>([], 'person');
}

const amel = (company: string | null) => ({
  user: { id: 'amel' },
  company: company === null ? null : { id: company },
});

describe('SessionData', () => {
  let session: SessionData;
  let held: Held;

  beforeEach(() => {
    session = TestBed.inject(SessionData);
    held = TestBed.inject(Held);
  });

  function fill(): void {
    held.rows.set(['an invoice of Acme']);
    held.companies.set(['Acme', 'Globex']);
  }

  it('forgets everything a person held when they sign out', () => {
    session.heard(amel('acme'));
    fill();

    session.heard(null);

    expect(held.rows()).toEqual([]);
    expect(held.companies()).toEqual([]);
  });

  it('forgets everything when another person signs in without a sign-out heard', () => {
    session.heard(amel('acme'));
    fill();

    session.heard({ user: { id: 'karim' }, company: { id: 'acme' } });

    expect(held.rows()).toEqual([]);
    expect(held.companies()).toEqual([]);
  });

  it('forgets what a company held when the session moves to another, and keeps the person’s companies', () => {
    session.heard(amel('acme'));
    fill();

    session.heard(amel('globex'));

    expect(held.rows()).toEqual([]);
    expect(held.companies()).toEqual(['Acme', 'Globex']);
  });

  it('forgets nothing when the session is read again unchanged', () => {
    session.heard(amel('acme'));
    fill();

    session.heard(amel('acme'));

    expect(held.rows()).toEqual(['an invoice of Acme']);
  });

  it('forgets nothing while nobody was signed in: a refused sign-in keeps what the page read', () => {
    session.heard(null);
    fill();

    session.heard(null);

    expect(held.rows()).toEqual(['an invoice of Acme']);
    expect(held.companies()).toEqual(['Acme', 'Globex']);
  });

  it('forgets nothing when a company is first chosen', () => {
    session.heard(amel(null));
    fill();

    session.heard(amel('acme'));

    expect(held.rows()).toEqual(['an invoice of Acme']);
  });
});
