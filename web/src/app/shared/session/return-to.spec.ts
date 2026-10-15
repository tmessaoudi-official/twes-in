// SPDX-License-Identifier: AGPL-3.0-or-later

import { returnAddress, signInAddress } from './return-to';

describe('the address a sign-in returns to', () => {
  it('keeps a page of this app, with its query and fragment', () => {
    expect(returnAddress('/invoices/i1')).toBe('/invoices/i1');
    expect(returnAddress('/customers?search=durand&page=2#notes')).toBe(
      '/customers?search=durand&page=2#notes',
    );
  });

  it('refuses anything that could leave the app, or loop back to signing in', () => {
    for (const address of [
      '//evil.example/invoices',
      '/\\evil.example',
      '/invoices\\..\\x',
      'https://evil.example',
      'http:/evil.example',
      'javascript:alert(1)',
      'invoices/i1',
      ' /invoices/i1',
      '/invoices/i1\n',
      '/login',
      '/login?expired=1',
      '/',
      '',
      `/${'a'.repeat(2048)}`,
      null,
      undefined,
    ]) {
      expect(returnAddress(address), String(address)).toBeNull();
    }
  });

  it('writes the sign-in address with where to return and whether the session ended', () => {
    expect(signInAddress('/invoices/i1')).toBe('/login?returnTo=%2Finvoices%2Fi1');
    expect(signInAddress('/invoices/i1', true)).toBe('/login?expired=1&returnTo=%2Finvoices%2Fi1');
    expect(signInAddress('/', true)).toBe('/login?expired=1');
    expect(signInAddress('//evil.example')).toBe('/login');
    expect(signInAddress(undefined)).toBe('/login');
  });
});
