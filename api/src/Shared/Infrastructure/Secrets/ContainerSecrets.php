<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Secrets;

/**
 * Reads the compose secrets a deployment mounts under /run/secrets into the environment the application reads, so a
 * secret is never written in a container's environment, where `docker inspect` and `docker compose config` show it
 * (docs/SPEC.md § 7, Docker secrets). Each file becomes the variable its name says in capitals, `mailer_dsn` the
 * MAILER_DSN; the database's addresses are written as templates, `DATABASE_URL_TEMPLATE` holding
 * `{postgres_app_password}` where its password goes, which make DATABASE_URL. Run from Composer's autoloader
 * (config/container-secrets.php), so the server's workers and a console started by `docker compose exec`, which skips
 * the entrypoint, read the same values. No directory reads nothing, as in development and the tests.
 *
 * FrankenPHP's worker runner gives each request what was set at boot only beneath the request's own environment
 * (`$_SERVER += $server`, symfony/runtime's FrankenPhpWorkerRunner), so a variable this sets must not be in the
 * container's environment at all, not even empty: the workers would read that while every console read the secret.
 * Both are refused at start, by name.
 */
final class ContainerSecrets
{
    /** The addresses made from a template carrying secrets as placeholders, each from `<NAME>_TEMPLATE`. */
    private const array ADDRESSES = ['DATABASE_URL', 'DATABASE_MIGRATION_URL'];

    public static function load(string $directory = '/run/secrets'): void
    {
        foreach (self::values($directory, getenv()) as $name => $value) {
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }
    }

    /**
     * What to set, by variable, from the files in the directory and the container's environment.
     *
     * @param array<string, string> $environment
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException naming the variable at fault, never its value
     */
    public static function values(string $directory, array $environment): array
    {
        $values = [];
        foreach (self::files($directory) as $name => $path) {
            $variable = strtoupper($name);
            // A program that reads the file itself, as PostgreSQL's image reads POSTGRES_PASSWORD_FILE, is left to it.
            if (isset($environment[$variable.'_FILE'])) {
                continue;
            }
            if (\array_key_exists($variable, $environment)) {
                throw new \RuntimeException(\sprintf('%s is a mounted secret and in the container\'s environment, which the server\'s workers read instead: remove it from the environment.', $variable));
            }
            $value = file_get_contents($path);
            if (false === $value || '' === $value) {
                throw new \RuntimeException(\sprintf('The secret %s is empty: set %s in the values file.', $name, $variable));
            }
            $values[$variable] = $value;
        }
        foreach (self::ADDRESSES as $address) {
            $template = $address.'_TEMPLATE';
            if (isset($environment[$address]) && str_contains($environment[$address], '{')) {
                throw new \RuntimeException(\sprintf('%s carries a placeholder, which the server\'s workers would read as it is: name it %s.', $address, $template));
            }
            if (!isset($environment[$template])) {
                continue;
            }
            if (\array_key_exists($address, $environment)) {
                throw new \RuntimeException(\sprintf('%s is in the container\'s environment beside %s, and the server\'s workers would read it instead: remove it.', $address, $template));
            }
            $values[$address] = (string) preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', static function (array $placeholder) use ($values, $template): string {
                $variable = strtoupper($placeholder[1]);
                $value = $values[$variable] ?? throw new \RuntimeException(\sprintf('%s names the secret %s, which is not mounted: set %s in the values file.', $template, $placeholder[1], $variable));
                // Set in the address as it is, so it must not change the address's shape.
                if (1 !== preg_match('/^[A-Za-z0-9]+$/', $value)) {
                    throw new \RuntimeException(\sprintf('%s holds more than letters and digits, so it cannot sit in %s as it is: make a new one with scripts/deploy/new-instance.sh.', $variable, $template));
                }

                return $value;
            }, $environment[$template]);
        }

        return $values;
    }

    /** @return array<string, string> each secret's path, by its name */
    private static function files(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.'/'.$name;
            if (str_starts_with($name, '.') || !is_file($path)) {
                continue;
            }
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \RuntimeException(\sprintf('The secret %s has a name no variable can carry: lower-case letters, digits and _ only.', $name));
            }
            $files[$name] = $path;
        }

        return $files;
    }
}
