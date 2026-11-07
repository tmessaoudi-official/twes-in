<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Infrastructure\Http\ReceivedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class ReceivedFileTest extends TestCase
{
    public function testAFileReceivedWholeIsHandedOver(): void
    {
        $file = new UploadedFile(__FILE__, 'receipt.pdf', null, \UPLOAD_ERR_OK, true);

        self::assertSame($file, ReceivedFile::of(new Request(files: ['file' => $file])));
    }

    public function testARequestWithNoFilePartIsRefusedOnFileAsSomethingToChoose(): void
    {
        self::assertSame([['file', 'value_required', []]], self::refusalsOf(new Request()));
        self::assertSame([['file', 'value_required', []]], self::refusalsOf(new Request(files: ['photo' => new UploadedFile(__FILE__, 'a.pdf', null, \UPLOAD_ERR_OK, true)])));
    }

    /** @return iterable<string, array{int, string, array<string, int>}> */
    public static function errors(): iterable
    {
        yield 'above what PHP takes' => [\UPLOAD_ERR_INI_SIZE, 'file_too_large', ['bytes' => ReceivedFile::phpLimit()]];
        yield 'above what the form said' => [\UPLOAD_ERR_FORM_SIZE, 'file_too_large', ['bytes' => ReceivedFile::phpLimit()]];
        yield 'cut short on the way' => [\UPLOAD_ERR_PARTIAL, 'file_not_received', []];
        yield 'nothing chosen' => [\UPLOAD_ERR_NO_FILE, 'value_required', []];
        yield 'no room to keep it' => [\UPLOAD_ERR_CANT_WRITE, 'file_not_received', []];
    }

    /** @param array<string, int> $params */
    #[DataProvider('errors')]
    public function testAFilePhpRefusedIsRefusedOnFileWithItsReason(int $error, string $reason, array $params): void
    {
        $file = new UploadedFile(__FILE__, 'receipt.pdf', null, $error, true);

        self::assertSame([['file', $reason, $params]], self::refusalsOf(new Request(files: ['file' => $file])));
    }

    /** @return list<array{string, mixed, mixed}> */
    private static function refusalsOf(Request $request): array
    {
        try {
            ReceivedFile::of($request);
        } catch (ValidationException $refused) {
            $found = [];
            foreach ($refused->getConstraintViolationList() as $violation) {
                $payload = $violation->getConstraint()?->payload;
                self::assertIsArray($payload);
                $found[] = [$violation->getPropertyPath(), $payload['reason'] ?? null, $payload['params'] ?? []];
            }

            return $found;
        }
        self::fail('the request was not refused');
    }
}
