// SPDX-License-Identifier: AGPL-3.0-or-later

import { TestBed } from '@angular/core/testing';
import { CompanyApi, CompanyRefused } from './company-api';
import { CompanyProfileFacade } from './company-profile-facade';
import type { CompanyProfile, CompanyProfileChanges } from './company-types';

const changes: CompanyProfileChanges = {
  legalName: 'Demo SARL',
  legalForm: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
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
const saved: CompanyProfile = {
  ...changes,
  offersVatOnDebits: false,
  name: 'Demo',
  countryCode: 'TN',
  writable: true,
  logoVersion: null,
  identifierFields: [],
  vatRegimes: [{ code: 'standard', label: 'Régime normal' }],
};

describe('CompanyProfileFacade', () => {
  const api = {
    profile: vi.fn(),
    reviseProfile: vi.fn(),
    uploadLogo: vi.fn(),
    removeLogo: vi.fn(),
  };
  let facade: CompanyProfileFacade;

  beforeEach(() => {
    Object.values(api).forEach((fn) => fn.mockReset());
    TestBed.configureTestingModule({ providers: [{ provide: CompanyApi, useValue: api }] });
    facade = TestBed.inject(CompanyProfileFacade);
  });

  it('reads the profile of the company it was given', async () => {
    api.profile.mockResolvedValue(saved);

    await facade.load('c1');

    expect(api.profile).toHaveBeenCalledWith('c1');
    expect(facade.profile()).toEqual(saved);
    expect(facade.error()).toBeNull();
  });

  it('shows what the API kept after a revision', async () => {
    api.reviseProfile.mockResolvedValue(saved);

    expect(await facade.save('c1', changes)).toBe(true);

    expect(api.reviseProfile).toHaveBeenCalledWith('c1', changes);
    expect(facade.profile()).toEqual(saved);
  });

  it('names what the API refused and keeps the profile it had', async () => {
    api.profile.mockResolvedValue(saved);
    await facade.load('c1');
    api.reviseProfile.mockRejectedValue(new CompanyRefused('invalid'));

    expect(await facade.save('c1', changes)).toBe(false);

    expect(facade.error()).toBe('invalid');
    expect(facade.profile()).toEqual(saved);
  });

  it('names the new logo on the profile it holds, and none once it is removed', async () => {
    api.profile.mockResolvedValue(saved);
    await facade.load('c1');
    api.uploadLogo.mockResolvedValue('v2');
    api.removeLogo.mockResolvedValue(undefined);

    expect(await facade.uploadLogo('c1', new File(['x'], 'logo.png'))).toBe(true);
    expect(facade.profile()?.logoVersion).toBe('v2');

    expect(await facade.removeLogo('c1')).toBe(true);
    expect(facade.profile()?.logoVersion).toBeNull();
  });

  it('keeps the logo it had when the picture is refused, and says why', async () => {
    api.profile.mockResolvedValue({ ...saved, logoVersion: 'v1' });
    await facade.load('c1');
    api.uploadLogo.mockRejectedValue(new CompanyRefused('logo_refused'));

    expect(await facade.uploadLogo('c1', new File(['x'], 'logo.png'))).toBe(false);

    expect(facade.error()).toBe('logo_refused');
    expect(facade.profile()?.logoVersion).toBe('v1');
  });
});
