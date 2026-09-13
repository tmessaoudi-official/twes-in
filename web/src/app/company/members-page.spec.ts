// SPDX-License-Identifier: AGPL-3.0-or-later

import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import {
  provideTranslateLoader,
  provideTranslateService,
  TranslateLoader,
} from '@ngx-translate/core';
import { of } from 'rxjs';
import { AuthFacade } from '../auth/auth-facade';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { MembersPage } from './members-page';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      roles: { owner: 'propriétaire', admin: 'administrateur', member: 'membre' },
      members: {
        title: 'Membres',
        of: 'Entreprise {{company}}',
        back: 'Retour',
        name: 'Nom',
        email: 'Adresse e-mail',
        email_invalid: 'E-mail invalide',
        role: 'Rôle',
        actions: 'Actions',
        add: 'Ajouter',
        added: 'Membre ajouté.',
        remove: 'Retirer',
        none: 'Aucun membre.',
        errors: { unknown_user: 'Aucun compte', last_owner: 'Au moins un propriétaire' },
      },
    });
  }
}

const owner: MemberRow = {
  userId: 'u1',
  email: 'owner@example.test',
  displayName: 'Owner',
  role: 'owner',
  joinedAt: '2026-09-09T10:00:00+00:00',
  status: 'joined',
};

describe('MembersPage', () => {
  const rows = signal<readonly MemberRow[]>([]);
  const error = signal<string | null>(null);
  const members = {
    members: rows.asReadonly(),
    busy: signal(false).asReadonly(),
    error: error.asReadonly(),
    load: vi.fn(),
    add: vi.fn(),
    remove: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  let fixture: ComponentFixture<MembersPage>;

  const text = (testId: string): string | null =>
    fixture.nativeElement.querySelector(`[data-testid="${testId}"]`)?.textContent?.trim() ?? null;

  beforeEach(async () => {
    rows.set([owner]);
    error.set(null);
    members.load.mockReset().mockResolvedValue(undefined);
    members.add.mockReset().mockResolvedValue(owner);
    members.remove.mockReset().mockResolvedValue(true);
    auth.hasPermission.mockReset().mockReturnValue(true);

    TestBed.configureTestingModule({
      imports: [MembersPage],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MembersFacade, useValue: members },
        { provide: AuthFacade, useValue: auth },
        { provide: SettingsFacade, useClass: BrowserStorageSettings },
        { provide: SETTINGS_STORAGE, useValue: new PageMemoryStorage() },
      ],
    });
    fixture = TestBed.createComponent(MembersPage);
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
  });

  it('loads the members of the company being worked in', () => {
    expect(members.load).toHaveBeenCalledWith('c1');
  });

  it('shows every member it was given', () => {
    expect(text('member-owner@example.test')).toContain('Owner');
    expect(text('member-owner@example.test')).toContain('propriétaire');
  });

  it('refuses to submit an address that is not one', async () => {
    const component = fixture.componentInstance as unknown as {
      form: { setValue: (v: { email: string; role: string }) => void };
      add: () => Promise<void>;
    };
    component.form.setValue({ email: 'not-an-address', role: 'member' });

    await component.add();

    expect(members.add).not.toHaveBeenCalled();
  });

  it('sends a valid address with the chosen role', async () => {
    const component = fixture.componentInstance as unknown as {
      form: { setValue: (v: { email: string; role: string }) => void };
      add: () => Promise<void>;
    };
    component.form.setValue({ email: 'joiner@example.test', role: 'admin' });

    await component.add();

    expect(members.add).toHaveBeenCalledWith('c1', 'joiner@example.test', 'admin');
  });

  it('says so when the API refused', async () => {
    error.set('last_owner');
    fixture.detectChanges();

    expect(text('members-error')).toBe('Au moins un propriétaire');
  });

  it('hides the form and the remove buttons from someone who may not manage', async () => {
    auth.hasPermission.mockReturnValue(false);
    const other = TestBed.createComponent(MembersPage);
    other.detectChanges();
    await other.whenStable();
    other.detectChanges();

    expect(other.nativeElement.querySelector('[data-testid="member-add"]')).toBeNull();
    expect(
      other.nativeElement.querySelector('[data-testid="remove-owner@example.test"]'),
    ).toBeNull();
  });

  it('says the list is empty when it is', async () => {
    rows.set([]);
    const empty = TestBed.createComponent(MembersPage);
    empty.detectChanges();
    await empty.whenStable();
    empty.detectChanges();

    expect(empty.nativeElement.querySelector('[data-testid="members-empty"]')).not.toBeNull();
  });
});
