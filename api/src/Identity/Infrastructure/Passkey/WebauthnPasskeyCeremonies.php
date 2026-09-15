<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Passkey;

use App\Identity\Application\Mfa\PasskeyRefused;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\VerifiedPasskey;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The port on web-auth/webauthn-lib (MIT).
 *
 * Attestation is "none": a second factor needs proof of possession, not the make of the authenticator, and asking for
 * more makes browsers show a privacy prompt. The relying party id is configuration, never the request's Host header,
 * which a proxy or a client can set. Allowed origins are compared whole, scheme and port included.
 */
final readonly class WebauthnPasskeyCeremonies implements PasskeyCeremonies
{
    private const int ES256 = -7;
    private const int RS256 = -257;

    /** Matches the five minutes a pending login lasts. */
    private const int TIMEOUT_MS = 300_000;

    private SerializerInterface $serializer;

    private CeremonyStepManagerFactory $steps;

    /** @param list<string> $origins */
    public function __construct(
        private string $rpId,
        private string $rpName,
        array $origins,
    ) {
        $attestation = AttestationStatementSupportManager::create([NoneAttestationStatementSupport::create()]);
        $this->serializer = (new WebauthnSerializerFactory($attestation))->create();
        $steps = new CeremonyStepManagerFactory();
        $steps->setAllowedOrigins($origins);
        $steps->setAttestationStatementSupportManager($attestation);
        $this->steps = $steps;
    }

    public function creationOptions(string $userHandle, string $userName, string $displayName, array $excludeCredentialIds): string
    {
        return $this->toJson(PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId),
            PublicKeyCredentialUserEntity::create($userName, $userHandle, $displayName),
            random_bytes(32),
            [PublicKeyCredentialParameters::createPk(self::ES256), PublicKeyCredentialParameters::createPk(self::RS256)],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $this->descriptors($excludeCredentialIds),
            self::TIMEOUT_MS,
        ));
    }

    public function verifyRegistration(string $optionsJson, string $credentialJson): VerifiedPasskey
    {
        // The library's credential denormalizer hands back the raw array when there is no `id`, rather than refusing it.
        $this->credentialIdOf($credentialJson) ?? throw new PasskeyRefused();

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialCreationOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                throw new PasskeyRefused();
            }

            $record = AuthenticatorAttestationResponseValidator::create($this->steps->creationCeremony())
                ->check($credential->response, $options, $this->rpId);
        } catch (\Exception|\TypeError $e) {
            // The credential is the client's JSON: a field of the wrong type reaches the library's denormalizers as a
            // TypeError before any check runs. Whatever the cause, the answer is the same refusal.
            throw new PasskeyRefused('', 0, $e);
        }

        return new VerifiedPasskey(self::encode($record->publicKeyCredentialId), $this->toJson($record));
    }

    public function requestOptions(array $allowCredentialIds): string
    {
        return $this->toJson(PublicKeyCredentialRequestOptions::create(
            random_bytes(32),
            $this->rpId,
            $this->descriptors($allowCredentialIds),
            AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            self::TIMEOUT_MS,
        ));
    }

    public function credentialIdOf(string $credentialJson): ?string
    {
        try {
            $body = json_decode($credentialJson, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $id = \is_array($body) ? ($body['id'] ?? null) : null;
        $raw = \is_string($id) ? self::decode($id) : null;

        return null === $raw ? null : self::encode($raw);
    }

    public function verifyAssertion(string $optionsJson, string $credentialJson, string $recordJson, string $userHandle): string
    {
        $this->credentialIdOf($credentialJson) ?? throw new PasskeyRefused();

        try {
            $options = $this->serializer->deserialize($optionsJson, PublicKeyCredentialRequestOptions::class, 'json');
            $credential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');
            $record = $this->serializer->deserialize($recordJson, CredentialRecord::class, 'json');

            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                throw new PasskeyRefused();
            }

            $updated = AuthenticatorAssertionResponseValidator::create($this->steps->requestCeremony())
                ->check($record, $credential->response, $options, $this->rpId, $userHandle);
        } catch (\Exception|\TypeError $e) {
            throw new PasskeyRefused('', 0, $e);
        }

        return $this->toJson($updated);
    }

    /**
     * @param list<string> $ids base64url
     *
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptors(array $ids): array
    {
        $descriptors = [];
        foreach ($ids as $id) {
            $raw = self::decode($id);
            if (null !== $raw) {
                $descriptors[] = PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, $raw);
            }
        }

        return $descriptors;
    }

    private function toJson(object $value): string
    {
        return $this->serializer->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
            JsonEncode::OPTIONS => \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES,
        ]);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $base64Url): ?string
    {
        $base64 = strtr($base64Url, '-_', '+/');
        $raw = base64_decode(str_pad($base64, (int) ceil(\strlen($base64) / 4) * 4, '='), true);

        return false === $raw || '' === $raw ? null : $raw;
    }
}
