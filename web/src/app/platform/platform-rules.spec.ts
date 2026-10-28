// SPDX-License-Identifier: AGPL-3.0-or-later

import {
  COMPANY_NAME_MAX,
  OWNER_EMAIL_MAX,
  companyNameRefusal,
  ownerEmailRefusal,
} from './platform-rules';

describe('the platform rules said before the step-up', () => {
  it('refuses a company name left empty or longer than the API keeps, and takes one at the bound', () => {
    expect(companyNameRefusal('')).toEqual({
      field: 'name',
      reason: 'value_required',
      params: {},
    });
    expect(companyNameRefusal('a'.repeat(COMPANY_NAME_MAX + 1))).toEqual({
      field: 'name',
      reason: 'invalid_text',
      params: { max: 160 },
    });
    expect(companyNameRefusal('a'.repeat(COMPANY_NAME_MAX))).toBeNull();
    expect(companyNameRefusal('Quincaillerie du Centre')).toBeNull();
  });

  it("refuses an owner's address left empty, too long or not shaped as one", () => {
    expect(ownerEmailRefusal('')).toEqual({ field: 'email', reason: 'value_required', params: {} });
    expect(ownerEmailRefusal('------')).toEqual({
      field: 'email',
      reason: 'invalid_email',
      params: {},
    });
    expect(ownerEmailRefusal('owner.example.tn')).toEqual({
      field: 'email',
      reason: 'invalid_email',
      params: {},
    });
    const long = `${'a'.repeat(OWNER_EMAIL_MAX - 'x@exemple.tn'.length + 1)}x@exemple.tn`;
    expect(ownerEmailRefusal(long)).toEqual({
      field: 'email',
      reason: 'invalid_text',
      params: { max: 254 },
    });
    expect(ownerEmailRefusal('compta@exemple.tn')).toBeNull();
  });
});
