<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Tests\Unit\Service;

use OCA\Uplink\Exception\DownloadException;
use OCA\Uplink\Service\DownloadService;
use OCA\Uplink\Service\FileNamer;
use OCA\Uplink\Service\PendingDownload;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IFilenameValidator;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\InvalidContentException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IPreview;
use OCP\ITempManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the download flow with a fake HTTP client behaving like the one of Nextcloud.
 */
class DownloadServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $settings = [];
	/** @var callable(string, array): IResponse */
	private $transfer;
	/** @var list<string> */
	private array $tempFiles = [];
	private Folder&MockObject $userFolder;
	private Folder&MockObject $folder;
	private DownloadService $service;

	/** @var array<string, string> Contents written to the folder, by name */
	private array $saved = [];
	private int|float $freeSpace = FileInfo::SPACE_UNLIMITED;
	private ?\Exception $saveError = null;
	private ?int $savedSizeOverride = null;
	private bool $deleted = false;

	protected function setUp(): void {
		parent::setUp();

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key): string => $this->settings[$key] ?? '');

		$tempManager = $this->createMock(ITempManager::class);
		$tempManager->method('getTemporaryFile')->willReturnCallback(function (): string {
			$file = tempnam(sys_get_temp_dir(), 'uplink-test');
			$this->tempFiles[] = $file;
			return $file;
		});

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(fn (string $url, array $options): IResponse => ($this->transfer)($url, $options));
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$validator = $this->createMock(IFilenameValidator::class);
		$validator->method('isFilenameValid')->willReturnCallback(fn (string $name): bool => trim($name) !== '' && !str_contains($name, '/'));
		$validator->method('sanitizeFilename')->willReturnArgument(0);
		$validator->method('validateFilename')->willReturnCallback(function (string $name): void {
			if (str_contains($name, '/')) {
				throw new InvalidPathException('"/" is not allowed inside a file or folder name.');
			}
		});

		$this->folder = $this->createFolder(true);
		$this->userFolder = $this->createFolder(true);
		$this->userFolder->method('get')->willReturnCallback(function (string $path) {
			return match ($path) {
				'/Documents' => $this->folder,
				'/ReadOnly' => $this->createFolder(false),
				'/file.txt' => $this->createMock(File::class),
				default => throw new NotFoundException(),
			};
		});
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->userFolder);

		$this->service = new DownloadService(
			$rootFolder,
			$clientService,
			$tempManager,
			$appConfig,
			$validator,
			$this->createMock(IPreview::class),
			new FileNamer($validator, $this->createMock(IMimeTypeDetector::class)),
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}

	protected function tearDown(): void {
		foreach ($this->tempFiles as $file) {
			$this->assertFileDoesNotExist($file, 'The temporary file must be removed');
		}
		parent::tearDown();
	}

	private function createFolder(bool $creatable): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('isCreatable')->willReturn($creatable);
		$folder->method('getFreeSpace')->willReturnCallback(fn () => $this->freeSpace);
		$folder->method('getNonExistingName')->willReturnCallback(fn (string $name): string => isset($this->saved[$name]) ? 'copy of ' . $name : $name);
		$folder->method('newFile')->willReturnCallback(function (string $name, $content): File {
			if ($this->saveError !== null) {
				throw $this->saveError;
			}
			$this->saved[$name] = stream_get_contents($content);
			fclose($content);
			$file = $this->createMock(File::class);
			$file->method('getName')->willReturn($name);
			$file->method('getSize')->willReturn($this->savedSizeOverride ?? strlen($this->saved[$name]));
			$file->method('delete')->willReturnCallback(function (): void {
				$this->deleted = true;
			});
			return $file;
		});
		return $folder;
	}

	/**
	 * Simulates a transfer: redirects first, then the final response.
	 *
	 * @param list<array{int, array<string, string>, string}> $responses Status, headers and body of each response
	 */
	private function serve(array $responses): void {
		$this->transfer = function (string $url, array $options) use ($responses): IResponse {
			foreach ($responses as [$status, $headers, $body]) {
				$psrResponse = $this->createMock(ResponseInterface::class);
				$psrResponse->method('getStatusCode')->willReturn($status);
				$psrResponse->method('getHeaderLine')->willReturnCallback(fn (string $name): string => $headers[$name] ?? '');
				try {
					$options['on_headers']($psrResponse);
				} catch (\Throwable $e) {
					// The HTTP client aborts the transfer and wraps the exception.
					throw new \RuntimeException('An error was encountered during the on_headers event', 0, $e);
				}
				foreach (str_split($body === '' ? '' : $body, 4) as $chunk) {
					if ($chunk !== '' && $options['sink']->write($chunk) !== strlen($chunk)) {
						throw new \RuntimeException('cURL error 23: Failure writing output to destination');
					}
					($options['progress'])(0, 0, 0, 0);
				}
			}

			$response = $this->createMock(IResponse::class);
			$response->method('getStatusCode')->willReturn($status);
			$response->method('getHeader')->willReturnCallback(fn (string $name): string => $headers[$name] ?? '');
			return $response;
		};
	}

	private function download(string $url, string $name = ''): string {
		$pending = $this->service->prepare('alice', $url, '/Documents', $name);
		$file = $this->service->run($pending, static function (): void {
		}, static function (): void {
		});
		return $file->getName();
	}

	private function assertDownloadFails(string $url, string $message): void {
		try {
			$this->download($url);
			$this->fail('The download should fail');
		} catch (DownloadException $e) {
			$this->assertStringContainsString($message, $e->getMessage());
		}
	}

	public function testPrepareValidatesTheRequest(): void {
		$cases = [
			['', '/Documents', '', 'Please enter a link.'],
			['ftp://example.com/file', '/Documents', '', 'Only links starting with http://'],
			['javascript:alert(1)', '/Documents', '', 'This link is not valid.'],
			['https://exa mple.com/file', '/Documents', '', 'This link is not valid.'],
			["https://example.com/fi\nle", '/Documents', '', 'This link is not valid.'],
			['https://example.com/file', '/Missing', '', 'The destination folder does not exist.'],
			['https://example.com/file', '/file.txt', '', 'The destination folder does not exist.'],
			['https://example.com/file', '/ReadOnly', '', 'You are not allowed to add files to this folder.'],
			['https://example.com/file', '/Documents', 'a/b', '"/" is not allowed'],
		];
		foreach ($cases as [$url, $path, $name, $message]) {
			try {
				$this->service->prepare('alice', $url, $path, $name);
				$this->fail("$url should be refused");
			} catch (DownloadException $e) {
				$this->assertStringContainsString($message, $e->getMessage(), $url);
			}
		}

		$pending = $this->service->prepare('alice', '  https://example.com/a.pdf  ', '/', ' name.pdf ');
		$this->assertInstanceOf(PendingDownload::class, $pending);
		$this->assertSame('https://example.com/a.pdf', $pending->url);
		$this->assertSame($this->userFolder, $pending->folder);
		$this->assertSame('name.pdf', $pending->name);
	}

	public function testOnlyTheFinalBodyIsSaved(): void {
		$this->serve([
			[302, ['Location' => 'https://cdn.example.com/x'], '<html>Moved</html>'],
			[200, ['Content-Type' => 'application/pdf', 'Content-Length' => '12'], '%PDF-content'],
		]);

		$this->assertSame('report.pdf', $this->download('https://example.com/report.pdf'));
		$this->assertSame(['report.pdf' => '%PDF-content'], $this->saved);
	}

	public function testNamesAreDetectedAndConflictsAvoided(): void {
		$this->serve([[200, ['Content-Disposition' => "attachment; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf"], 'abc']]);
		$this->assertSame('résumé.pdf', $this->download('https://example.com/download?id=1'));
		$this->assertSame('copy of résumé.pdf', $this->download('https://example.com/download?id=1'));
		$this->assertSame('chosen.txt', $this->download('https://example.com/download?id=1', 'chosen.txt'));
		$this->assertSame('chosen.pdf', $this->download('https://example.com/download?id=1', 'chosen'));
	}

	public function testRemoteErrorsAreReported(): void {
		$this->serve([[404, ['Content-Type' => 'text/html'], 'Not found']]);
		$this->assertDownloadFails('https://example.com/missing', 'The file was not found on the server of this link (error 404).');
		$this->serve([[403, [], '']]);
		$this->assertDownloadFails('https://example.com/private', 'refused access to the file (error 403)');
		$this->serve([[500, [], '']]);
		$this->assertDownloadFails('https://example.com/broken', 'returned an error (500)');
		$this->assertSame([], $this->saved);
	}

	public function testTheConfiguredMaximumSizeIsEnforced(): void {
		$this->settings['max_size'] = '10';

		$this->serve([[200, ['Content-Length' => '11'], '12345678901']]);
		$this->assertDownloadFails('https://example.com/big', 'larger than the maximum size allowed (10 B)');

		$this->serve([[200, [], '12345678901']]);
		$this->assertDownloadFails('https://example.com/big-without-length', 'larger than the maximum size allowed');

		$this->serve([[200, [], '1234567890']]);
		$this->assertSame('exact', $this->download('https://example.com/exact'));
		$this->assertSame(['exact' => '1234567890'], $this->saved);
	}

	public function testTheFreeSpaceIsEnforced(): void {
		$this->freeSpace = 5;
		$this->serve([[200, ['Content-Length' => '6'], '123456']]);
		$this->assertDownloadFails('https://example.com/a', 'There is not enough free space to save this file.');

		$this->serve([[200, [], '123456']]);
		$this->assertDownloadFails('https://example.com/b', 'There is not enough free space to save this file.');

		$this->freeSpace = 0;
		$this->assertDownloadFails('https://example.com/c', 'There is not enough free space to save this file.');
		$this->assertSame([], $this->saved);
	}

	public function testTruncatedFilesAreRemoved(): void {
		$this->savedSizeOverride = 2;
		$this->serve([[200, [], '12345']]);
		$this->assertDownloadFails('https://example.com/a.bin', 'There is not enough free space to save this file.');
		$this->assertTrue($this->deleted);
	}

	public function testStorageErrorsAreReported(): void {
		$this->serve([[200, [], 'EICAR']]);

		$this->saveError = new InvalidContentException('Virus Eicar-Test-Signature is detected in the file.');
		$this->assertDownloadFails('https://example.com/virus.txt', 'Virus Eicar-Test-Signature is detected');

		$this->saveError = new ForbiddenException('Access denied', false);
		$this->assertDownloadFails('https://example.com/blocked.txt', 'The file could not be saved in this folder.');

		$this->saveError = new InvalidPathException('Invalid path');
		$this->assertDownloadFails('https://example.com/name.txt', 'The file could not be saved because the name "name.txt" is not allowed.');
	}

	public function testLocalAddressesAreReported(): void {
		$this->transfer = static function (): IResponse {
			throw new LocalServerException('Host "127.0.0.1" violates local access rules');
		};
		$this->assertDownloadFails('http://127.0.0.1/a', 'Links to local or private network addresses are not allowed on this server.');

		$this->transfer = static function (): IResponse {
			throw new LocalServerException('No DNS record found for does-not-exist.example');
		};
		$this->assertDownloadFails('https://does-not-exist.example/a', 'The server of this link could not be found.');
	}
}
