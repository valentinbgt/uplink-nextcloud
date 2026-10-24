<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Service;

use OCA\Uplink\AppInfo\Application;
use OCA\Uplink\Exception\DownloadException;
use OCA\Uplink\Http\DownloadSink;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\FileNameTooLongException;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\IFilenameValidator;
use OCP\Files\InvalidContentException;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\StorageNotAvailableException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\LocalServerException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IPreview;
use OCP\ITempManager;
use OCP\Util;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Downloads a file from a URL into a folder of a user.
 *
 * The file is first downloaded to a temporary file, so the folder never
 * contains a partial file, and is then written to the folder at once.
 */
class DownloadService {
	public const CONFIG_MAX_SIZE = 'max_size';
	public const CONFIG_TIMEOUT = 'timeout';

	private const CONNECT_TIMEOUT = 30;
	/** Transfers receiving no data for this many seconds are aborted. */
	private const STALL_TIMEOUT = 300;
	/** Minimum delay in seconds between two progress reports. */
	private const PROGRESS_INTERVAL = 1.0;
	private const MAX_URL_LENGTH = 8192;
	private const USER_AGENT = 'Mozilla/5.0 (compatible; Uplink for Nextcloud; +https://github.com/valentinbgt/uplink-nextcloud)';

	public function __construct(
		private IRootFolder $rootFolder,
		private IClientService $clientService,
		private ITempManager $tempManager,
		private IAppConfig $appConfig,
		private IFilenameValidator $filenameValidator,
		private IPreview $previewManager,
		private FileNamer $fileNamer,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Validates a download request before anything is downloaded.
	 *
	 * @param string $path The destination folder, relative to the files of the user
	 * @param string $name The file name chosen by the user, empty to detect it
	 * @throws DownloadException
	 */
	public function prepare(string $userId, string $url, string $path, string $name): PendingDownload {
		$url = trim($url);
		$this->validateUrl($url);
		$folder = $this->getFolder($userId, $path);

		$name = trim($name);
		if ($name !== '') {
			$this->validateName($name);
		}

		return new PendingDownload($url, $folder, $name);
	}

	/**
	 * Downloads the file and saves it in the destination folder.
	 *
	 * @param \Closure(int, ?int): void $onProgress Receives the downloaded size and the total size when known
	 * @param \Closure(): void $onSaving Called when the download is complete, before the file is saved
	 * @throws DownloadException
	 */
	public function run(PendingDownload $download, \Closure $onProgress, \Closure $onSaving): File {
		$tempFile = $this->tempManager->getTemporaryFile();
		if ($tempFile === false) {
			throw new DownloadException($this->l10n->t('The server could not create a temporary file.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$response = $this->download($download, $tempFile, $onProgress);
			$onSaving();
			return $this->save($download, $tempFile, $response);
		} finally {
			@unlink($tempFile);
		}
	}

	/**
	 * Describes a saved file for the Files app.
	 *
	 * @return array{id: string, name: string, size: int|float, mtime: int, mimetype: string, permissions: int, hasPreview: bool}
	 */
	public function describe(File $file): array {
		try {
			$hasPreview = $this->previewManager->isAvailable($file);
		} catch (\Exception) {
			// The file is saved: a missing preview must not be reported as a failure.
			$hasPreview = false;
		}

		return [
			// A string, as file ids can exceed the integer precision of JavaScript.
			'id' => (string)$file->getId(),
			'name' => $file->getName(),
			'size' => $file->getSize(),
			'mtime' => $file->getMTime(),
			'mimetype' => $file->getMimetype(),
			'permissions' => $file->getPermissions(),
			'hasPreview' => $hasPreview,
		];
	}

	private function validateUrl(string $url): void {
		if ($url === '') {
			throw new DownloadException($this->l10n->t('Please enter a link.'));
		}

		$parts = parse_url($url);
		if (!is_array($parts)
			|| strlen($url) > self::MAX_URL_LENGTH
			|| preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
			throw $this->getInvalidUrlError();
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		if ($scheme !== 'http' && $scheme !== 'https') {
			if (in_array($scheme, ['', 'javascript', 'data', 'file'], true)) {
				throw $this->getInvalidUrlError();
			}
			throw new DownloadException($this->l10n->t('Only links starting with http:// or https:// are supported.'));
		}

		// A domain name (possibly internationalized), an IPv4 address or a bracketed IPv6 address.
		$host = $parts['host'] ?? '';
		if (preg_match('/^(?:[\p{L}\p{N}_-]+(?:\.[\p{L}\p{N}_-]+)*\.?|\[[0-9A-Fa-f:.]+\])$/u', $host) !== 1) {
			throw $this->getInvalidUrlError();
		}
	}

	private function getInvalidUrlError(?\Throwable $previous = null): DownloadException {
		return new DownloadException($this->l10n->t('This link is not valid.'), Http::STATUS_BAD_REQUEST, $previous);
	}

	private function getFolder(string $userId, string $path): Folder {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$node = trim($path, '/') === '' ? $userFolder : $userFolder->get($path);
		} catch (NotFoundException|NotPermittedException|InvalidPathException) {
			$node = null;
		}

		if (!$node instanceof Folder) {
			throw new DownloadException($this->l10n->t('The destination folder does not exist.'), Http::STATUS_NOT_FOUND);
		}
		if (!$node->isCreatable()) {
			throw new DownloadException($this->l10n->t('You are not allowed to add files to this folder.'), Http::STATUS_FORBIDDEN);
		}
		return $node;
	}

	private function validateName(string $name): void {
		try {
			$this->filenameValidator->validateFilename($name);
		} catch (FileNameTooLongException $e) {
			throw new DownloadException($this->l10n->t('The file name is too long.'), Http::STATUS_BAD_REQUEST, $e);
		} catch (InvalidPathException $e) {
			$message = $e->getMessage();
			throw new DownloadException(
				$message !== '' ? $message : $this->l10n->t('"%s" is not a valid file name.', [$name]),
				Http::STATUS_BAD_REQUEST,
				$e,
			);
		}
	}

	/**
	 * Downloads the file to the temporary file.
	 *
	 * @return array{contentDisposition: string, contentType: string, url: string} Details of the final response
	 * @throws DownloadException
	 */
	private function download(PendingDownload $download, string $tempFile, \Closure $onProgress): array {
		[$limit, $limitIsFreeSpace] = $this->getSizeLimit($download->folder);

		try {
			$sink = new DownloadSink($tempFile, $limit);
		} catch (\RuntimeException $e) {
			$this->logger->error('Could not open the temporary file of a download', ['exception' => $e]);
			throw new DownloadException($this->l10n->t('The server could not create a temporary file.'), Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}

		$total = null;
		$tooLarge = false;
		$effectiveUrl = $download->url;
		$lastProgress = microtime(true);

		$options = [
			'sink' => $sink,
			'http_errors' => false,
			// The file is saved exactly as it is served.
			'decode_content' => false,
			'timeout' => $this->getTimeout(),
			'connect_timeout' => self::CONNECT_TIMEOUT,
			'version' => '1.1',
			'headers' => [
				'User-Agent' => self::USER_AGENT,
				'Accept' => '*/*',
				'Accept-Encoding' => 'identity',
			],
			'curl' => [
				\CURLOPT_LOW_SPEED_LIMIT => 1,
				\CURLOPT_LOW_SPEED_TIME => self::STALL_TIMEOUT,
			],
			'on_headers' => function (ResponseInterface $response) use ($sink, $limit, &$total, &$tooLarge): void {
				$status = $response->getStatusCode();
				$isFile = $status >= 200 && $status < 300;
				$sink->beginResponse($isFile);
				if (!$isFile) {
					return;
				}

				$length = $response->getHeaderLine('Content-Length');
				$total = ctype_digit($length) ? (int)$length : null;
				if ($total !== null && $limit > 0 && $total > $limit) {
					$tooLarge = true;
					throw new \RuntimeException('The announced size exceeds the limit');
				}
			},
			'on_stats' => function (object $stats) use (&$effectiveUrl): void {
				if (method_exists($stats, 'getEffectiveUri')) {
					$effectiveUrl = (string)$stats->getEffectiveUri();
				}
			},
			'progress' => function () use ($sink, $onProgress, &$total, &$lastProgress): void {
				$now = microtime(true);
				if ($now - $lastProgress >= self::PROGRESS_INTERVAL) {
					$lastProgress = $now;
					$onProgress($sink->getBytesWritten(), $total);
				}
			},
		];

		try {
			$response = $this->clientService->newClient()->get($download->url, $options);
		} catch (LocalServerException $e) {
			// Also raised when the host name cannot be resolved while local addresses are blocked.
			if (str_contains($e->getMessage(), 'No DNS record')) {
				throw new DownloadException($this->l10n->t('The server of this link could not be found.'), Http::STATUS_BAD_GATEWAY, $e);
			}
			throw new DownloadException($this->l10n->t('Links to local or private network addresses are not allowed on this server.'), Http::STATUS_BAD_REQUEST, $e);
		} catch (\InvalidArgumentException $e) {
			// Raised by the HTTP client for URLs it cannot parse.
			throw $this->getInvalidUrlError($e);
		} catch (\Exception $e) {
			throw $this->getTransferError($e, $download, $sink, $tooLarge, $limit, $limitIsFreeSpace);
		} finally {
			$sink->close();
		}

		$status = $response->getStatusCode();
		if ($status < 200 || $status >= 300) {
			throw new DownloadException(match ($status) {
				401, 403 => $this->l10n->t('The server of this link refused access to the file (error %d).', [$status]),
				404, 410 => $this->l10n->t('The file was not found on the server of this link (error %d).', [$status]),
				default => $this->l10n->t('The server of this link returned an error (%d).', [$status]),
			}, Http::STATUS_BAD_GATEWAY);
		}

		$onProgress($sink->getBytesWritten(), $total);

		return [
			'contentDisposition' => $response->getHeader('Content-Disposition'),
			'contentType' => $response->getHeader('Content-Type'),
			'url' => $effectiveUrl,
		];
	}

	/**
	 * Writes the downloaded file to the destination folder.
	 *
	 * @param array{contentDisposition: string, contentType: string, url: string} $response
	 * @throws DownloadException
	 */
	private function save(PendingDownload $download, string $tempFile, array $response): File {
		clearstatcache(true, $tempFile);
		$size = filesize($tempFile);
		if ($size === false) {
			throw new DownloadException($this->l10n->t('The server could not read the downloaded file.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$folder = $download->folder;
		$freeSpace = self::getFreeSpace($folder);
		if ($freeSpace >= 0 && $size > $freeSpace) {
			throw $this->getNoSpaceError();
		}

		$urls = array_values(array_unique([$response['url'], $download->url]));
		$detectedName = $this->fileNamer->fromResponse($response['contentDisposition'], $response['contentType'], $urls);
		$name = $download->name === ''
			? $detectedName
			: $this->fileNamer->withExtensionOf($download->name, $detectedName);
		$name = $folder->getNonExistingName($name);

		$source = fopen($tempFile, 'rb');
		if ($source === false) {
			throw new DownloadException($this->l10n->t('The server could not read the downloaded file.'), Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$file = $folder->newFile($name, $source);
		} catch (InvalidPathException $e) {
			throw new DownloadException($this->l10n->t('The file could not be saved because the name "%s" is not allowed.', [$name]), Http::STATUS_BAD_REQUEST, $e);
		} catch (NotPermittedException|ForbiddenException $e) {
			// ForbiddenException is raised by access control rules.
			throw new DownloadException($this->l10n->t('The file could not be saved in this folder.'), Http::STATUS_FORBIDDEN, $e);
		} catch (InvalidContentException $e) {
			// Raised by content checks such as an antivirus, with a message for the user.
			$message = $e->getMessage();
			throw new DownloadException($message !== '' ? $message : $this->l10n->t('The content of this file was refused by the server.'), Http::STATUS_FORBIDDEN, $e);
		} catch (StorageNotAvailableException $e) {
			throw new DownloadException($this->l10n->t('The storage of this folder is not available.'), Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		} finally {
			if (is_resource($source)) {
				fclose($source);
			}
		}

		// A full storage or quota can truncate the file without reporting an error.
		if ($file->getSize() != $size) {
			$this->logger->warning('The downloaded file {name} was saved incompletely ({saved} of {size} bytes) and was removed', [
				'name' => $name,
				'saved' => $file->getSize(),
				'size' => $size,
			]);
			try {
				$file->delete();
			} catch (\Exception $e) {
				$this->logger->error('Could not remove the incomplete file {name}', ['name' => $name, 'exception' => $e]);
			}
			throw $this->getNoSpaceError();
		}

		return $file;
	}

	/**
	 * Returns the maximum size of the file: the configured maximum, or the free space if it is lower.
	 *
	 * @return array{int, bool} The limit in bytes (0 for no limit), and whether it is the free space
	 * @throws DownloadException if there is no free space at all
	 */
	private function getSizeLimit(Folder $folder): array {
		$maxSize = $this->getMaxSize();
		$freeSpace = self::getFreeSpace($folder);
		if ($freeSpace >= 0 && $freeSpace < 1) {
			throw $this->getNoSpaceError();
		}
		if ($freeSpace >= 1 && ($maxSize === 0 || $freeSpace < $maxSize)) {
			return [(int)$freeSpace, true];
		}
		return [$maxSize, false];
	}

	/**
	 * Returns the free space of a folder in bytes, or a negative value if it is unknown or unlimited.
	 */
	private static function getFreeSpace(Folder $folder): int|float {
		$freeSpace = $folder->getFreeSpace();
		return is_int($freeSpace) || is_float($freeSpace) ? $freeSpace : FileInfo::SPACE_UNKNOWN;
	}

	private function getMaxSize(): int {
		$value = trim($this->getSetting(self::CONFIG_MAX_SIZE));
		if ($value === '' || $value === '0') {
			return 0;
		}

		$bytes = Util::computerFileSize($value);
		if ($bytes === false || $bytes < 1) {
			$this->logger->warning('Ignoring the invalid "{key}" setting "{value}"', ['key' => self::CONFIG_MAX_SIZE, 'value' => $value]);
			return 0;
		}
		return (int)$bytes;
	}

	/**
	 * Returns the maximum duration of a download in seconds, 0 for no limit.
	 */
	private function getTimeout(): int {
		$value = trim($this->getSetting(self::CONFIG_TIMEOUT));
		return ctype_digit($value) ? (int)$value : 0;
	}

	private function getSetting(string $key): string {
		try {
			return $this->appConfig->getValueString(Application::APP_ID, $key);
		} catch (\Exception) {
			// The value was stored with another type.
			try {
				return (string)$this->appConfig->getValueInt(Application::APP_ID, $key);
			} catch (\Exception) {
				return '';
			}
		}
	}

	private function getNoSpaceError(): DownloadException {
		return new DownloadException($this->l10n->t('There is not enough free space to save this file.'), Http::STATUS_INSUFFICIENT_STORAGE);
	}

	/**
	 * Converts an error of the HTTP client to a message for the user.
	 */
	private function getTransferError(\Exception $e, PendingDownload $download, DownloadSink $sink, bool $tooLarge, int $limit, bool $limitIsFreeSpace): DownloadException {
		if ($tooLarge || $sink->isLimitReached()) {
			if ($limitIsFreeSpace) {
				return $this->getNoSpaceError();
			}
			return new DownloadException(
				$this->l10n->t('This file is larger than the maximum size allowed (%s).', [Util::humanFileSize($limit)]),
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
				$e,
			);
		}

		if ($sink->hasWriteFailed()) {
			$this->logger->error('Could not write a download to the temporary directory, the disk may be full', ['exception' => $e]);
			return new DownloadException($this->l10n->t('The server could not store the file temporarily.'), Http::STATUS_INTERNAL_SERVER_ERROR, $e);
		}

		$errno = self::getCurlErrorNumber($e);
		// The exception message is not logged as it contains the full URL, which can hold secrets.
		$this->logger->info('Download from {url} failed with {class} (cURL error {errno})', [
			'url' => self::redactUrl($download->url),
			'class' => get_class($e),
			'errno' => $errno ?? 'none',
		]);

		$message = match (true) {
			$errno === 3 => $this->l10n->t('This link is not valid.'),
			$errno === 6 => $this->l10n->t('The server of this link could not be found.'),
			$errno === 7 => $this->l10n->t('Could not connect to the server of this link.'),
			$errno === 28 => $this->l10n->t('The server of this link did not respond in time.'),
			in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true)
				=> $this->l10n->t('A secure connection to the server of this link could not be established.'),
			in_array($errno, [18, 52, 55, 56, 92], true) => $this->l10n->t('The connection to the server of this link was interrupted.'),
			is_a($e, 'GuzzleHttp\Exception\TooManyRedirectsException') => $this->l10n->t('This link redirects too many times.'),
			default => $this->l10n->t('The file could not be downloaded.'),
		};
		return new DownloadException($message, Http::STATUS_BAD_GATEWAY, $e);
	}

	private static function getCurlErrorNumber(\Throwable $e): ?int {
		for ($current = $e; $current !== null; $current = $current->getPrevious()) {
			if (method_exists($current, 'getHandlerContext')) {
				$errno = $current->getHandlerContext()['errno'] ?? null;
				if (is_int($errno) && $errno !== 0) {
					return $errno;
				}
			}
		}
		return null;
	}

	/**
	 * Removes the credentials and query of a URL, so it can be logged.
	 */
	private static function redactUrl(string $url): string {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return '';
		}
		return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '')
			. (isset($parts['port']) ? ':' . $parts['port'] : '')
			. ($parts['path'] ?? '');
	}
}
