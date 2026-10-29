// SPDX-License-Identifier: AGPL-3.0-or-later

import { FormControl } from '@angular/forms';
import { EMAIL_PATTERN, emailRule, emailTaken, emailTypo } from './email-rule';
import vectors from './email-vectors.json';

describe('the e-mail rule', () => {
  // The API's ContactRules::email reads the same list (EmailRuleTest): an address the screen takes, the API takes.
  it('is the API’s pattern, and takes and refuses the same addresses', () => {
    expect(EMAIL_PATTERN).toBe(vectors.pattern);
    for (const address of vectors.taken) expect(emailTaken(address), address).toBe(true);
    for (const address of vectors.refused) expect(emailTaken(address), address).toBe(false);
  });

  it('refuses an address whose domain has no dot, which Angular’s own validator takes', () => {
    expect(emailRule(new FormControl('compta@exemple'))).toEqual({ email: true });
    expect(emailRule(new FormControl('  compta@exemple.tn '))).toBeNull();
    expect(emailRule(new FormControl(''))).toBeNull();
  });
});

describe('emailTypo', () => {
  it('offers the common domain a slip of a key most likely meant', () => {
    expect(emailTypo('ali@gmial.com')).toBe('ali@gmail.com');
    expect(emailTypo('ali@gmail.con')).toBe('ali@gmail.com');
    expect(emailTypo('ali@hotmial.fr')).toBe('ali@hotmail.fr');
    expect(emailTypo('ali@topnet.com')).toBeNull();
    expect(emailTypo('Ali.Ben@Yahooo.fr')).toBe('Ali.Ben@yahoo.fr');
  });

  it('offers nothing for a common domain, another domain, or what is no address yet', () => {
    expect(emailTypo('ali@gmail.com')).toBeNull();
    expect(emailTypo('compta@quincaillerie-sfax.tn')).toBeNull();
    expect(emailTypo('ali@gmial')).toBeNull();
    expect(emailTypo('gmial.com')).toBeNull();
  });
});
