// SPDX-License-Identifier: AGPL-3.0-or-later

import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthFacade } from '../auth/auth-facade';
import type { SignedInState, WorkingCompany } from '../auth/auth-types';
import { CompanyBrand } from './company-brand';
import { companyLogoUrl } from './company-api';

describe('CompanyBrand', () => {
  const me = signal<SignedInState | null>(null);

  function signedIn(company: Partial<WorkingCompany> | null): void {
    me.set({
      company:
        company === null
          ? null
          : ({ id: 'c1', name: 'Quincaillerie du Sahel', ...company } as WorkingCompany),
    } as SignedInState);
  }

  function render(): HTMLElement {
    TestBed.configureTestingModule({
      imports: [CompanyBrand],
      providers: [{ provide: AuthFacade, useValue: { me } }],
    });
    const fixture = TestBed.createComponent(CompanyBrand);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  const q = (root: HTMLElement, id: string) => root.querySelector(`[data-testid="${id}"]`);

  it('names the company a customer is facing, with its logo beside the name', () => {
    // Audit 2026-10-06 V-33: the customer screen and display named no one.
    signedIn({ logoVersion: 'f1' });
    const root = render();

    expect(q(root, 'company-brand-name')?.textContent?.trim()).toBe('Quincaillerie du Sahel');
    const logo = q(root, 'company-brand-logo') as HTMLImageElement | null;
    expect(logo?.getAttribute('src')).toBe(companyLogoUrl('c1', 'f1'));
    // The name beside it already says whose logo it is.
    expect(logo?.getAttribute('alt')).toBe('');
  });

  it('names a company without a logo by its name alone, and nobody when no company is chosen', () => {
    signedIn({ logoVersion: null });
    const root = render();
    expect(q(root, 'company-brand-logo')).toBeNull();
    expect(q(root, 'company-brand-name')?.textContent).toContain('Quincaillerie du Sahel');

    signedIn(null);
    TestBed.tick();
    expect(q(root, 'company-brand')).toBeNull();
  });
});
