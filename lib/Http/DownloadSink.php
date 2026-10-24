<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Http;

use Psr\Http\Message\StreamInterface;

/**
 * Write-only stream used as the "sink" of the HTTP client.
 *
 * The HTTP client writes the body of every response of a redirect chain to the
 * sink, so {@see self::beginResponse()} has to be called from the "on_headers"
 * hook: only the body of a successful response is kept.
 *
 * Returning less than the received length from {@see self::write()} makes curl
 * abort the transfer. This is how the size limit is enforced while downloading.
 */
final class DownloadSink implements StreamInterface {
	/** @var resource|null */
	private $handle;
	private bool $keepBody = false;
	private int $bytesWritten = 0;
	private bool $limitReached = false;
	private bool $writeFailed = false;

	/**
	 * @param string $path File receiving the body, truncated when a response begins
	 * @param int $maxBytes Maximum size of the body, 0 for no limit
	 * @throws \RuntimeException if the file cannot be opened
	 */
	public function __construct(
		string $path,
		private int $maxBytes = 0,
	) {
		$handle = @fopen($path, 'wb');
		if ($handle === false) {
			throw new \RuntimeException('Unable to open the temporary file for writing');
		}
		$this->handle = $handle;
	}

	/**
	 * Called when the headers of a response have been received, before its body.
	 *
	 * @param bool $keepBody Whether the body of this response is the file to keep
	 */
	public function beginResponse(bool $keepBody): void {
		$this->keepBody = $keepBody;
		if ($keepBody && $this->handle !== null) {
			ftruncate($this->handle, 0);
			rewind($this->handle);
			$this->bytesWritten = 0;
		}
	}

	public function getBytesWritten(): int {
		return $this->bytesWritten;
	}

	public function isLimitReached(): bool {
		return $this->limitReached;
	}

	public function hasWriteFailed(): bool {
		return $this->writeFailed;
	}

	public function write(string $string): int {
		$length = strlen($string);
		if (!$this->keepBody) {
			return $length;
		}
		if ($this->handle === null) {
			$this->writeFailed = true;
			return 0;
		}
		if ($this->maxBytes > 0 && $this->bytesWritten + $length > $this->maxBytes) {
			$this->limitReached = true;
			return 0;
		}

		$written = fwrite($this->handle, $string);
		if ($written !== $length) {
			// Regular files are only written partially when the disk is full.
			$this->writeFailed = true;
			return 0;
		}
		$this->bytesWritten += $written;
		return $written;
	}

	public function close(): void {
		if ($this->handle !== null) {
			fclose($this->handle);
			$this->handle = null;
		}
	}

	public function detach() {
		$handle = $this->handle;
		$this->handle = null;
		return $handle;
	}

	public function getSize(): ?int {
		return null;
	}

	public function tell(): int {
		return $this->bytesWritten;
	}

	public function eof(): bool {
		return true;
	}

	public function isSeekable(): bool {
		return false;
	}

	public function seek(int $offset, int $whence = SEEK_SET): void {
		throw new \RuntimeException('The download sink is not seekable');
	}

	public function rewind(): void {
		throw new \RuntimeException('The download sink is not seekable');
	}

	public function isWritable(): bool {
		return $this->handle !== null;
	}

	public function isReadable(): bool {
		return false;
	}

	public function read(int $length): string {
		throw new \RuntimeException('The download sink is not readable');
	}

	public function getContents(): string {
		throw new \RuntimeException('The download sink is not readable');
	}

	public function getMetadata(?string $key = null) {
		return $key === null ? [] : null;
	}

	public function __toString(): string {
		return '';
	}
}
