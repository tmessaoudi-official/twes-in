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
import { MEMBERS_LIST, MembersPage } from './members-page';
import { provideQuietFeedback, type RecordedFeedback } from '../shared/testing/feedback';
import { Feedback } from '../shared/feedback/feedback';
import { UnsavedChanges } from '../shared/form/unsaved-changes';

class StaticLoader implements TranslateLoader {
  getTranslation() {
    return of({
      roles: { owner: 'propriétaire', admin: 'administrateur', member: 'membre' },
      members: {
        title: 'Membres',
        invite_title: 'Inviter un membre',
        of: 'Entreprise {{company}}',
        back: 'Retour',
        name: 'Nom',
        email: 'Adresse e-mail',
        email_invalid: 'E-mail invalide',
        role: 'Rôle',
        actions: 'Actions',
        add: 'Ajouter',
        invited_row: 'Invitation en attente',
        invited_until: 'Invitation en attente jusqu’au {{day}}',
        expired_on: 'Invitation expirée le {{day}} sans avoir servi : renvoyez-la',
        replaces: 'Une invitation attend déjà {{email}}.',
        resend: 'Renvoyer l’invitation',
        change_role: 'Changer le rôle',
        change_role_title: 'Changer le rôle de {{name}}',
        change_role_confirm: 'Changer',
        keep: 'Garder',
        withdraw: 'Retirer l’invitation',
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
  invitationId: null,
  expiresAt: null,
};

const invited: MemberRow = {
  userId: '',
  email: 'invited@example.test',
  displayName: '',
  role: 'member',
  joinedAt: '',
  status: 'invited',
  invitationId: 'i1',
  expiresAt: '2026-11-02T10:00:00+00:00',
};

describe('MembersPage', () => {
  // Who is signed in: someone else than the owner row, whom the removal cases act on.
  let signedIn = 'u-me';
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
    withdraw: vi.fn(),
    changeRole: vi.fn(async () => true),
  };
  const auth = {
    me: () => ({ user: { id: signedIn }, company: { id: 'c1', name: 'Acme' } }),
    hasPermission: vi.fn(),
  };
  const role = (name: string, builtIn: boolean): RoleRow => ({
    id: `r-${name}`,
    name,
    builtIn,
    wildcard: false,
    permissions: [],
    memberCount: 0,
    version: 1,
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
    signedIn = 'u-me';
    rows.set([owner]);
    error.set(null);
    members.load.mockReset().mockResolvedValue(undefined);
    members.add.mockReset().mockResolvedValue(owner);
    members.remove.mockReset().mockResolvedValue(true);
    members.withdraw.mockReset().mockResolvedValue(true);
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
    // Translated lowercase to sit inside sentences, a shipped role alone in its column read « propriétaire ».
    expect(text('member-owner@example.test')).toContain('Propriétaire');
  });

  it('offers the roles the company made, beside those the release ships', async () => {
    // Hardcoding owner/admin/member here meant a role the company had just made could be held by nobody.
    const select = fixture.nativeElement.querySelector(
      '[data-testid="member-role"]',
    ) as HTMLElement;
    select.click();
    fixture.detectChanges();
    await fixture.whenStable();

    const offered = [...document.querySelectorAll('[role="option"] [data-option-label]')].map((o) =>
      o.textContent?.trim(),
    );
    // A shipped role is raised standing alone; a company's own name is left as it was typed.
    expect(offered).toEqual(['Propriétaire', 'Administrateur', 'Membre', 'barista']);
  });

  it('names the invitation form, so it does not read as part of the list above it', () => {
    expect(
      fixture.nativeElement.querySelector('[data-testid="member-invite-title"]')?.textContent,
    ).toContain('Inviter un membre');
  });

  it('names a custom role by its own name, having no translation to look up', () => {
    rows.set([{ ...owner, email: 'barista@example.test', role: 'barista' }]);
    fixture.detectChanges();

    expect(text('member-barista@example.test')).toContain('barista');
    expect(text('member-barista@example.test')).not.toContain('Barista');
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

  it('counts an invitation being typed, holding the page until it goes', async () => {
    const unsaved = TestBed.inject(UnsavedChanges);
    const component = fixture.componentInstance as unknown as {
      form: { setValue: (v: { email: string; role: string }) => void };
      add: () => Promise<void>;
    };
    expect(unsaved.count()).toBe(0);
    component.form.setValue({ email: 'joiner@example.test', role: 'admin' });
    expect(unsaved.count()).toBe(2);

    members.add.mockResolvedValueOnce({ email: 'joiner@example.test' });
    await component.add();

    expect(unsaved.count()).toBe(0);
  });

  it("offers no « Retirer » on the signed-in person's own row: removing someone is not leaving", async () => {
    signedIn = 'u1';
    fixture.detectChanges();
    const more = fixture.nativeElement.querySelector(
      '[data-testid="row-more-u1"]',
    ) as HTMLElement | null;
    more?.click();
    fixture.detectChanges();
    await fixture.whenStable();

    expect(document.querySelectorAll('[data-testid="row-menu-remove-u1"]').length).toBe(0);
  });

  /** Opens a row's « ⋮ » and answers the entry of that action, or null when the row does not offer it. */
  async function menuEntry(rowId: string, action: string): Promise<HTMLElement | null> {
    (
      fixture.nativeElement.querySelector(`[data-testid="row-more-${rowId}"]`) as HTMLElement
    ).click();
    fixture.detectChanges();
    await fixture.whenStable();
    const entries = document.querySelectorAll<HTMLElement>(
      `[data-testid="row-menu-${action}-${rowId}"]`,
    );
    return entries.length === 0 ? null : entries[entries.length - 1];
  }

  it('lists an open invitation as waiting, until when, offering to send it again, change its role or withdraw it', async () => {
    // A mistyped address stayed listed with no way to take the invitation back: the link lived seven days.
    rows.set([owner, invited]);
    fixture.detectChanges();

    expect(text('member-invited@example.test')).toContain('Invitation en attente jusqu’au');
    for (const action of ['resend', 'change-role', 'withdraw']) {
      expect(await menuEntry('invited:invited@example.test', action), action).not.toBeNull();
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27 }));
      fixture.detectChanges();
      await fixture.whenStable();
    }
    expect(await menuEntry('invited:invited@example.test', 'remove')).toBeNull();
  });

  it('says an invitation that ran out unused, offering to send it again or withdraw it', async () => {
    // It used to vanish from the list: the person waiting said they never got in, and the owner saw nothing.
    rows.set([owner, { ...invited, status: 'expired', expiresAt: '2026-10-20T10:00:00+00:00' }]);
    fixture.detectChanges();

    expect(text('member-invited@example.test')).toContain('Invitation expirée le');
    for (const action of ['resend', 'withdraw']) {
      expect(await menuEntry('invited:invited@example.test', action), action).not.toBeNull();
      document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27 }));
      fixture.detectChanges();
      await fixture.whenStable();
    }
    expect(await menuEntry('invited:invited@example.test', 'remove')).toBeNull();
  });

  it('withdraws an invitation without a question, and its toast sends it again', async () => {
    rows.set([owner, invited]);
    fixture.detectChanges();

    (await menuEntry('invited:invited@example.test', 'withdraw'))!.click();
    fixture.detectChanges();
    await fixture.whenStable();

    await vi.waitFor(() => expect(members.withdraw).toHaveBeenCalledWith('c1', 'i1'));
    expect(document.querySelector('[data-testid="confirm-run"]')).toBeNull();
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    await vi.waitFor(() =>
      expect(said).toEqual([
        expect.objectContaining({
          key: 'members.withdrawn',
          params: { email: 'invited@example.test' },
          effect: 'corrigeable',
        }),
      ]),
    );
    said[0].action?.run();
    await vi.waitFor(() =>
      expect(members.add).toHaveBeenCalledWith('c1', 'invited@example.test', 'member'),
    );
  });

  it('sends an invitation again at the same role, saying the old link stopped', async () => {
    rows.set([owner, invited]);
    fixture.detectChanges();

    (await menuEntry('invited:invited@example.test', 'resend'))!.click();
    fixture.detectChanges();
    await fixture.whenStable();

    await vi.waitFor(() =>
      expect(members.add).toHaveBeenCalledWith('c1', 'invited@example.test', 'member'),
    );
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'members.resent',
          params: { email: 'invited@example.test' },
        }),
      ]),
    );
  });

  it('changes a member’s role from a dialog, without a question, and its toast puts the old one back', async () => {
    // A member's role could only change by removing them and inviting them again (hunt F5).
    const clerk: MemberRow = {
      ...owner,
      userId: 'u2',
      email: 'clerk@example.test',
      displayName: 'Nadia',
      role: 'member',
    };
    rows.set([owner, clerk]);
    fixture.detectChanges();
    members.changeRole.mockClear();

    (await menuEntry('u2', 'change-role'))!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    await vi.waitFor(() =>
      expect(document.querySelector('[data-testid="member-role-title"]')?.textContent).toContain(
        'Changer le rôle de Nadia',
      ),
    );
    document.querySelector<HTMLElement>('[data-testid="member-role-change"]')!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    [...document.querySelectorAll<HTMLElement>('[role="option"]')]
      .find((option) => option.textContent?.includes('Administrateur'))!
      .click();
    fixture.detectChanges();
    document.querySelector<HTMLElement>('[data-testid="member-role-confirm"]')!.click();

    await vi.waitFor(() => expect(members.changeRole).toHaveBeenCalledWith('c1', 'u2', 'admin'));
    const said = (TestBed.inject(Feedback) as RecordedFeedback).said;
    await vi.waitFor(() =>
      expect(said).toEqual([
        expect.objectContaining({
          key: 'members.role_changed',
          params: { name: 'Nadia', role: 'administrateur' },
          effect: 'corrigeable',
        }),
      ]),
    );
    said[0].action?.run();
    await vi.waitFor(() =>
      expect(members.changeRole).toHaveBeenLastCalledWith('c1', 'u2', 'member'),
    );
  });

  it('changes nothing when the role kept is the one held', async () => {
    rows.set([owner, { ...owner, userId: 'u2', email: 'clerk@example.test', role: 'member' }]);
    fixture.detectChanges();
    members.changeRole.mockClear();

    (await menuEntry('u2', 'change-role'))!.click();
    fixture.detectChanges();
    await fixture.whenStable();
    await vi.waitFor(() =>
      expect(document.querySelector('[data-testid="member-role-confirm"]')).not.toBeNull(),
    );
    document.querySelector<HTMLElement>('[data-testid="member-role-confirm"]')!.click();
    await fixture.whenStable();

    expect(members.changeRole).not.toHaveBeenCalled();
  });

  it('puts an invitation in the form to change its role, and says sending replaces it', async () => {
    rows.set([owner, invited]);
    fixture.detectChanges();

    (await menuEntry('invited:invited@example.test', 'change-role'))!.click();
    fixture.detectChanges();
    await fixture.whenStable();

    const email = fixture.nativeElement.querySelector(
      '[data-testid="member-email"]',
    ) as HTMLInputElement;
    expect(email.value).toBe('invited@example.test');
    expect(text('member-replaces')).toContain('invited@example.test');
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
    // Said once done, naming who lost access, as the question did.
    await vi.waitFor(() =>
      expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([
        expect.objectContaining({
          key: 'members.removed',
          params: { name: 'Owner' },
          effect: 'corrigeable',
        }),
      ]),
    );
  });

  it('says nothing was done when the removal was refused', async () => {
    members.remove.mockResolvedValue(false);
    fixture.nativeElement.querySelector('[data-testid="row-more-u1"]').click();
    fixture.detectChanges();
    await fixture.whenStable();
    const entries = document.querySelectorAll('[data-testid^="row-menu-remove-"]');
    (entries[entries.length - 1] as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();
    const buttons = document.querySelectorAll('[data-testid="confirm-run"]');
    (buttons[buttons.length - 1] as HTMLElement).click();
    fixture.detectChanges();
    await fixture.whenStable();

    await vi.waitFor(() => expect(members.remove).toHaveBeenCalled());
    expect((TestBed.inject(Feedback) as RecordedFeedback).said).toEqual([]);
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

describe('the members list', () => {
  it('narrows by several roles at once', () => {
    expect(MEMBERS_LIST.filters?.map((filter) => [filter.id, filter.multiple])).toEqual([
      ['role', true],
    ]);
  });
});
