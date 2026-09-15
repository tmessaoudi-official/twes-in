<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * POST /api/auth/login and POST /api/auth/logout are firewall endpoints (json_login, logout) and GET /api/health
 * is a plain controller, so API Platform does not know them. This adds them to the OpenAPI document, which is
 * where the TypeScript client's types come from: LoginRequest, the error shape, the health shape, and the
 * fact that a login answers a Me.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class OpenApiExtras implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['LoginRequest'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['email', 'password'],
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string', 'format' => 'password'],
            ],
        ]);
        $schemas['AuthError'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'string',
                    'enum' => ['invalid_credentials', 'account_locked', 'account_disabled', 'too_many_attempts', 'authentication_required', 'csrf_token_missing', 'csrf_token_invalid', 'mfa_not_pending', 'invalid_code', 'mfa_enrolment_required', 'mfa_already_enrolled', 'invalid_passkey', 'mfa_last_factor', 'passkey_not_found'],
                ],
            ],
        ]);
        $schemas['Health'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['status', 'database'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                'database' => ['type' => 'string', 'enum' => ['ok', 'unreachable']],
            ],
        ]);

        $me = $this->meSchemaName($schemas);
        $errorResponse = static fn (string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/AuthError']))]));

        $openApi->getPaths()->addPath('/api/auth/login', new PathItem(post: new Operation(
            operationId: 'login',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Signed in, or a second factor is owed', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['oneOf' => [['$ref' => '#/components/schemas/'.$me], ['$ref' => '#/components/schemas/MfaPending']]]))])),
                '400' => $errorResponse('Malformed body'),
                '401' => $errorResponse('Wrong credentials, locked or disabled account'),
                '403' => $errorResponse('CSRF check failed'),
                '429' => $errorResponse('Too many attempts'),
            ],
            summary: 'Sign in with email and password',
            requestBody: new RequestBody('Credentials', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/LoginRequest']))]), true),
        )));
        $openApi->getPaths()->addPath('/api/auth/logout', new PathItem(post: new Operation(
            operationId: 'logout',
            tags: ['Auth'],
            responses: [
                '204' => new Response('Signed out; the session is invalidated'),
                '403' => $errorResponse('CSRF check failed'),
            ],
            summary: 'Sign out',
        )));
        // The MFA endpoints are plain controllers for the same reason login is: one of them has to call
        // Security::login() itself. They still belong in the document, because that is where the SPA's types
        // come from.
        $schemas['MfaCode'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['code'],
            'properties' => [
                'code' => ['type' => 'string', 'description' => 'A six-digit code, or a recovery code.'],
            ],
        ]);
        $schemas['MfaPending'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['mfaRequired'],
            'properties' => ['mfaRequired' => ['type' => 'boolean', 'enum' => [true]]],
        ]);
        $schemas['MfaEnrolment'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['secret', 'provisioningUri'],
            'properties' => [
                'secret' => ['type' => 'string', 'description' => 'Base32, for typing in by hand.'],
                'provisioningUri' => ['type' => 'string', 'description' => 'otpauth:// URI, for the QR code.'],
            ],
        ]);
        $schemas['MfaRecoveryCodes'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['recoveryCodes'],
            'properties' => [
                'recoveryCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Shown once and never again.'],
            ],
        ]);

        $jsonOf = static fn (string $schema, string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]));
        $codeBody = new RequestBody('The code', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/MfaCode']))]), true);

        $openApi->getPaths()->addPath('/api/auth/mfa/verify', new PathItem(post: new Operation(
            operationId: 'verifySecondFactor',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Signed in; the session cookie is set', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$me]))])),
                '401' => $errorResponse('No pending login, or the code is wrong'),
                '429' => $errorResponse('Too many attempts'),
            ],
            summary: 'Finish a login that owes a second factor',
            requestBody: $codeBody,
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/enrolment', new PathItem(post: new Operation(
            operationId: 'beginMfaEnrolment',
            tags: ['Auth'],
            responses: [
                '200' => $jsonOf('MfaEnrolment', 'A pending secret; not in force until confirmed'),
                '401' => $errorResponse('Not signed in'),
                '409' => $errorResponse('An authenticator is already in force'),
            ],
            summary: 'Start enrolling an authenticator',
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/enrolment/confirm', new PathItem(post: new Operation(
            operationId: 'confirmMfaEnrolment',
            tags: ['Auth'],
            responses: [
                '200' => $jsonOf('MfaRecoveryCodes', 'Enrolled; the recovery codes are shown once'),
                '401' => $errorResponse('Not signed in'),
                '422' => $errorResponse('The code does not match the pending secret'),
            ],
            summary: 'Confirm the authenticator and receive the recovery codes',
            requestBody: $codeBody,
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/recovery-codes', new PathItem(post: new Operation(
            operationId: 'regenerateRecoveryCodes',
            tags: ['Auth'],
            responses: [
                '200' => $jsonOf('MfaRecoveryCodes', 'A new set; every earlier code stops working'),
                '401' => $errorResponse('Not signed in'),
                '422' => $errorResponse('No authenticator, or the code is not a current authenticator code'),
                '429' => $errorResponse('Too many attempts'),
            ],
            summary: 'Replace the recovery codes, proven by a current authenticator code',
            requestBody: $codeBody,
        )));

        // Passkeys. Options and credentials are WebAuthn's own JSON (Level 3 § 5.1 toJSON, § 5.4 and § 5.5), which the SPA
        // hands to PublicKeyCredential.parseCreationOptionsFromJSON / parseRequestOptionsFromJSON and posts back unchanged.
        $schemas['PublicKeyCredentialOptionsJson'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['challenge'],
            'additionalProperties' => true,
            'properties' => ['challenge' => ['type' => 'string', 'description' => 'Base64url.']],
        ]);
        $schemas['PasskeyCredentialJson'] = new \ArrayObject([
            'type' => 'object',
            'additionalProperties' => true,
            'description' => 'What PublicKeyCredential.toJSON() returns.',
        ]);
        $schemas['Passkey'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['id', 'name', 'createdAt', 'lastUsedAt'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'lastUsedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ],
        ]);
        $schemas['PasskeyList'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['passkeys'],
            'properties' => ['passkeys' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Passkey']]],
        ]);
        $schemas['PasskeyRegistration'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['name', 'credential'],
            'properties' => [
                'name' => ['type' => 'string', 'maxLength' => 80],
                'credential' => ['$ref' => '#/components/schemas/PasskeyCredentialJson'],
            ],
        ]);
        $schemas['PasskeyRegistered'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['passkey', 'recoveryCodes'],
            'properties' => [
                'passkey' => ['$ref' => '#/components/schemas/Passkey'],
                'recoveryCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Ten codes, shown once, when this passkey is the first factor; otherwise empty.'],
            ],
        ]);
        $schemas['PasskeyAssertion'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['credential'],
            'properties' => ['credential' => ['$ref' => '#/components/schemas/PasskeyCredentialJson']],
        ]);
        $bodyOf = static fn (string $schema, string $description): RequestBody => new RequestBody($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]), true);

        $openApi->getPaths()->addPath('/api/auth/mfa/passkeys', new PathItem(
            get: new Operation(
                operationId: 'listPasskeys',
                tags: ['Auth'],
                responses: [
                    '200' => $jsonOf('PasskeyList', 'The passkeys of the signed-in account, oldest first'),
                    '401' => $errorResponse('Not signed in'),
                ],
                summary: 'List the passkeys of the signed-in account',
            ),
            post: new Operation(
                operationId: 'registerPasskey',
                tags: ['Auth'],
                responses: [
                    '201' => $jsonOf('PasskeyRegistered', 'Registered and in force'),
                    '401' => $errorResponse('Not signed in'),
                    '422' => $errorResponse('No registration under way, or the credential does not verify'),
                ],
                summary: 'Register a passkey against the creation options just issued',
                requestBody: $bodyOf('PasskeyRegistration', 'The new credential and a name for it'),
            ),
        ));
        $openApi->getPaths()->addPath('/api/auth/mfa/passkeys/options', new PathItem(post: new Operation(
            operationId: 'passkeyRegistrationOptions',
            tags: ['Auth'],
            responses: [
                '200' => $jsonOf('PublicKeyCredentialOptionsJson', 'Creation options, answerable once within five minutes'),
                '401' => $errorResponse('Not signed in'),
            ],
            summary: 'Start registering a passkey',
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/passkeys/{id}', new PathItem(delete: new Operation(
            operationId: 'removePasskey',
            tags: ['Auth'],
            responses: [
                '204' => new Response('Removed'),
                '401' => $errorResponse('Not signed in'),
                '404' => $errorResponse('No such passkey on this account'),
                '409' => $errorResponse('The last factor of an account a company requires to have one'),
            ],
            summary: 'Remove a passkey',
            parameters: [new Parameter('id', 'path', 'The passkey', true, schema: ['type' => 'string', 'format' => 'uuid'])],
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/passkey-login/options', new PathItem(post: new Operation(
            operationId: 'passkeyLoginOptions',
            tags: ['Auth'],
            responses: [
                '200' => $jsonOf('PublicKeyCredentialOptionsJson', 'Request options for the pending account'),
                '401' => $errorResponse('No pending login, or the account has no passkey'),
            ],
            summary: 'Start answering a pending login with a passkey',
        )));
        $openApi->getPaths()->addPath('/api/auth/mfa/passkey-login', new PathItem(post: new Operation(
            operationId: 'finishPasskeyLogin',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Signed in; the session cookie is set', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$me]))])),
                '401' => $errorResponse('No pending login, or the passkey does not verify'),
                '429' => $errorResponse('Too many attempts'),
            ],
            summary: 'Finish a login that owes a second factor with a passkey',
            requestBody: $bodyOf('PasskeyAssertion', 'The assertion'),
        )));

        $health = static fn (string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/Health']))]));
        $openApi->getPaths()->addPath('/api/health', new PathItem(get: new Operation(
            operationId: 'health',
            tags: ['Health'],
            responses: ['200' => $health('The database answers'), '503' => $health('The database does not answer')],
            summary: 'Liveness, public',
        )));

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    /**
     * API Platform names the Me schema by resource and format ("Me", "Me.jsonld", …); pick whatever it produced.
     *
     * @param \ArrayObject<string, mixed> $schemas
     */
    private function meSchemaName(\ArrayObject $schemas): string
    {
        foreach (array_keys($schemas->getArrayCopy()) as $name) {
            if ('Me' === $name || str_starts_with($name, 'Me.') || str_starts_with($name, 'Me-')) {
                return $name;
            }
        }

        throw new \LogicException('The Me resource must produce an OpenAPI schema before the login endpoint can reference it.');
    }
}
