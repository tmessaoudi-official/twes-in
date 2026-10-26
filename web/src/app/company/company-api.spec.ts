// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused, companyLogoUrl } from './company-api';
import type { CompanyProfileChanges } from './company-types';
import { saidAlready } from '../shared/errors/said-already';

const changes: CompanyProfileChanges = {
  legalName: 'Demo SARL',
  legalForm: null,
  identifiers: { matricule_fiscal: '1234567' },
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: null,
  email: null,
  phone: null,
  website: null,
  iban: null,
  bic: null,
  vatRegime: 'standard',
  vatOnDebits: false,
  invoiceFooterText: null,
  latePenaltyText: null,
};

describe('CompanyApi, the profile', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads a profile whose absent values the API left out', async () => {
    const reading = api.profile('c1');
    http.expectOne({ method: 'GET', url: '/api/companies/c1/profile' }).flush({
      name: 'Demo',
      countryCode: 'TN',
      writable: true,
      identifiers: {},
      vatRegime: 'standard',
      identifierFields: [
        { key: 'matricule_fiscal', label: 'Matricule fiscal', pattern: '^x$', required: true },
      ],
      vatRegimes: [{ code: 'standard', label: 'Régime normal' }],
    });

    const profile = await reading;
    expect(profile.legalName).toBeNull();
    expect(profile.iban).toBeNull();
    expect(profile.identifiers).toEqual({});
    expect(profile.identifierFields[0]?.required).toBe(true);
    expect(profile.vatRegimes).toEqual([{ code: 'standard', label: 'Régime normal' }]);
    expect([profile.vatOnDebits, profile.offersVatOnDebits]).toEqual([false, false]);
  });

  it('names a value the preset refused as invalid, not as a member error', async () => {
    const revising = api.reviseProfile('c1', changes, 4);
    const request = http.expectOne({ method: 'PUT', url: '/api/companies/c1/profile' });
    expect(request.request.body).toEqual({ ...changes, version: 4 });
    request.flush(
      { detail: 'identifiers.matricule_fiscal: shape' },
      { status: 422, statusText: 'Unprocessable' },
    );

    await expect(revising).rejects.toEqual(new CompanyRefused('invalid'));
  });

  it('names a revision of a version saved since as changed, and another conflict as invalid', async () => {
    const changed = api.reviseProfile('c1', changes, 1);
    http
      .expectOne({ method: 'PUT', url: '/api/companies/c1/profile' })
      .flush(
        { type: '/problems/record-changed', status: 409 },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(changed).rejects.toEqual(new CompanyRefused('record_changed'));

    const other = api.reviseProfile('c1', changes, 1);
    http
      .expectOne({ method: 'PUT', url: '/api/companies/c1/profile' })
      .flush(null, { status: 409, statusText: 'Conflict' });
    await expect(other).rejects.toEqual(new CompanyRefused('invalid'));
  });

  it('names each field a revision was refused on, a registration number on its own field', async () => {
    const revising = api.reviseProfile('c1', changes, 1);
    http.expectOne({ method: 'PUT', url: '/api/companies/c1/profile' }).flush(
      {
        violations: [
          {
            propertyPath: 'identifiers.matricule_fiscal',
            message: 'x',
            payload: { reason: 'identifier_shape', params: { identifier: 'matricule_fiscal' } },
          },
          { propertyPath: 'email', message: 'x', payload: { reason: 'invalid_email' } },
        ],
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );

    await expect(revising).rejects.toMatchObject({
      code: 'invalid',
      refusals: [
        {
          field: 'identifier__matricule_fiscal',
          reason: 'identifier_shape',
          params: { identifier: 'matricule_fiscal' },
        },
        { field: 'email', reason: 'invalid_email', params: {} },
      ],
    });
  });
});

describe('CompanyApi, the members', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('changes a member’s role by its own address, answering their row', async () => {
    const changing = api.changeMemberRole('c1', 'u2', 'admin');
    const request = http.expectOne({ method: 'PUT', url: '/api/companies/c1/members/u2/role' });
    expect(request.request.body).toEqual({ role: 'admin' });
    request.flush({ userId: 'u2', email: 'a@example.test', role: 'admin', status: 'joined' });

    expect((await changing).role).toBe('admin');
  });

  it('names a removal the actor role does not reach as forbidden, not as invalid', async () => {
    const removing = api.removeMember('c1', 'u2');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' })
      .flush(
        { detail: 'Your role in this company does not remove an owner.' },
        { status: 403, statusText: 'Forbidden' },
      );

    await expect(removing).rejects.toEqual(new CompanyRefused('forbidden'));
  });

  it('tells the last owner from a member already there by its reason, never by a word of the detail', async () => {
    const last = api.removeMember('c1', 'u2');
    http.expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' }).flush(
      {
        detail: 'member: A company keeps at least one owner.',
        violations: [{ propertyPath: 'member', message: 'x', payload: { reason: 'last_owner' } }],
      },
      { status: 409, statusText: 'Conflict' },
    );
    await expect(last).rejects.toEqual(new CompanyRefused('last_owner'));

    const own = api.removeMember('c1', 'u2');
    http.expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' }).flush(
      {
        detail: 'member: Nobody removes themselves from a company.',
        violations: [
          { propertyPath: 'member', message: 'x', payload: { reason: 'own_membership' } },
        ],
      },
      { status: 409, statusText: 'Conflict' },
    );
    await expect(own).rejects.toEqual(new CompanyRefused('own_membership'));

    const already = api.removeMember('c1', 'u2');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' })
      .flush(
        { detail: 'This owner is already a member.' },
        { status: 409, statusText: 'Conflict' },
      );
    await expect(already).rejects.toEqual(new CompanyRefused('already_member'));
  });

  it("keeps the demo's answer under its refusal of an invitation, which the screen leaves to the toast", async () => {
    const adding = api.addMember('c1', 'someone@example.test', 'staff');
    http
      .expectOne({ method: 'POST', url: '/api/companies/c1/members' })
      .flush({ error: 'demo_refused' }, { status: 403, statusText: 'Forbidden' });

    await expect(adding).rejects.toSatisfy(
      (error) => error instanceof CompanyRefused && saidAlready(error),
    );
  });

  // docs/SPEC.md § 7, the step-up list: the refusal names its token first, for the screen to ask for the proof.
  it('tells a member change and new bank details that wait for the step-up proof, by its token', async () => {
    const detail = 'step_up_required: confirm the password or a passkey again before this change.';
    const removing = api.removeMember('c1', 'u2');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/members/u2' })
      .flush({ detail }, { status: 403, statusText: 'Forbidden' });
    await expect(removing).rejects.toEqual(new CompanyRefused('step_up_required'));

    const saving = api.reviseProfile('c1', {} as never, 1);
    http
      .expectOne({ method: 'PUT', url: '/api/companies/c1/profile' })
      .flush({ detail }, { status: 403, statusText: 'Forbidden' });
    await expect(saving).rejects.toEqual(new CompanyRefused('step_up_required'));
  });
});

// docs/SPEC.md § 7, 2026-09-25 09:03: « Société à l'ouverture », the company every sign-in opens.
describe('CompanyApi, the company a sign-in opens', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('says which company is pinned', async () => {
    const reading = api.companies();
    http.expectOne({ method: 'GET', url: '/api/me/companies' }).flush([
      { companyId: 'c1', name: 'Acme', status: 'active', role: 'owner', pinned: true },
      { companyId: 'c2', name: 'Globex', status: 'active', role: 'member' },
    ]);
    expect((await reading).map((company) => [company.id, company.pinned])).toEqual([
      ['c1', true],
      ['c2', false],
    ]);
  });

  it('says until when support may read a company, and nothing for a membership', async () => {
    const reading = api.companies();
    http.expectOne({ method: 'GET', url: '/api/me/companies' }).flush([
      { companyId: 'c1', name: 'Acme', status: 'active', role: 'owner' },
      {
        companyId: 'c2',
        name: 'Atlas',
        status: 'active',
        role: 'support',
        supportAccessUntil: '2026-10-27T10:00:00+01:00',
        supportAccessReason: 'La facture du 3 mars',
      },
    ]);
    expect(
      (await reading).map((company) => [
        company.id,
        company.supportAccessUntil,
        company.supportAccessReason,
      ]),
    ).toEqual([
      ['c1', null, null],
      ['c2', '2026-10-27T10:00:00+01:00', 'La facture du 3 mars'],
    ]);
  });

  it('pins a company, or none for the one last worked in', async () => {
    const pinning = api.pinAtSignIn('c2');
    const request = http.expectOne({ method: 'PUT', url: '/api/me/company-at-sign-in' });
    expect(request.request.body).toEqual({ companyId: 'c2' });
    request.flush({ companyId: 'c2' });
    await pinning;

    const unpinning = api.pinAtSignIn(null);
    const unpin = http.expectOne({ method: 'PUT', url: '/api/me/company-at-sign-in' });
    expect(unpin.request.body).toEqual({ companyId: null });
    unpin.flush({ companyId: null });
    await unpinning;
  });

  it('leaves the company the session works in, by deleting it from the session', async () => {
    const leaving = api.leave();
    http
      .expectOne({ method: 'DELETE', url: '/api/me/company' })
      .flush(null, { status: 204, statusText: 'No Content' });
    await leaving;
  });
});

describe('CompanyApi, the logo', () => {
  let api: CompanyApi;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    api = TestBed.inject(CompanyApi);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends the picture as a multipart part named file and answers the new version', async () => {
    const file = new File(['png'], 'logo.png', { type: 'image/png' });
    const sending = api.uploadLogo('c1', file);
    const request = http.expectOne({ method: 'POST', url: '/api/companies/c1/logo' });
    expect((request.request.body as FormData).get('file')).toBeInstanceOf(File);
    request.flush({ logoVersion: 'v2' }, { status: 201, statusText: 'Created' });

    expect(await sending).toBe('v2');
  });

  it("says a picture the API does not keep is refused, from its 422 or the proxy's 413", async () => {
    for (const status of [422, 413]) {
      const sending = api.uploadLogo('c1', new File(['x'], 'logo.png'));
      http
        .expectOne({ method: 'POST', url: '/api/companies/c1/logo' })
        .flush('', { status, statusText: 'Refused' });
      await expect(sending).rejects.toEqual(new CompanyRefused('logo_refused'));
    }
  });

  it('keeps the usual codes for the other failures', async () => {
    const missing = api.removeLogo('c1');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/logo' })
      .flush('', { status: 404, statusText: 'Not Found' });
    await expect(missing).rejects.toEqual(new CompanyRefused('not_found'));

    const down = api.removeLogo('c1');
    http
      .expectOne({ method: 'DELETE', url: '/api/companies/c1/logo' })
      .error(new ProgressEvent('error'));
    await expect(down).rejects.toEqual(new CompanyRefused('network'));
  });

  it('reads the logo shown as a file the upload takes again, named by its kind', async () => {
    const reading = api.logoFile('c1', 'v1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/logo?v=v1' })
      .flush(new Blob(['jpg'], { type: 'image/jpeg' }));
    const file = await reading;
    expect([file.name, file.type]).toEqual(['logo.jpg', 'image/jpeg']);
  });

  it('names the logo by its version, so a changed one is a new address', () => {
    expect(companyLogoUrl('c 1', 'v/2')).toBe('/api/companies/c%201/logo?v=v%2F2');
  });

  it('reads the version the profile names, and none when the company has no logo', async () => {
    const reading = api.profile('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/profile' })
      .flush({ name: 'Demo', countryCode: 'TN', logoVersion: 'v1' });
    expect((await reading).logoVersion).toBe('v1');

    const without = api.profile('c1');
    http
      .expectOne({ method: 'GET', url: '/api/companies/c1/profile' })
      .flush({ name: 'Demo', countryCode: 'TN' });
    expect((await without).logoVersion).toBeNull();
  });
});
