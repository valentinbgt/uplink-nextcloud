<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Tests\Unit\Service;

use OCA\Uplink\Service\FileNamer;
use OCP\Files\IFilenameValidator;
use OCP\Files\IMimeTypeDetector;
use PHPUnit\Framework\TestCase;

class FileNamerTest extends TestCase {
	private bool $supports4ByteText = true;
	private bool $sanitizerAvailable = true;
	private FileNamer $namer;

	protected function setUp(): void {
		parent::setUp();

		// Mimics the default rules of Nextcloud.
		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturnCallback(fn (string $name): bool => $this->isValid($name));
		$validator->method('sanitizeFilename')->willReturnCallback(function (string $name): string {
			if (!$this->sanitizerAvailable) {
				throw new \InvalidArgumentException('No replacement character');
			}
			if (str_ends_with(strtolower($name), '.part')) {
				$name = substr($name, 0, -5);
			}
			return str_replace(['/', '\\'], '_', $name);
		});

		$mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);
		$mimeTypeDetector->method('getAllMappings')->willReturn([
			'_comment' => ['Not an extension'],
			'bin' => ['application/octet-stream'],
			'foo' => ['application/x-foo'],
		]);

		$this->namer = new FileNamer($validator, $mimeTypeDetector);
	}

	private function isValid(string $name): bool {
		$trimmed = trim($name);
		return $trimmed !== ''
			&& $trimmed !== '.'
			&& $trimmed !== '..'
			&& strlen($name) <= 250
			&& preg_match('/[\/\\\\\x00-\x1F]/', $name) !== 1
			&& !str_ends_with(strtolower($name), '.part')
			&& strtolower($name) !== '.htaccess'
			&& ($this->supports4ByteText || preg_match('/[\x{10000}-\x{10FFFF}]/u', $name) !== 1);
	}

	public function testNameFromContentDisposition(): void {
		$cases = [
			["attachment; filename=\"resume.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9%20final.pdf", 'application/pdf', 'résumé final.pdf'],
			["attachment; filename*=utf-8'fr'na%C3%AFve.txt", 'text/plain', 'naïve.txt'],
			['attachment; filename="say \"hi\".txt"', 'text/plain', 'say "hi".txt'],
			['attachment; filename=report.pdf', 'application/pdf', 'report.pdf'],
			['attachment;filename = "spaced.txt" ', 'text/plain', 'spaced.txt'],
			['filename="no-type.txt"', 'text/plain', 'no-type.txt'],
			["attachment; filename*=X-UNKNOWN''abc.txt; filename=\"fallback.txt\"", 'text/plain', 'fallback.txt'],
			["attachment; filename*=ISO-8859-1''caf%E9.txt", 'text/plain', 'café.txt'],
			["attachment; filename=\"caf\xE9.txt\"", 'text/plain', 'café.txt'],
			['attachment; filename="=?UTF-8?B?w6l0w6kucGRm?="', 'application/pdf', 'été.pdf'],
			['attachment; filename="na%C3%AFve.txt"', 'text/plain', 'naïve.txt'],
			['attachment; filename="../../etc/passwd"', 'text/plain', 'passwd.txt'],
			['attachment; filename="C:\Users\me\doc.txt"', 'text/plain', 'doc.txt'],
			['inline; filename=photo', 'image/png', 'photo.png'],
			['attachment; filename=".htaccess"', 'text/plain', 'htaccess.txt'],
			["attachment; filename*=UTF-8''invoice%E2%80%AEtxt.exe", 'application/octet-stream', 'invoicetxt.exe'],
			['attachment; filename="x.part"', 'text/plain', 'x.txt'],
			['attachment; filename="trailing. . "', 'text/plain', 'trailing.txt'],
		];

		foreach ($cases as [$header, $contentType, $expected]) {
			$this->assertSame($expected, $this->namer->fromResponse($header, $contentType, ['https://example.com/ignored.bin']), $header);
		}
	}

	public function testNameFromUrl(): void {
		$cases = [
			['https://example.com/files/report.pdf', 'application/pdf', 'report.pdf'],
			['https://example.com/my%20file.txt?x=1#top', 'text/plain', 'my file.txt'],
			['https://example.com/caf%E9.txt', 'text/plain', 'café.txt'],
			['https://example.com/download.php?id=1', 'application/pdf', 'download.pdf'],
			['https://example.com/download.php?id=1', 'application/octet-stream', 'download.php'],
			['https://example.com/file.zip', 'text/html; charset=utf-8', 'file.zip.html'],
			['https://example.com/page.htm', 'text/html', 'page.htm'],
			['https://example.com/noext', 'image/png', 'noext.png'],
			['https://example.com/noext', 'application/octet-stream', 'noext'],
			['https://example.com/archive', 'application/x-foo', 'archive.foo'],
			['https://example.com/', 'text/html', 'download.html'],
			['https://example.com', '', 'download'],
			['https://example.com/folder/', 'application/x-unknown', 'download'],
			['https://example.com/.env', 'text/plain', 'env.txt'],
		];

		foreach ($cases as [$url, $contentType, $expected]) {
			$this->assertSame($expected, $this->namer->fromResponse('', $contentType, [$url]), $url);
		}
	}

	public function testTheFirstUrlWithANameIsUsed(): void {
		$this->assertSame('abc123.zip', $this->namer->fromResponse('', 'application/zip', [
			'https://cdn.example.com/abc123',
			'https://example.com/file.zip',
		]));
		$this->assertSame('file.zip', $this->namer->fromResponse('', 'application/zip', [
			'https://cdn.example.com/',
			'https://example.com/file.zip',
		]));
	}

	public function testLongNamesAreShortenedKeepingTheExtension(): void {
		$name = $this->namer->fromResponse('attachment; filename="' . str_repeat('a', 300) . '.txt"', 'text/plain', []);
		$this->assertSame(str_repeat('a', 226) . '.txt', $name);

		$name = $this->namer->fromResponse('attachment; filename="' . str_repeat('é', 200) . '.txt"', 'text/plain', []);
		$this->assertLessThanOrEqual(230, strlen($name));
		$this->assertTrue(mb_check_encoding($name, 'UTF-8'));
		$this->assertStringEndsWith('é.txt', $name);
	}

	public function testFourByteCharactersAreRemovedWhenTheDatabaseCannotStoreThem(): void {
		$header = "attachment; filename*=UTF-8''%F0%9F%98%80%20smile.txt";
		$this->assertSame('😀 smile.txt', $this->namer->fromResponse($header, 'text/plain', []));

		$this->supports4ByteText = false;
		$this->assertSame('smile.txt', $this->namer->fromResponse($header, 'text/plain', []));
		$this->assertSame('download.txt', $this->namer->fromResponse("attachment; filename*=UTF-8''%F0%9F%98%80.txt", 'text/plain', []));
	}

	public function testUnusableNamesFallBackToAGenericName(): void {
		$this->sanitizerAvailable = false;
		$this->assertSame('download.txt', $this->namer->fromResponse('attachment; filename="bad.part"', 'text/plain', []));
		$this->assertSame('download.pdf', $this->namer->fromResponse('attachment; filename="..."', 'application/pdf', []));
	}

	public function testAChosenNameGetsTheDetectedExtension(): void {
		$this->assertSame('rapport.pdf', $this->namer->withExtensionOf('rapport', 'download.pdf'));
		$this->assertSame('mon rapport 2026.pdf', $this->namer->withExtensionOf('mon rapport 2026', 'report.pdf'));
		$this->assertSame('rapport.odt', $this->namer->withExtensionOf('rapport.odt', 'report.pdf'));
		$this->assertSame('rapport', $this->namer->withExtensionOf('rapport', 'download'));
		$this->assertSame(str_repeat('a', 248), $this->namer->withExtensionOf(str_repeat('a', 248), 'x.pdf'));
	}

	public function testSanitize(): void {
		$this->assertSame('passwd', $this->namer->sanitize('../../etc/passwd'));
		$this->assertSame('doc.txt', $this->namer->sanitize('C:\\Users\\me\\doc.txt'));
		$this->assertSame('ab.txt', $this->namer->sanitize("a\x00\x1F\x7Fb.txt"));
		$this->assertSame('hidden', $this->namer->sanitize('...hidden'));
		$this->assertSame('', $this->namer->sanitize(' . '));
		$this->assertSame('', $this->namer->sanitize('folder/'));
		$this->assertSame('café', $this->namer->sanitize("caf\xE9"));
	}

	public function testParseContentDisposition(): void {
		$this->assertSame('', FileNamer::parseContentDisposition(''));
		$this->assertSame('', FileNamer::parseContentDisposition('inline'));
		$this->assertSame('', FileNamer::parseContentDisposition('attachment; filename=""'));
		$this->assertSame('a.txt', FileNamer::parseContentDisposition('attachment; FILENAME="a.txt"'));
		$this->assertSame('first.txt', FileNamer::parseContentDisposition('attachment; filename=first.txt; filename=second.txt'));
		$this->assertSame('semi;colon.txt', FileNamer::parseContentDisposition('attachment; filename="semi;colon.txt"; size=3'));
		$this->assertSame('unterminated.txt', FileNamer::parseContentDisposition('attachment; filename="unterminated.txt'));
	}

	public function testNameFromUrlHelper(): void {
		$this->assertSame('file name.pdf', FileNamer::nameFromUrl('https://example.com/dir/file%20name.pdf?query=1'));
		$this->assertSame('', FileNamer::nameFromUrl('https://example.com/dir/'));
		$this->assertSame('', FileNamer::nameFromUrl('https://example.com'));
		$this->assertSame('a+b.txt', FileNamer::nameFromUrl('https://example.com/a+b.txt'));
	}

	public function testGetExtension(): void {
		$this->assertSame('pdf', FileNamer::getExtension('report.pdf'));
		$this->assertSame('gz', FileNamer::getExtension('archive.tar.gz'));
		$this->assertSame('', FileNamer::getExtension('README'));
		$this->assertSame('', FileNamer::getExtension('.bashrc'));
		$this->assertSame('', FileNamer::getExtension('name.with space'));
		$this->assertSame('', FileNamer::getExtension('name.verylongextension'));
	}

	public function testExtensionForMimeType(): void {
		$this->assertSame('jpg', $this->namer->extensionForMimeType('image/jpeg'));
		$this->assertSame('foo', $this->namer->extensionForMimeType('application/x-foo'));
		$this->assertSame('', $this->namer->extensionForMimeType('application/octet-stream'));
		$this->assertSame('', $this->namer->extensionForMimeType('application/x-unknown'));
		$this->assertSame('', $this->namer->extensionForMimeType(''));
	}
}
