// SPDX-License-Identifier: AGPL-3.0-or-later

import { IDENTIFIER_FIELD_PREFIX } from '../shared/form/field-refusals';
import { dirtyCount } from '../shared/form/dirty-count';
import { buildFormGroup } from '../shared/form/form-builder';
import { profileChanges, profileForm, profileValues } from './company-profile-form';
import type { CompanyProfile } from './company-types';

const profile: CompanyProfile = {
  name: 'Demo',
  countryCode: 'TN',
  writable: true,
  logoVersion: null,
  version: 1,
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
  vatOnDebits: false,
  offersVatOnDebits: false,
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

describe('the débits option', () => {
  const french: CompanyProfile = { ...profile, countryCode: 'FR', offersVatOnDebits: true };
  const ids = (form: ReturnType<typeof profileForm>): string[] =>
    form.sections.flatMap((section) => section.fields.map((field) => field.id));

  it('is asked, as a box beside the VAT regime, only where the preset offers it', () => {
    expect(ids(profileForm(profile))).not.toContain('vatOnDebits');
    const identity = profileForm(french).sections[0]?.fields ?? [];
    const box = identity.find((field) => field.id === 'vatOnDebits');
    expect(box?.kind).toBe('checkbox');
    expect(box?.hint).toBe('company.profile.hints.vatOnDebits');
    expect(identity.map((field) => field.id).indexOf('vatOnDebits')).toBe(
      identity.map((field) => field.id).indexOf('vatRegime') + 1,
    );
  });

  /**
   * A saved value with no field to hold it reads as a change: the page opened with one unsaved change wherever the
   * option is not offered, and asked before letting anybody leave it for another settings page.
   */
  it('opens with no unsaved change, whether the option is offered or not', () => {
    for (const company of [profile, { ...french, vatOnDebits: true }]) {
      const form = buildFormGroup(profileForm(company), profileValues(company));
      expect(dirtyCount(form.getRawValue(), profileValues(company))).toBe(0);
    }
  });

  it('shows what the company chose and sends it back, never true where it is not offered', () => {
    const opted = { ...french, vatOnDebits: true };
    expect(profileValues(opted)['vatOnDebits']).toBe(true);
    expect(profileChanges(opted, { ...profileValues(opted), vatOnDebits: false }).vatOnDebits).toBe(
      false,
    );
    expect(
      profileChanges(french, { ...profileValues(french), vatOnDebits: true }).vatOnDebits,
    ).toBe(true);
    expect(
      profileChanges(profile, { ...profileValues(profile), vatOnDebits: true }).vatOnDebits,
    ).toBe(false);
  });
});

describe('profileForm', () => {
  // docs/SPEC.md § 7, 2026-10-22 18:09: a bare RIB is the IBAN TN59 spells before it, so the field lets one through.
  it('takes an IBAN or a bare RIB in the IBAN field, and nothing else', () => {
    const iban = buildFormGroup(profileForm(profile), profileValues(profile)).controls['iban']!;
    for (const taken of [
      'TN59 1000 6035 1835 9847 8831',
      '10006035183598478831',
      '10 006 035 1835984788 31',
      '10.006.035.1835984788.31',
    ]) {
      iban.setValue(taken);
      expect(iban.errors, taken).toBeNull();
    }
    for (const refused of [
      '1000603518359847883',
      'TN59',
      'TN59 1000 6035 18!!',
      '!!10006035183598478831',
      '10006035183598478831 TN',
    ]) {
      iban.setValue(refused);
      expect(iban.errors?.['pattern'], refused).toBeTruthy();
    }
  });

  // The BIC as a RIB prints it, its groups apart: the API drops the spaces before its own check.
  it('takes a BIC written with its groups apart', () => {
    const bic = buildFormGroup(profileForm(profile), profileValues(profile)).controls['bic']!;
    for (const taken of ['BIATTNTT', 'BIAT TN TT', 'biat tn tt', 'BNORFRPPBDX', 'BNOR FR PP BDX']) {
      bic.setValue(taken);
      expect(bic.errors, taken).toBeNull();
    }
    for (const refused of ['BIAT', 'BIATTNTT1', 'BIAT  TN TT', 'BIAT TN TT 1']) {
      bic.setValue(refused);
      expect(bic.errors?.['pattern'] ?? bic.errors?.['maxlength'], refused).toBeTruthy();
    }
  });

  // A pattern the browser checks answers first, so its refusal says the format and an example, as the API's would.
  it('says the format of every field it checks against a pattern', () => {
    const unsaid = profileForm(profile)
      .sections.flatMap((section) => section.fields)
      // A preset identifier's hint is the preset's (row 103): the API sends it beside the pattern.
      .filter((field) => !field.id.startsWith(IDENTIFIER_FIELD_PREFIX))
      .filter((field) => field.pattern !== undefined && !field.patternError && !field.hint)
      .map((field) => field.id);
    expect(unsaid).toEqual([]);
  });

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
      vatOnDebits: false,
      invoiceFooterText: null,
      latePenaltyText: null,
    });
  });

  it('drops an identifier left empty rather than sending it blank', () => {
    const values = { ...profileValues(profile), identifier__matricule_fiscal: '' };

    expect(profileChanges(profile, values).identifiers).toEqual({});
  });
});
