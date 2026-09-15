<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\CsrfRequestListener;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Functional tests talk to the API the way the SPA does: JSON bodies, a random csrf-token header on every
 * unsafe request, cookies kept between requests. Rows are created through the entity manager and rolled back by dama.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Every request from a browser tab carries the site's origin; the CSRF manager checks it.
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // The login limiter's counters live in a filesystem pool that outlives the transaction dama rolls back.
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param list<string> $permissions */
    protected function createUser(string $email, string $password, ?Company $company = null, array $permissions = ['*'], string $roleName = Role::OWNER, bool $operator = false, bool $active = true): User
    {
        $em = $this->em();
        $user = new User(Email::fromString($email), ucfirst(strtok($email, '@') ?: 'User'));
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash($password), new \DateTimeImmutable());
        $user->setPlatformOperator($operator);
        $user->setActive($active);
        $em->persist($user);
        if (null !== $company) {
            $role = new Role($roleName, $permissions, $company);
            $em->persist($role);
            $em->persist(new Membership($user, $company, $role));
        }
        $em->flush();

        return $user;
    }

    protected function createCompany(string $name = 'Demo'): Company
    {
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->em()->persist($company);
        $this->em()->flush();

        return $company;
    }

    /** The SPA's token: one random value per page load, sent as a header (framework.csrf_protection, header only). */
    private const string CSRF_TOKEN = '0123456789abcdef0123456789abcdef';

    /**
     * A second (or third) company for a user who already exists.
     *
     * @param list<string> $permissions
     */
    protected function addMembership(User $user, Company $company, string $roleName = Role::MEMBER, array $permissions = ['*']): void
    {
        $em = $this->em();
        $role = new Role($roleName, $permissions, $company);
        $em->persist($role);
        $em->persist(new Membership($user, $company, $role));
        $em->flush();
    }

    /** @param array<string, mixed>|null $body */
    protected function postJson(string $path, ?array $body, bool $withCsrf = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($withCsrf) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER))] = self::CSRF_TOKEN;
        }
        $this->client->request('POST', $path, [], [], $headers, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** A file sent the way the SPA's FormData does: one multipart part under `$field`. */
    protected function uploadFile(string $path, string $name, string $contents, string $field = 'file'): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($tmp, $contents);
        $this->client->request('POST', $path, [], [$field => new UploadedFile($tmp, $name, null, null, true)], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER)) => self::CSRF_TOKEN,
        ]);
    }

    /** @param array<string, mixed>|null $body */
    protected function sendJson(string $method, string $path, ?array $body = null, bool $withCsrf = true): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($withCsrf) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER))] = self::CSRF_TOKEN;
        }
        $this->client->request($method, $path, [], [], $headers, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    protected function getJson(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    /** @return list<array<string, mixed>> */
    protected function jsonList(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $out = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            $out[] = self::stringKeyed($row);
        }

        return $out;
    }

    /** AddMember resolves a role through RoleRepository::builtIn, which only ever returns a company-less role. */
    protected function seedBuiltInRoles(): void
    {
        $em = $this->em();
        $em->persist(new Role(Role::OWNER, [Permission::WILDCARD]));
        $em->persist(new Role(Role::ADMIN, ['user.read', 'user.write', 'company.read']));
        $em->persist(new Role(Role::MEMBER, ['company.read']));
        $em->flush();
    }

    protected function login(string $email, string $password): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return self::stringKeyed($body);
    }

    /**
     * Scalars out of a JSON body, asserted to be what they are. PHPStan runs at max here, so a test that
     * indexes straight into a decoded body is indexing into `mixed`.
     *
     * @param array<string, mixed> $body
     */
    protected function stringAt(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        self::assertIsString($value, "$key is a string");

        return $value;
    }

    /** @param array<string, mixed> $body */
    protected function boolAt(array $body, string $key): bool
    {
        $value = $body[$key] ?? null;
        self::assertIsBool($value, "$key is a boolean");

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    protected function arrayAt(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        self::assertIsArray($value, "$key is an array");

        return $value;
    }

    /**
     * A nested object of a JSON body, asserted to be one.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function section(array $body, string $key): array
    {
        $section = $body[$key] ?? null;
        self::assertIsArray($section, "$key is an object");

        return self::stringKeyed($section);
    }

    /**
     * @param array<mixed> $array
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $array): array
    {
        $out = [];
        foreach ($array as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /** The kernel reboots between requests, so an entity from before a request must be re-read, not refreshed. */
    protected function reload(User $user): User
    {
        $fresh = $this->em()->find(User::class, $user->getId());
        self::assertNotNull($fresh);

        return $fresh;
    }
}
