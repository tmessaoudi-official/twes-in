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
import { Session } from '../shared/session/session';
import { BrowserStorageSettings } from '../shared/settings/browser-storage-settings';
import {
  PageMemoryStorage,
  SETTINGS_STORAGE,
  SettingsFacade,
} from '../shared/settings/settings-facade';
import type { MemberRow } from './company-types';
import { MembersFacade } from './members-facade';
import { RolesFacade } from './roles-facade';
import type { RoleRow } from './roles-types';
import { MembersPage } from './members-page';
import { provideQuietFeedback } from '../shared/testing/feedback';

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
        invited_row: 'Invitation en attente',
        added: 'Membre ajouté.',
        remove: 'Retirer',
        remove_message: "{{name}} n'aura plus accès à cette entreprise.",
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
    refresh: vi.fn(async () => undefined),
    add: vi.fn(),
    remove: vi.fn(),
  };
  const auth = {
    me: () => ({ user: { id: 'u1' }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const role = (name: string, builtIn: boolean): RoleRow => ({
    id: `r-${name}`,
    name,
    builtIn,
    wildcard: false,
    permissions: [],
    memberCount: 0,
  });
  const companyRoles = signal<readonly RoleRow[]>([]);
  const roles = {
    roles: companyRoles.asReadonly(),
    groups: signal([]).asReadonly(),
    busy: signal(false).asReadonly(),
    error: signal(null).asReadonly(),
    detail: signal('').asReadonly(),
    load: vi.fn(),
    refresh: vi.fn(async () => undefined),
    create: vi.fn(),
    revise: vi.fn(),
    remove: vi.fn(),
    clearError: vi.fn(),
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
    companyRoles.set([
      role('owner', true),
      role('admin', true),
      role('member', true),
      role('barista', false),
    ]);
    roles.load.mockReset().mockResolvedValue(undefined);

    TestBed.configureTestingModule({
      imports: [MembersPage],
      providers: [
        ...provideQuietFeedback(),
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        provideTranslateService({
          lang: 'fr',
          fallbackLang: 'fr',
          loader: provideTranslateLoader(() => new StaticLoader()),
        }),
        { provide: MembersFacade, useValue: members },
        { provide: RolesFacade, useValue: roles },
        { provide: AuthFacade, useValue: auth },
        { provide: Session, useExisting: AuthFacade },
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

  it('offers the roles the company made, beside the three the release ships', async () => {
    // Hardcoding owner/admin/member here meant a role the company had just made could be held by nobody.
    const select = fixture.nativeElement.querySelector(
      '[data-testid="member-role"]',
    ) as HTMLElement;
    select.click();
    fixture.detectChanges();
    await fixture.whenStable();

    const offered = [...document.querySelectorAll('mat-option')].map((o) => o.textContent?.trim());
    expect(offered).toEqual(['propriétaire', 'administrateur', 'membre', 'barista']);
  });

  it('names a custom role by its own name, having no translation to look up', () => {
    rows.set([{ ...owner, email: 'barista@example.test', role: 'barista' }]);
    fixture.detectChanges();

    expect(text('member-barista@example.test')).toContain('barista');
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

  it('lists an open invitation as waiting, with nothing to remove', () => {
    rows.set([
      owner,
      {
        userId: '',
        email: 'invited@example.test',
        displayName: '',
        role: 'member',
        joinedAt: '',
        status: 'invited',
      },
    ]);
    fixture.detectChanges();

    expect(text('member-invited@example.test')).toContain('Invitation en attente');
    expect(
      fixture.nativeElement.querySelector('[data-testid="row-more-invited:invited@example.test"]'),
    ).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="row-more-u1"]')).not.toBeNull();
  });

  it('asks before it removes a member, naming them, and removes nothing when the answer is no', async () => {
    // Removing somebody's access used to happen on the click, with no question anywhere on the page.
    const askToRemove = async (): Promise<void> => {
      fixture.nativeElement.querySelector('[data-testid="row-more-u1"]').click();
      fixture.detectChanges();
      await fixture.whenStable();
      const entries = document.querySelectorAll('[data-testid^="row-menu-remove-"]');
      (entries[entries.length - 1] as HTMLElement).click();
      fixture.detectChanges();
      await fixture.whenStable();
    };

    await askToRemove();
    expect(document.querySelector('[data-testid="confirm-message"]')?.textContent).toContain(
      "Owner n'aura plus accès",
    );
    expect(members.remove).not.toHaveBeenCalled();

    (document.querySelector('[data-testid="confirm-keep"]') as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    expect(members.remove).not.toHaveBeenCalled();

    await askToRemove();
    // The last one: a dialog that has just closed can still be in the DOM for a frame.
    const buttons = document.querySelectorAll('[data-testid="confirm-run"]');
    (buttons[buttons.length - 1] as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    await vi.waitFor(() => expect(members.remove).toHaveBeenCalledWith('c1', 'u1'));
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
    expect(other.nativeElement.querySelector('[data-testid="row-more-u1"]')).toBeNull();
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
