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
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use App\Identity\Infrastructure\Security\CsrfRequestListener;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests talk to the API the way the SPA does: JSON bodies, a random csrf-token header on every
 * unsafe request, cookies kept between requests. Rows are created through the entity manager and rolled back by dama.
 */
abstract class ApiTestCase extends WebTestCase
{
    /** The authenticator createUser enrols; .env.test's key encrypts it. */
    protected const string AUTHENTICATOR_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const string MFA_TEST_KEY = 'Fh0hAlB8Q7xUq0mJ0zRz2s4vXn6yKbPd8eGtWc3AjQY=';

    protected KernelBrowser $client;

    /** @var array<string, true> the addresses createUser enrolled, whose login() goes through the code step */
    private array $enrolled = [];

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

    /**
     * An operator gets an authenticator unless `$authenticator` says otherwise: every operator must carry one
     * (docs/SPEC.md § 7, 2026-09-15, S3), so a test of anything else signs them in the way they really sign in.
     *
     * @param list<string> $permissions
     */
    protected function createUser(string $email, string $password, ?Company $company = null, array $permissions = ['*'], string $roleName = Role::OWNER, bool $operator = false, bool $active = true, ?bool $authenticator = null): User
    {
        $em = $this->em();
        $user = new User(Email::fromString($email), ucfirst(strtok($email, '@') ?: 'User'));
        $user->setPasswordHash(static::getContainer()->get(PasswordHasher::class)->hash($password), new \DateTimeImmutable());
        $user->setPlatformOperator($operator);
        $user->setActive($active);
        if ($authenticator ?? $operator) {
            $user->beginTotpEnrolment((new SodiumSecretCipher(self::MFA_TEST_KEY))->encrypt(self::AUTHENTICATOR_SECRET));
            $user->confirmTotpEnrolment(self::spentTimestep());
            $this->enrolled[strtolower($email)] = true;
        }
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

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server extra server parameters, such as `HTTP_X_TAB`
     */
    protected function postJson(string $path, ?array $body, bool $withCsrf = true, array $server = []): void
    {
        $headers = ['CONTENT_TYPE' => 'application/json', ...$server];
        if ($withCsrf) {
            $headers['HTTP_'.strtoupper(str_replace('-', '_', CsrfRequestListener::HEADER))] = self::CSRF_TOKEN;
        }
        $this->client->request('POST', $path, [], [], $headers, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * A file sent the way the SPA's FormData does: one multipart part under `$field`.
     *
     * @param array<string, string> $parameters form fields sent beside the file
     */
    protected function uploadFile(string $path, string $name, string $contents, string $field = 'file', array $parameters = []): void
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents($tmp, $contents);
        $this->client->request('POST', $path, $parameters, [$field => new UploadedFile($tmp, $name, null, null, true)], [
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

    /** Accepts what the SPA's HttpClient accepts: a list answers JSON-LD, a single record plain JSON (docs/SPEC.md § 7). */
    protected function getJson(string $path): void
    {
        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT' => 'application/json, text/plain, */*']);
    }

    /** @return list<array<string, mixed>> the rows of a list, whether a Hydra page or a plain array */
    protected function jsonList(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        if (\array_key_exists('member', $body)) {
            $body = $body['member'];
            self::assertIsArray($body);
        }
        $out = [];
        foreach ($body as $row) {
            self::assertIsArray($row);
            $out[] = self::stringKeyed($row);
        }

        return $out;
    }

    /** An invitation and its acceptance resolve a role through RoleRepository::builtIn, which only ever returns a company-less role. */
    protected function seedBuiltInRoles(): void
    {
        $em = $this->em();
        $em->persist(new Role(Role::OWNER, [Permission::WILDCARD]));
        $em->persist(new Role(Role::ADMIN, ['user.read', 'user.write', 'company.read']));
        $em->persist(new Role(Role::MEMBER, ['company.read']));
        $em->flush();
    }

    /** Signs in; for an account createUser enrolled, the code step too, leaving the answer a one-step login gives. */
    protected function login(string $email, string $password): void
    {
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
        if (!isset($this->enrolled[strtolower($email)]) || Response::HTTP_OK !== $this->client->getResponse()->getStatusCode() || true !== ($this->json()['mfaRequired'] ?? null)) {
            return;
        }

        // The last spent step goes back into the past first, so signing in twice within thirty seconds is not a replay.
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString($email)]);
        self::assertNotNull($user);
        $user->confirmTotpEnrolment(self::spentTimestep());
        $this->em()->flush();
        $this->postJson('/api/auth/mfa/verify', ['code' => (new OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, new \DateTimeImmutable())]);
    }

    /** A step a minute old: any code for now is newer than it. */
    private static function spentTimestep(): int
    {
        return intdiv((new \DateTimeImmutable('-1 minute'))->getTimestamp(), 30);
    }

    /**
     * What a Hydra page says about itself: `totalItems` and, when there is more than one page, `view`.
     *
     * @return array{totalItems: int, view?: array<string, mixed>}
     */
    protected function jsonPage(): array
    {
        self::assertResponseHeaderSame('Content-Type', 'application/ld+json; charset=utf-8');
        $body = $this->json();
        self::assertIsInt($body['totalItems'] ?? null, 'a page carries its total');
        $page = ['totalItems' => $body['totalItems']];
        if (isset($body['view'])) {
            $page['view'] = $this->section($body, 'view');
        }

        return $page;
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
