<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Response;
use App\Module\Invoices\Application\FacturX\FacturXRefused;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The OpenAPI document is the contract the TypeScript client is generated from: a property the API always
 * sends must be "required" there, or every consumer has to null-check what is never null.
 */
final class OpenApiTest extends KernelTestCase
{
    public function testTheAuthEndpointsAndTheMeShapeAreInTheContract(): void
    {
        self::bootKernel();
        $openApi = static::getContainer()->get(OpenApiFactoryInterface::class)();
        $schemas = $openApi->getComponents()->getSchemas();
        self::assertNotNull($schemas);

        $paths = $openApi->getPaths();
        self::assertNotNull($paths->getPath('/api/auth/login')?->getPost());
        self::assertNotNull($paths->getPath('/api/auth/logout')?->getPost());
        self::assertNotNull($paths->getPath('/api/auth/me')?->getGet());
        self::assertNotNull($paths->getPath('/api/health')?->getGet());
        self::assertNotNull($paths->getPath('/api/auth/mfa/passkeys')?->getPost());
        self::assertNotNull($paths->getPath('/api/auth/mfa/passkeys/{id}')?->getDelete());
        self::assertNotNull($paths->getPath('/api/auth/mfa/passkey-login')?->getPost());
        self::assertNotNull($paths->getPath('/api/auth/mfa/recovery-codes/passkey/options')?->getPost());
        self::assertNotNull($paths->getPath('/api/platform/settings')?->getGet());
        self::assertNotNull($paths->getPath('/api/platform/settings/{key}')?->getPut());
        self::assertNotNull($paths->getPath('/api/platform/companies')?->getGet());
        self::assertNotNull($paths->getPath('/api/platform/companies/{companyId}/approve')?->getPost());
        self::assertNotNull($paths->getPath('/api/platform/companies/{companyId}/reject')?->getPost());
        $signup = $paths->getPath('/api/signup');
        self::assertNotNull($signup?->getGet());
        self::assertNotNull($signup->getPost());
        self::assertNotNull($paths->getPath('/api/signup/{token}')?->getGet());
        self::assertNotNull($paths->getPath('/api/signup/{token}/complete')?->getPost());
        self::assertNotNull($paths->getPath('/api/auth/mfa/recovery-codes/passkey')?->getPost());

        self::assertSame(['user', 'company', 'permissions', 'mfa', 'modules'], $this->required($schemas['Me']));
        self::assertSame(['enrolled', 'required', 'totp', 'passkeys'], $this->required($schemas['MeMfa']));
        self::assertSame(['id', 'email', 'displayName', 'locale', 'isPlatformOperator'], $this->required($schemas['MeUser']));
        self::assertSame(['id', 'name', 'countryCode', 'currency', 'locale', 'timezone', 'status', 'role', 'access', 'subscription'], $this->required($schemas['MeCompany']));
        self::assertSame(['email', 'password'], $this->required($schemas['LoginRequest']));
        self::assertSame(['status', 'database'], $this->required($schemas['Health']));

        // ArrayObjects all the way down: a JSON round trip is the plain view of the schema.
        $me = json_decode(json_encode($schemas['Me'], \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($me);
        self::assertIsArray($me['properties']);
        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $me['properties']['permissions']);
        $company = $me['properties']['company'];
        self::assertIsArray($company);
        self::assertIsArray($company['anyOf'] ?? null);
        self::assertContains(['type' => 'null'], $company['anyOf'], 'company is required but may be null');
    }

    public function testASettingValueIsAnyJsonValueAndEachLevelNamesItsValue(): void
    {
        self::bootKernel();
        $schemas = static::getContainer()->get(OpenApiFactoryInterface::class)()->getComponents()->getSchemas();
        self::assertNotNull($schemas);
        self::assertTrue(isset($schemas['Setting-setting.read']), 'the settings read shape is in the contract');

        $setting = json_decode(json_encode($schemas['Setting-setting.read'], \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($setting);
        self::assertIsArray($setting['properties']);
        // A setting holds a colour, a number, a flag or a list layout: typed as a string, a layout would not compile.
        foreach (['value', 'default'] as $name) {
            $property = $setting['properties'][$name];
            self::assertIsArray($property);
            self::assertIsArray($property['anyOf'] ?? null);
            self::assertContains(['type' => 'object', 'additionalProperties' => true], $property['anyOf'], "$name may be an object");
            self::assertContains(['type' => 'boolean'], $property['anyOf'], "$name may be a flag");
        }
        $levels = $setting['properties']['levels'];
        self::assertIsArray($levels);
        self::assertIsArray($levels['items'] ?? null);
        self::assertSame(['level', 'value'], $levels['items']['required'] ?? null);
    }

    public function testTheFacturXFilesAndWhyThereIsNoneAreInTheContract(): void
    {
        self::bootKernel();
        $paths = static::getContainer()->get(OpenApiFactoryInterface::class)()->getPaths();

        foreach (['/factur-x.xml' => 'application/xml', '/factur-x.pdf' => 'application/pdf'] as $file => $type) {
            $operation = $paths->getPath('/api/companies/{companyId}/invoices/{invoiceId}'.$file)?->getGet();
            self::assertNotNull($operation, $file);
            $responses = $operation->getResponses() ?? [];
            self::assertSame(['200', '401', '404', '409', '422'], array_values(array_intersect(['200', '401', '404', '409', '422'], array_map(strval(...), array_keys($responses)))), $file);
            $ok = $responses['200'] ?? null;
            self::assertInstanceOf(Response::class, $ok);
            self::assertArrayHasKey($type, $ok->getContent()?->getArrayCopy() ?? [], $file);
            $refusal = $responses['422'] ?? null;
            self::assertInstanceOf(Response::class, $refusal);
            $media = $refusal->getContent()?->getArrayCopy()['application/json'] ?? null;
            self::assertInstanceOf(MediaType::class, $media, $file);
            $schema = $media->getSchema()?->getArrayCopy();
            self::assertIsArray($schema);
            self::assertSame(['code', 'params', 'message', 'gaps'], $schema['required'] ?? null, $file);
            $properties = $schema['properties'] ?? null;
            self::assertIsArray($properties);
            $gaps = $properties['gaps'] ?? null;
            self::assertIsArray($gaps);
            $item = $gaps['items'] ?? null;
            self::assertIsArray($item);
            $itemProperties = $item['properties'] ?? null;
            self::assertIsArray($itemProperties);
            $code = $itemProperties['code'] ?? null;
            self::assertIsArray($code);
            self::assertSame(FacturXRefused::GAPS, $code['enum'] ?? null, $file);
        }
    }

    /** @return list<string> */
    private function required(mixed $schema): array
    {
        $array = $schema instanceof \ArrayObject ? $schema->getArrayCopy() : $schema;
        self::assertIsArray($array);
        $required = $array['required'] ?? [];
        self::assertIsArray($required);
        $names = [];
        foreach ($required as $name) {
            self::assertIsString($name);
            $names[] = $name;
        }

        return $names;
    }
}
