<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Application\Mfa\PasskeyRefused;

/**
 * The two WebAuthn ceremonies, registration and assertion, behind a port so the use cases never name the library.
 *
 * Options and credentials cross it as JSON strings: the options go to the browser as they are and come back to be
 * verified against, and a credential is what the browser's `PublicKeyCredential.toJSON()` produced.
 */
interface PasskeyCeremonies
{
    /**
     * @param list<string> $excludeCredentialIds base64url ids the authenticator must not register again
     *
     * @return string creation options as JSON, bound to the relying party and requiring user verification
     */
    public function creationOptions(string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): string;

    /** @throws PasskeyRefused when the credential does not answer these options */
    public function verifyRegistration(string $optionsJson, string $credentialJson): VerifiedPasskey;

    /**
     * @param list<string> $allowCredentialIds base64url ids of the passkeys the account may answer with
     *
     * @return string request options as JSON
     */
    public function requestOptions(array $allowCredentialIds): string;

    /** The base64url credential id an assertion names, or null when it names none that can be read. */
    public function credentialIdOf(string $credentialJson): ?string;

    /**
     * @return string the credential record after this use, carrying its new signature counter
     *
     * @throws PasskeyRefused when the assertion does not verify against the record and these options
     */
    public function verifyAssertion(string $optionsJson, string $credentialJson, string $recordJson, string $userHandle): string;
}
