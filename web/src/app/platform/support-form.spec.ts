// SPDX-License-Identifier: AGPL-3.0-or-later

import { buildFormGroup } from '../shared/form/form-builder';
import { apiPattern, hintExamples } from '../shared/testing/hint-examples';
import type { PlatformSupportRules } from './platform-types';
import { supportForm } from './support-form';

/** The rules the API sends with each channel, its patterns as hint-examples.json holds them to the API's own. */
const rules: PlatformSupportRules = {
  phone: {
    pattern: apiPattern('support_phone'),
    patternReason: 'invalid_phone_digits',
    maxLength: 30,
  },
  whatsapp: { pattern: apiPattern('whatsapp'), patternReason: 'invalid_whatsapp', maxLength: 30 },
  email: { pattern: null, patternReason: null, maxLength: 254 },
  status_url: { pattern: null, patternReason: null, maxLength: 200 },
};

describe('« Contact du support »', () => {
  // The phone field says itself what it asks for, the local number with an example of the country picked (row 252):
  // a hint of the form's own would say again the country code the picker beside it gives.
  it('leaves the phone and WhatsApp hints to the phone field, and keeps the others', () => {
    const fields = supportForm(rules).sections[0]!.fields;
    const hint = (id: string) => fields.find((field) => field.id === id)?.hint;
    expect(hint('phone')).toBeUndefined();
    expect(hint('whatsapp')).toBeUndefined();
    expect(hint('email')).toBe('platform.support.email_hint');
    expect(hint('status_url')).toBe('platform.support.status_url_hint');
  });

  // The hint under a channel and its refusal offer an example a person copies, its groups kept apart by no-break
  // spaces (« +216 20 123 456 »), and in Arabic a direction mark before it: the field takes it as copied.
  it.each([
    ['phone', 'support_phone'],
    ['whatsapp', 'whatsapp'],
  ] as const)('takes every example the %s hint and refusal offer, as copied', (channel, kind) => {
    const control = buildFormGroup(supportForm(rules)).controls[channel]!;
    const examples = hintExamples(kind);
    expect(examples.some((example) => /\u202f/.test(example))).toBe(true);
    for (const example of examples) {
      control.setValue(example);
      expect(control.errors, example).toBeNull();
    }
    control.setValue('+216 20\u200b123 456');
    expect(control.errors?.['pattern'], 'a zero-width mark is no space').toBeTruthy();
  });
});
