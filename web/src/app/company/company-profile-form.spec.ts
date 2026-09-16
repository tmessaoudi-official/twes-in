// SPDX-License-Identifier: AGPL-3.0-or-later

import { profileChanges, profileForm, profileValues } from './company-profile-form';
import type { CompanyProfile } from './company-types';

const profile: CompanyProfile = {
  name: 'Demo',
  countryCode: 'TN',
  writable: true,
  legalName: 'Demo SARL',
  legalForm: null,
  identifiers: { matricule_fiscal: '1234567A/B/M/000' },
  addressLine1: null,
  addressLine2: null,
  postalCode: null,
  city: 'Tunis',
  email: null,
  phone: null,
  website: null,
  iban: null,
  bic: null,
  vatRegime: 'standard',
  invoiceFooterText: null,
  latePenaltyText: null,
  identifierFields: [
    {
      key: 'matricule_fiscal',
      label: 'Matricule fiscal',
      pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      required: true,
    },
  ],
  vatRegimes: [{ code: 'standard', label: 'Régime normal' }],
};

describe('profileForm', () => {
  it('groups the profile the way an invoice header reads it', () => {
    const form = profileForm(profile);

    expect(form.sections.map((section) => section.id)).toEqual([
      'identity',
      'identifiers',
      'address',
      'contact',
      'banking',
      'documents',
    ]);
    expect(form.sections[0]?.title).toBe('company.profile.sections.identity');
  });

  it('explains what each section is for', () => {
    expect(profileForm(profile).sections.map((section) => section.description)).toEqual(
      ['identity', 'identifiers', 'address', 'contact', 'banking', 'documents'].map(
        (id) => `company.profile.sections_about.${id}`,
      ),
    );
  });

  it("asks for the preset's identifiers with their shape, under the label the API gave", () => {
    const identifiers = profileForm(profile).sections.find((s) => s.id === 'identifiers');

    expect(identifiers?.fields).toEqual([
      {
        id: 'identifier__matricule_fiscal',
        label: 'Matricule fiscal',
        kind: 'text',
        required: true,
        maxLength: 64,
        pattern: '^[0-9]{7}[A-Z]/[A-Z]/[A-Z]/[0-9]{3}$',
      },
    ]);
  });

  it('offers only the VAT regimes the preset has', () => {
    const identity = profileForm(profile).sections.find((s) => s.id === 'identity');
    const regime = identity?.fields.find((field) => field.id === 'vatRegime');

    expect(regime).toMatchObject({ kind: 'select', required: true });
    expect(regime?.options).toEqual([{ value: 'standard', label: 'Régime normal' }]);
  });

  it('leaves the identifiers out when the preset asks for none', () => {
    const form = profileForm({ ...profile, identifierFields: [] });

    expect(form.sections.map((section) => section.id)).not.toContain('identifiers');
  });
});

describe('profileValues and profileChanges', () => {
  it('starts each field at the saved value, an absent one empty', () => {
    const values = profileValues(profile);

    expect(values['legalName']).toBe('Demo SARL');
    expect(values['legalForm']).toBe('');
    expect(values['identifier__matricule_fiscal']).toBe('1234567A/B/M/000');
    expect(values['vatRegime']).toBe('standard');
  });

  it('sends an emptied field as no value and gathers the identifiers back', () => {
    const values = {
      ...profileValues(profile),
      legalName: '',
      iban: 'TN59 1000 6035 1835 9847 8831',
      identifier__matricule_fiscal: '7654321B/A/M/000',
    };

    expect(profileChanges(profile, values)).toEqual({
      legalName: null,
      legalForm: null,
      identifiers: { matricule_fiscal: '7654321B/A/M/000' },
      addressLine1: null,
      addressLine2: null,
      postalCode: null,
      city: 'Tunis',
      email: null,
      phone: null,
      website: null,
      iban: 'TN59 1000 6035 1835 9847 8831',
      bic: null,
      vatRegime: 'standard',
      invoiceFooterText: null,
      latePenaltyText: null,
    });
  });

  it('drops an identifier left empty rather than sending it blank', () => {
    const values = { ...profileValues(profile), identifier__matricule_fiscal: '' };

    expect(profileChanges(profile, values).identifiers).toEqual({});
  });
});
