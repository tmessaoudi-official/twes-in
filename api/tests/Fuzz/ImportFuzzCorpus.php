<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Fuzz;

use App\Shared\Infrastructure\Spreadsheet\OpenSpoutReader;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The import files a person, a broken export or an attacker may send: wrong and mixed encodings, broken and hostile
 * workbooks, values no subject expects. Built in memory and deterministic: the fixed cases are the same on every run,
 * and the seeded ones are the same for the same seed, so a red names a file that can be built again.
 *
 * Kept small enough for the normal suite; FUZZ_SEEDS asks for more seeded files on a longer run.
 */
final class ImportFuzzCorpus
{
    private const string MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const string RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const string OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** A declaration that, if any parser read it, would fetch a file of the machine into a cell. */
    private const string DOCTYPE = '<!DOCTYPE worksheet [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>';

    /** One cell big enough to matter and small enough for the toolchain's memory limit. */
    private const int HUGE_CELL = 1_000_000;

    private const int MANY_COLUMNS = 3000;

    /**
     * The seeds of a run: one by default, FUZZ_SEEDS of them when asked.
     *
     * @return list<int>
     */
    public static function seeds(): array
    {
        $asked = getenv('FUZZ_SEEDS');
        $count = false !== $asked && ctype_digit($asked) && (int) $asked > 0 ? (int) $asked : 1;

        return range(1, $count);
    }

    /**
     * Values a subject's columns are not written for, each placed in turn in every column.
     *
     * @return list<string>
     */
    public static function hostileValues(): array
    {
        return [
            "=cmd|' /C calc'!A0",
            '+1+1',
            '-2+3',
            '@SUM(A1:A9)',
            "\t=1+1",
            '=HYPERLINK("http://example.invalid","x")',
            "a\0b",
            "\u{202E}gnp.exe",
            "\u{FEFF}\u{200B}",
            '😀😀',
            '1e999',
            '-0',
            '99999999999999999999999999999',
            'NaN',
            '1,5',
            "'; DROP TABLE customer; --",
            '<b>x</b>',
            "line\nbreak",
            str_repeat('é', 3000),
        ];
    }

    /**
     * Files with the subject's own header, whose hostile values reach the subject's import of a row.
     *
     * @param list<string> $header
     *
     * @return list<FuzzFile>
     */
    public static function rowFiles(array $header): array
    {
        $width = \count($header);
        $rows = [$header];
        foreach (self::hostileValues() as $index => $value) {
            $row = [];
            for ($column = 0; $column < $width; ++$column) {
                // Every other column keeps a plain value, so a row's hostile cell is not always refused for a neighbour.
                $row[] = 0 === ($column + $index) % 2 ? $value : 'V'.$index.'-'.$column;
            }
            $rows[] = $row;
        }
        $huge = array_fill(0, $width, 'x');
        $huge[0] = str_repeat('A', self::HUGE_CELL);
        $wide = array_merge(array_fill(0, $width, 'w'), array_fill(0, self::MANY_COLUMNS, 'extra'));
        $utf8 = self::csv($rows);

        return [
            new FuzzFile('csv hostile values', 'f.csv', $utf8, rowLevel: true),
            new FuzzFile('xlsx hostile values', 'f.xlsx', self::workbook(self::sheet(self::xmlSafe($rows))), rowLevel: true),
            new FuzzFile('csv header only', 'f.csv', self::csv([$header]), rowLevel: true),
            new FuzzFile('xlsx header only', 'f.xlsx', self::workbook(self::sheet([$header])), rowLevel: true),
            new FuzzFile('csv huge cell', 'f.csv', self::csv([$header, $huge]), rowLevel: true),
            new FuzzFile('xlsx huge cell', 'f.xlsx', self::workbook(self::sheet([$header, $huge])), rowLevel: true),
            new FuzzFile('csv thousands of columns in a row', 'f.csv', self::csv([$header, $wide]), rowLevel: true),
            new FuzzFile('xlsx thousands of columns in a row', 'f.xlsx', self::workbook(self::sheet([$header, $wide])), rowLevel: true),
            new FuzzFile('csv thousands of header columns', 'f.csv', self::csv([array_merge($header, array_map(static fn (int $i): string => 'c'.$i, range(1, self::MANY_COLUMNS)))]), rowLevel: true),
            new FuzzFile('csv windows-1252', 'f.csv', (string) mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8'), rowLevel: true),
            new FuzzFile('csv windows-1256', 'f.csv', (string) iconv('UTF-8', 'CP1256//IGNORE', $utf8), rowLevel: true),
            new FuzzFile('csv utf-16le with mark', 'f.csv', "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'), rowLevel: true),
            new FuzzFile('csv utf-16be with mark', 'f.csv', "\xFE\xFF".mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), rowLevel: true),
            new FuzzFile('csv utf-16le without mark', 'f.csv', mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'), rowLevel: true),
            new FuzzFile('csv utf-16be without mark', 'f.csv', mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8'), rowLevel: true),
            new FuzzFile('csv mixed encodings', 'f.csv', self::csv([$header]).mb_convert_encoding(self::csv(\array_slice($rows, 1, 3)), 'Windows-1252', 'UTF-8').iconv('UTF-8', 'CP1256//IGNORE', self::csv(\array_slice($rows, 4, 3))), rowLevel: true),
            new FuzzFile('csv utf-16 lone high surrogate', 'f.csv', "\xFF\xFE".mb_convert_encoding(self::csv([$header]), 'UTF-16LE', 'UTF-8')."\x41\x00\x00\xD8\x42\x00\n\x00", rowLevel: true),
            new FuzzFile('csv utf-16 lone low surrogate', 'f.csv', "\xFF\xFE".mb_convert_encoding(self::csv([$header]), 'UTF-16LE', 'UTF-8')."\x41\x00\x00\xDC\x42\x00\n\x00", rowLevel: true),
            new FuzzFile('csv utf-16 odd last byte', 'f.csv', "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8')."\x41", rowLevel: true),
            new FuzzFile('csv utf-8 encoded surrogate and overlong', 'f.csv', self::csv([$header])."\xED\xA0\x80,\xC0\xAF,\xE2\x82\n", rowLevel: true),
            new FuzzFile('xlsx declaring a document type', 'f.xlsx', self::workbook(self::DOCTYPE.self::sheet(self::xmlSafe($rows))), declaresDoctype: true, rowLevel: true),
        ];
    }

    /**
     * Files broken below the row: no header can save them, so they are the same for every subject.
     *
     * @return list<FuzzFile>
     */
    public static function fileFiles(): array
    {
        $plain = self::workbook(self::sheet([['number', 'name'], ['1', 'x']]));
        $files = [
            new FuzzFile('csv empty', 'f.csv', ''),
            new FuzzFile('xlsx empty', 'f.xlsx', ''),
            new FuzzFile('csv blank lines only', 'f.csv', str_repeat("\n", 50)),
            new FuzzFile('csv blank cells only', 'f.csv', str_repeat(",,,\r\n", 50)),
            new FuzzFile('csv mark only', 'f.csv', "\xEF\xBB\xBF"),
            new FuzzFile('csv utf-16 mark only', 'f.csv', "\xFF\xFE"),
            new FuzzFile('csv unterminated quote', 'f.csv', "number,name\n\"1,x\n2,y"),
            new FuzzFile('csv carriage returns only', 'f.csv', "number,name\r1,x\r2,y\r"),
            new FuzzFile('xlsx not a zip', 'f.xlsx', "PK\x03\x04".str_repeat("\xAA", 64)),
            new FuzzFile('xlsx a csv by its name', 'f.xlsx', "number,name\n1,x\n"),
            new FuzzFile('ods by its name', 'f.ods', self::ods('')),
            new FuzzFile('ods shaped zip named xlsx', 'f.xlsx', self::ods('')),
            new FuzzFile('ods shaped zip declaring a document type', 'f.xlsx', self::ods(self::DOCTYPE), declaresDoctype: true),
            new FuzzFile('xlsx with no sheet', 'f.xlsx', self::workbook(self::sheet([]), workbook: '<workbook xmlns="'.self::MAIN.'"><sheets/></workbook>')),
            new FuzzFile('xlsx with no workbook', 'f.xlsx', self::zip(['[Content_Types].xml' => self::contentTypes()])),
            new FuzzFile('xlsx sheet with no data', 'f.xlsx', self::workbook('<worksheet xmlns="'.self::MAIN.'"/>')),
            new FuzzFile('xlsx sheet not xml', 'f.xlsx', self::workbook("\x00\x01 not xml <<")),
            new FuzzFile('xlsx sheet cut short', 'f.xlsx', self::workbook(substr(self::sheet([['number', 'name'], ['1', 'x']]), 0, 120))),
            new FuzzFile('xlsx undefined entity without a document type', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="inlineStr"><is><t>&xxe;</t></is></c></row>'))),
            new FuzzFile('xlsx forbidden control character', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="inlineStr"><is><t>a&#1;b</t></is></c></row>'))),
            new FuzzFile('xlsx missing shared string', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="s"><v>999</v></c></row>'))),
            new FuzzFile('xlsx shared strings not xml', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="s"><v>0</v></c></row>'), extra: ['xl/sharedStrings.xml' => '<sst><si><t>x'])),
            new FuzzFile('xlsx formula without a value', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1"><f>CMD|\' /C calc\'!A0</f></c><c r="B1" t="str"><f>A1</f></c></row>'))),
            new FuzzFile('xlsx formula in a shared string', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="s"><v>0</v></c></row>'), extra: ['xl/sharedStrings.xml' => '<sst xmlns="'.self::MAIN.'"><si><t>=cmd|\' /C calc\'!A0</t></si></sst>'])),
            new FuzzFile('xlsx odd cell types', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="b"><v>2</v></c><c r="B1" t="e"><v>#REF!</v></c><c r="C1" t="d"><v>not a day</v></c><c r="D1" t="n"><v>1e999</v></c><c r="E1" t="n"><v>abc</v></c><c r="F1" t="zz"><v>1</v></c><c r="G1" s="2"><v>abc</v></c><c r="H1" s="2"><v>-1e308</v></c></row>'))),
            new FuzzFile('xlsx percentages in odd places', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" s="1"><v>abc</v></c><c r="B1" s="1" t="inlineStr"><is><t>5</t></is></c><c r="C1" s="1"><v>1e300</v></c><c s="1"><v>0.5</v></c><c r="ZZZZZZZZZZZZZZZZZZZZZZZZ1" s="1"><v>0.1</v></c><c r="1A" s="1"><v>0.1</v></c><c r="" s="99999999999999999999"><v>1</v></c></row><row r="abc"><c r="A2" s="-1"><v>1</v></c></row><row r="99999999999999999999"><c s="1"><v>1</v></c></row>'))),
            new FuzzFile('xlsx thousands of columns', 'f.xlsx', self::workbook(self::sheet([array_map(static fn (int $i): string => 'c'.$i, range(1, self::MANY_COLUMNS))]))),
            new FuzzFile('xlsx relationship to a part that is not there', 'f.xlsx', self::workbook(self::sheet([['a']]), relationships: self::relationships('worksheets/nothing.xml'))),
            new FuzzFile('xlsx relationship climbing out', 'f.xlsx', self::workbook(self::sheet([['a']]), relationships: self::relationships('../../../../../etc/passwd'))),
            new FuzzFile('xlsx relationship from the root', 'f.xlsx', self::workbook(self::sheet([['a']]), relationships: self::relationships('/xl/worksheets/sheet1.xml'))),
            new FuzzFile('xlsx relationship to a url', 'f.xlsx', self::workbook(self::sheet([['a']]), relationships: self::relationships('http://example.invalid/sheet.xml'))),
            new FuzzFile('xlsx styles not xml', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" s="1"><v>1</v></c></row>'), styles: '<styleSheet><cellXfs><xf')),
            new FuzzFile('xlsx deep nesting', 'f.xlsx', self::workbook(self::sheetXml('<row r="1"><c r="A1" t="inlineStr"><is>'.str_repeat('<r>', 5000).'<t>x</t>'.str_repeat('</r>', 5000).'</is></c></row>'))),
        ];

        foreach (self::doctypePlaces() as $where => $parts) {
            $files[] = new FuzzFile('xlsx document type in '.$where, 'f.xlsx', self::zip($parts), declaresDoctype: true);
        }

        // The zip's directory understating what a part inflates to: the zip library refuses the part's bytes.
        $understated = self::zip(self::parts(self::sheet([['number', 'name'], ['1', str_repeat('y', 5000)]])));
        $files[] = new FuzzFile('xlsx directory understating a size', 'f.xlsx', self::understated($understated));

        // A part whose bytes no longer match its checksum: the zip library warns as it reads it.
        $parts = self::parts(self::sheetXml('<row r="1"><c r="A1" s="1"><v>0.5</v></c></row>'));
        foreach (['xl/styles.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml'] as $name) {
            $zip = self::zip($parts, [$name]);
            $at = strpos($zip, $parts[$name]);
            \assert(false !== $at);
            // The last byte of the part's own text, which no other part repeats at the same place.
            $at += \strlen($parts[$name]) - 2;
            $zip[$at] = 'X' === $zip[$at] ? 'Y' : 'X';
            $files[] = new FuzzFile('xlsx failing its checksum in '.$name, 'f.xlsx', $zip);
        }

        // A real writer's workbook as well as the hand-built one, cut at every tenth of its length.
        for ($tenth = 1; $tenth < 10; ++$tenth) {
            $files[] = new FuzzFile('xlsx truncated at '.$tenth.'0%', 'f.xlsx', substr($plain, 0, intdiv(\strlen($plain) * $tenth, 10)));
        }

        return $files;
    }

    /**
     * Files made by the seed: bytes of a valid workbook flipped and cut, random text in random encodings.
     *
     * @return list<FuzzFile>
     */
    public static function seededFiles(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $workbook = self::workbook(self::sheet([['number', 'name', 'kind'], ['1', 'x', 'individual'], ['2', 'y', 'company']]));
        $files = [];
        for ($i = 0; $i < 4; ++$i) {
            $flipped = $workbook;
            for ($flips = $random->getInt(1, 8); $flips > 0; --$flips) {
                $at = $random->getInt(0, \strlen($flipped) - 1);
                $flipped[$at] = \chr(\ord($flipped[$at]) ^ $random->getInt(1, 255));
            }
            $files[] = new FuzzFile(\sprintf('seed %d xlsx bytes flipped %d', $seed, $i), 'f.xlsx', $flipped);
            $files[] = new FuzzFile(\sprintf('seed %d xlsx cut %d', $seed, $i), 'f.xlsx', substr($workbook, 0, $random->getInt(0, \strlen($workbook) - 1)));
        }

        $alphabet = ['a', 'é', 'ج', ',', ';', '"', "\n", "\r", "\t", ' ', '=', '😀', "\u{FEFF}", '0', '9', '.'];
        for ($i = 0; $i < 4; ++$i) {
            $text = "number,name,kind\n";
            for ($length = $random->getInt(0, 400); $length > 0; --$length) {
                $text .= $alphabet[$random->getInt(0, \count($alphabet) - 1)];
            }
            $bytes = match ($random->getInt(0, 4)) {
                0 => $text,
                1 => (string) mb_convert_encoding($text, 'Windows-1252', 'UTF-8'),
                2 => (string) iconv('UTF-8', 'CP1256//IGNORE', $text),
                3 => "\xFF\xFE".mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'),
                default => $random->getBytes($random->getInt(1, 400)),
            };
            $files[] = new FuzzFile(\sprintf('seed %d csv random text %d', $seed, $i), 'f.csv', $bytes);
        }

        return $files;
    }

    /**
     * Each kind of part a document type may hide in, each spelling the scan has to see through.
     *
     * @return array<string, array<string, string>>
     */
    private static function doctypePlaces(): array
    {
        $sheet = self::sheet([['number', 'name'], ['1', 'x']]);
        $withDoctype = static fn (string $xml): string => str_contains($xml, '?>') ? (string) preg_replace('/\?>/', '?>'.self::DOCTYPE, $xml, 1) : self::DOCTYPE.$xml;
        $parts = self::parts($sheet);
        $places = [];
        foreach (['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/sheet1.xml', 'xl/styles.xml'] as $name) {
            $places[$name] = [...$parts, $name => $withDoctype($parts[$name])];
        }
        $places['shared strings'] = [...$parts, 'xl/sharedStrings.xml' => self::DOCTYPE.'<sst xmlns="'.self::MAIN.'"><si><t>&xxe;</t></si></sst>'];
        $places['a part of any name'] = [...$parts, 'customXml/anything.bin' => self::DOCTYPE];
        $places['lower case'] = [...$parts, 'xl/worksheets/sheet1.xml' => str_replace('<!DOCTYPE', '<!doctype', $withDoctype($sheet))];
        $places['utf-16'] = [...$parts, 'xl/worksheets/sheet1.xml' => "\xFF\xFE".mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $withDoctype($sheet)), 'UTF-16LE', 'UTF-8')];
        $places['utf-16be'] = [...$parts, 'xl/worksheets/sheet1.xml' => "\xFE\xFF".mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $withDoctype($sheet)), 'UTF-16BE', 'UTF-8')];
        // The XML declaration naming IBM037, and the document type, in EBCDIC.
        $places['ebcdic'] = [...$parts, 'xl/worksheets/sheet1.xml' => (string) iconv('UTF-8', 'IBM037', '<?xml version="1.0" encoding="IBM037"?>'.self::DOCTYPE.'<worksheet/>')];
        // Cut by the scan's reading in pieces: the declaration starts four bytes before the end of the first.
        $places['across two pieces'] = [...$parts, 'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><!--'.str_repeat('p', OpenSpoutReader::SCAN_CHUNK - 4 - 25).'-->'.self::DOCTYPE.'<worksheet/>'];
        $places['parameter entities'] = [...$parts, 'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY % p SYSTEM "http://example.invalid/x.dtd"> %p;]><worksheet/>'];
        $places['billion laughs'] = [...$parts, 'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><worksheet xmlns="'.self::MAIN.'"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&c;</t></is></c></row></sheetData></worksheet>'];

        return $places;
    }

    /** @param list<list<string>> $rows */
    private static function csv(array $rows): string
    {
        $out = fopen('php://memory', 'w+');
        \assert(false !== $out);
        foreach ($rows as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    /**
     * The values as XML 1.0 can carry them: a NUL or a control character is no character of a workbook.
     *
     * @param list<list<string>> $rows
     *
     * @return list<list<string>>
     */
    private static function xmlSafe(array $rows): array
    {
        return array_map(static fn (array $row): array => array_map(static fn (string $cell): string => (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cell), $row), $rows);
    }

    /** @param list<list<string>> $rows */
    private static function sheet(array $rows): string
    {
        $xml = '';
        foreach ($rows as $index => $row) {
            $xml .= '<row r="'.($index + 1).'">';
            foreach ($row as $cell) {
                $xml .= '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars($cell, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return self::sheetXml($xml);
    }

    private static function sheetXml(string $rows): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="'.self::MAIN.'"><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    /**
     * A workbook of one sheet, its parts as a spreadsheet application writes them unless a case names its own.
     *
     * @param array<string, string> $extra
     */
    private static function workbook(string $sheet, ?string $workbook = null, ?string $relationships = null, ?string $styles = null, array $extra = []): string
    {
        $parts = self::parts($sheet);
        if (null !== $workbook) {
            $parts['xl/workbook.xml'] = $workbook;
        }
        if (null !== $relationships) {
            $parts['xl/_rels/workbook.xml.rels'] = $relationships;
        }
        if (null !== $styles) {
            $parts['xl/styles.xml'] = $styles;
        }

        return self::zip([...$parts, ...$extra]);
    }

    /** @return array<string, string> */
    private static function parts(string $sheet): array
    {
        return [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="'.self::RELATIONSHIPS.'"><Relationship Id="rId1" Type="'.self::OFFICE.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::OFFICE.'"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => self::relationships('worksheets/sheet1.xml'),
            // Style 1 shows a percentage, style 2 a day.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="'.self::MAIN.'"><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="9" applyNumberFormat="1"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
    }

    private static function relationships(string $sheetTarget): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="'.self::RELATIONSHIPS.'"><Relationship Id="rId1" Type="'.self::OFFICE.'/worksheet" Target="'.htmlspecialchars($sheetTarget, \ENT_XML1 | \ENT_QUOTES).'"/><Relationship Id="rId2" Type="'.self::OFFICE.'/styles" Target="styles.xml"/></Relationships>';
    }

    /** What LibreOffice writes for a spreadsheet: a stored mimetype first, then its content. */
    private static function ods(string $doctype): string
    {
        return self::zip([
            'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?>'.$doctype.'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:spreadsheet><table:table><table:table-row><table:table-cell><text:p>number</text:p></table:table-cell></table:table-row></table:table></office:spreadsheet></office:body></office:document-content>',
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/></manifest:manifest>',
        ]);
    }

    /**
     * @param array<string, string> $parts
     * @param list<string>          $stored the parts written uncompressed, so their bytes can be found and damaged
     */
    private static function zip(array $parts, array $stored = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fuzz-zip-');
        \assert(false !== $path);
        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
                throw new \RuntimeException('The corpus could not write a zip.');
            }
            foreach ($parts as $name => $contents) {
                $zip->addFromString($name, $contents);
                if (\in_array($name, $stored, true)) {
                    $zip->setCompressionName($name, \ZipArchive::CM_STORE);
                }
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * The archive with every central-directory entry's uncompressed size halved, so the directory understates what the
     * parts inflate to.
     */
    private static function understated(string $zip): string
    {
        $offset = 0;
        while (false !== ($at = strpos($zip, "PK\x01\x02", $offset))) {
            $size = unpack('V', substr($zip, $at + 24, 4));
            \assert(\is_array($size) && \is_int($size[1]));
            $zip = substr_replace($zip, pack('V', intdiv($size[1], 2)), $at + 24, 4);
            $offset = $at + 4;
        }

        return $zip;
    }
}
