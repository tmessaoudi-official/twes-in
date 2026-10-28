// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { FormControl } from '@angular/forms';
import { PasswordRule } from './password-rule';

describe('PasswordRule', () => {
  let rule: PasswordRule;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    rule = TestBed.inject(PasswordRule);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('reads the length once from the API and holds a password to it, counted in characters', async () => {
    const control = new FormControl('', { nonNullable: true, validators: rule.validator });
    control.setValue('court');
    // Nothing is said before the rule is read: the API alone would say it.
    expect(control.valid).toBe(true);

    const reading = rule.read();
    void rule.read();
    http.expectOne({ method: 'GET', url: '/api/auth/password/rule' }).flush({
      minLength: 12,
      maxLength: 4096,
    });
    await reading;
    control.updateValueAndValidity();

    expect(rule.lengths()).toEqual({ minLength: 12, maxLength: 4096 });
    expect(control.errors).toEqual({ minlength: { requiredLength: 12, actualLength: 5 } });
    // Eleven letters, one of them written with two UTF-16 units, are still eleven characters.
    control.setValue('mot de pas𝒆');
    expect(control.hasError('minlength')).toBe(true);
    control.setValue('mot de passe');
    expect(control.valid).toBe(true);
    await rule.read();
    http.expectNone('/api/auth/password/rule');
  });

  it('asks again on the next read when the API could not be reached', async () => {
    const failed = rule.read();
    http.expectOne('/api/auth/password/rule').error(new ProgressEvent('error'));
    await failed;
    expect(rule.lengths()).toBeNull();

    const again = rule.read();
    http.expectOne('/api/auth/password/rule').flush({ minLength: 14, maxLength: 4096 });
    await again;
    expect(rule.lengths()?.minLength).toBe(14);
  });
});
