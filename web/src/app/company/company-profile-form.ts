// SPDX-License-Identifier: AGPL-3.0-or-later

import type { FormDescriptor, FormField, FormSection, FormValues } from '../shared/form/form-types';
import type { CompanyProfile, CompanyProfileChanges } from './company-types';

/** The fields a person types, with the limits the API checks again (CompanyProfileResource). */
const TEXT_FIELDS = [
  'legalName',
  'legalForm',
  'addressLine1',
  'addressLine2',
  'postalCode',
  'city',
  'email',
  'phone',
  'website',
  'iban',
  'bic',
  'invoiceFooterText',
  'latePenaltyText',
] as const;

const IDENTIFIER_PREFIX = 'identifier__';
const IDENTIFIER_MAX_LENGTH = 64;

const label = (id: string): string => `company.profile.fields.${id}`;
const section = (id: string, fields: FormField[]): FormSection => ({
  id,
  title: `company.profile.sections.${id}`,
  description: `company.profile.sections_about.${id}`,
  fields,
});

/**
 * The profile form, grouped the way an invoice header reads: who the company is, its registration numbers, where it
 * is, how to reach it, where to pay it and what its invoices print. The identifiers and VAT regimes are the fiscal
 * preset's, as the API listed them, so a company in another country gets its own without a change here.
 */
export function profileForm(profile: CompanyProfile): FormDescriptor {
  const identifiers: FormField[] = profile.identifierFields.map((field) => ({
    id: IDENTIFIER_PREFIX + field.key,
    label: field.label,
    kind: 'text',
    required: field.required,
    maxLength: IDENTIFIER_MAX_LENGTH,
    pattern: field.pattern,
  }));

  return {
    id: 'company-profile',
    sections: [
      section('identity', [
        {
          id: 'legalName',
          label: label('legalName'),
          kind: 'text',
          maxLength: 200,
          autocomplete: 'organization',
        },
        { id: 'legalForm', label: label('legalForm'), kind: 'text', maxLength: 80 },
        {
          id: 'vatRegime',
          label: label('vatRegime'),
          kind: 'select',
          required: true,
          options: profile.vatRegimes.map((regime) => ({
            value: regime.code,
            label: regime.label,
          })),
        },
      ]),
      ...(identifiers.length > 0 ? [section('identifiers', identifiers)] : []),
      section('address', [
        {
          id: 'addressLine1',
          label: label('addressLine1'),
          kind: 'text',
          maxLength: 200,
          span: 2,
          autocomplete: 'address-line1',
        },
        {
          id: 'addressLine2',
          label: label('addressLine2'),
          kind: 'text',
          maxLength: 200,
          span: 2,
          autocomplete: 'address-line2',
        },
        {
          id: 'postalCode',
          label: label('postalCode'),
          kind: 'text',
          maxLength: 20,
          autocomplete: 'postal-code',
        },
        {
          id: 'city',
          label: label('city'),
          kind: 'text',
          maxLength: 120,
          autocomplete: 'address-level2',
        },
      ]),
      section('contact', [
        {
          id: 'email',
          label: label('email'),
          kind: 'email',
          maxLength: 254,
          autocomplete: 'email',
        },
        {
          id: 'phone',
          label: label('phone'),
          kind: 'tel',
          maxLength: 40,
          pattern: '\\+?[0-9 ().\\-]{3,40}',
          autocomplete: 'tel',
        },
        {
          id: 'website',
          label: label('website'),
          kind: 'text',
          maxLength: 255,
          pattern: 'https?://\\S+',
          autocomplete: 'url',
        },
      ]),
      section('banking', [
        {
          id: 'iban',
          label: label('iban'),
          kind: 'text',
          maxLength: 42,
          pattern: '[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 ]{8,38}',
        },
        {
          id: 'bic',
          label: label('bic'),
          kind: 'text',
          maxLength: 11,
          pattern: '[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?',
        },
      ]),
      section('documents', [
        {
          id: 'invoiceFooterText',
          label: label('invoiceFooterText'),
          kind: 'textarea',
          maxLength: 2000,
          span: 2,
        },
        {
          id: 'latePenaltyText',
          label: label('latePenaltyText'),
          kind: 'textarea',
          maxLength: 2000,
          span: 2,
        },
      ]),
    ],
  };
}

/** Each field at the saved value; a value the company does not have is an empty field. */
export function profileValues(profile: CompanyProfile): FormValues {
  const values: FormValues = { vatRegime: profile.vatRegime };
  for (const id of TEXT_FIELDS) {
    values[id] = profile[id] ?? '';
  }
  for (const field of profile.identifierFields) {
    values[IDENTIFIER_PREFIX + field.key] = profile.identifiers[field.key] ?? '';
  }
  return values;
}

/** What the submitted form says, an emptied field as no value; the API trims and normalises the rest. */
export function profileChanges(profile: CompanyProfile, values: FormValues): CompanyProfileChanges {
  const text = (id: string): string | null => {
    const value = values[id];
    return typeof value === 'string' && value.trim() !== '' ? value : null;
  };
  const identifiers: Record<string, string> = {};
  for (const field of profile.identifierFields) {
    const value = text(IDENTIFIER_PREFIX + field.key);
    if (value !== null) identifiers[field.key] = value;
  }
  return {
    legalName: text('legalName'),
    legalForm: text('legalForm'),
    identifiers,
    addressLine1: text('addressLine1'),
    addressLine2: text('addressLine2'),
    postalCode: text('postalCode'),
    city: text('city'),
    email: text('email'),
    phone: text('phone'),
    website: text('website'),
    iban: text('iban'),
    bic: text('bic'),
    vatRegime: text('vatRegime') ?? profile.vatRegime,
    invoiceFooterText: text('invoiceFooterText'),
    latePenaltyText: text('latePenaltyText'),
  };
}
