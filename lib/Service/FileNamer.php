<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Service;

use OCP\Files\IFilenameValidator;
use OCP\Files\IMimeTypeDetector;

/**
 * Chooses the name of a downloaded file, the way a web browser does.
 */
class FileNamer {
	public const FALLBACK_NAME = 'download';

	/** Nextcloud allows 250 bytes, this leaves room for the " (2)" added on conflicts. */
	private const MAX_LENGTH = 230;

	/** Server-side script extensions, which never describe the downloaded content. */
	private const SCRIPT_EXTENSIONS = [
		'action', 'ashx', 'asp', 'aspx', 'cfm', 'cgi', 'do', 'jsp', 'jspx',
		'php', 'php3', 'php4', 'php5', 'phtml', 'pl', 'py',
	];

	private const HTML_EXTENSIONS = ['htm', 'html', 'shtml', 'xhtml'];

	/** Preferred extension of common types, which often have several extensions. */
	private const EXTENSIONS = [
		'application/epub+zip' => 'epub',
		'application/gzip' => 'gz',
		'application/json' => 'json',
		'application/msword' => 'doc',
		'application/ogg' => 'ogg',
		'application/pdf' => 'pdf',
		'application/rtf' => 'rtf',
		'application/vnd.ms-excel' => 'xls',
		'application/vnd.ms-powerpoint' => 'ppt',
		'application/vnd.oasis.opendocument.presentation' => 'odp',
		'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
		'application/vnd.oasis.opendocument.text' => 'odt',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.rar' => 'rar',
		'application/x-7z-compressed' => '7z',
		'application/x-bzip2' => 'bz2',
		'application/x-gzip' => 'gz',
		'application/x-rar-compressed' => 'rar',
		'application/x-tar' => 'tar',
		'application/x-xz' => 'xz',
		'application/xml' => 'xml',
		'application/zip' => 'zip',
		'application/zstd' => 'zst',
		'audio/aac' => 'aac',
		'audio/flac' => 'flac',
		'audio/mp4' => 'm4a',
		'audio/mpeg' => 'mp3',
		'audio/ogg' => 'ogg',
		'audio/opus' => 'opus',
		'audio/wav' => 'wav',
		'audio/webm' => 'weba',
		'audio/x-wav' => 'wav',
		'image/avif' => 'avif',
		'image/bmp' => 'bmp',
		'image/gif' => 'gif',
		'image/heic' => 'heic',
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/svg+xml' => 'svg',
		'image/tiff' => 'tiff',
		'image/webp' => 'webp',
		'text/calendar' => 'ics',
		'text/css' => 'css',
		'text/csv' => 'csv',
		'text/html' => 'html',
		'text/javascript' => 'js',
		'text/markdown' => 'md',
		'text/plain' => 'txt',
		'text/vcard' => 'vcf',
		'text/xml' => 'xml',
		'video/mp4' => 'mp4',
		'video/mpeg' => 'mpeg',
		'video/ogg' => 'ogv',
		'video/quicktime' => 'mov',
		'video/webm' => 'webm',
		'video/x-matroska' => 'mkv',
		'video/x-msvideo' => 'avi',
	];

	public function __construct(
		private IFilenameValidator $filenameValidator,
		private IMimeTypeDetector $mimeTypeDetector,
	) {
	}

	/**
	 * Chooses a valid name from the response of the remote server.
	 *
	 * @param string $contentDisposition The Content-Disposition header of the response
	 * @param string $contentType The Content-Type header of the response
	 * @param list<string> $urls The URLs of the file, by order of preference
	 */
	public function fromResponse(string $contentDisposition, string $contentType, array $urls): string {
		$mimeType = self::parseMimeType($contentType);
		$extension = $this->extensionForMimeType($mimeType);

		$name = $this->sanitize(self::parseContentDisposition($contentDisposition));
		if ($name !== '') {
			if (self::getExtension($name) === '' && $extension !== '') {
				$name .= '.' . $extension;
			}
			return $this->finalize($name, $extension);
		}

		foreach ($urls as $url) {
			$name = $this->sanitize(self::nameFromUrl($url));
			if ($name !== '') {
				return $this->finalize(self::fixUrlExtension($name, $mimeType, $extension), $extension);
			}
		}

		return $this->finalize(self::FALLBACK_NAME . ($extension !== '' ? '.' . $extension : ''), $extension);
	}

	/**
	 * Adds the extension of the detected name to a name chosen without extension.
	 *
	 * @param string $name The name chosen by the user, already validated
	 * @param string $detectedName The name detected from the response
	 */
	public function withExtensionOf(string $name, string $detectedName): string {
		$extension = self::getExtension($detectedName);
		if ($extension === '' || self::getExtension($name) !== '') {
			return $name;
		}

		$completed = $name . '.' . $extension;
		return $this->filenameValidator->isFilenameValid($completed) ? $completed : $name;
	}

	/**
	 * Turns an untrusted name into a plain file name, or an empty string if nothing usable is left.
	 */
	public function sanitize(string $name): string {
		if (!mb_check_encoding($name, 'UTF-8')) {
			$name = mb_convert_encoding($name, 'UTF-8', 'ISO-8859-1');
		}

		// Keep the last path segment only.
		$name = str_replace('\\', '/', $name);
		$slash = strrpos($name, '/');
		if ($slash !== false) {
			$name = substr($name, $slash + 1);
		}

		// Control characters, and bidirectional formatting characters that can disguise an extension.
		$name = preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $name) ?? '';
		// Leading dots would hide the file, trailing dots and spaces are not portable.
		$name = trim($name, " \t\n\r\0\x0B.");
		if ($name === '') {
			return '';
		}

		try {
			$name = trim($this->filenameValidator->sanitizeFilename($name));
		} catch (\InvalidArgumentException) {
			// No replacement character is allowed: the name is validated in finalize().
		}
		return $name;
	}

	/**
	 * Extracts the file name from a Content-Disposition header (RFC 6266).
	 */
	public static function parseContentDisposition(string $header): string {
		$parameters = self::parseHeaderParameters($header);

		if (isset($parameters['filename*'])) {
			$name = self::decodeExtendedValue($parameters['filename*']);
			if ($name !== '') {
				return $name;
			}
		}

		if (isset($parameters['filename']) && $parameters['filename'] !== '') {
			return self::decodePlainValue($parameters['filename']);
		}

		return '';
	}

	/**
	 * Returns the decoded last segment of the path of a URL.
	 */
	public static function nameFromUrl(string $url): string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return '';
		}

		$slash = strrpos($path, '/');
		$segment = rawurldecode($slash === false ? $path : substr($path, $slash + 1));
		if (!mb_check_encoding($segment, 'UTF-8')) {
			$segment = mb_convert_encoding($segment, 'UTF-8', 'ISO-8859-1');
		}
		return $segment;
	}

	/**
	 * Returns the extension of a file name, or an empty string if it has none.
	 */
	public static function getExtension(string $name): string {
		$dot = strrpos($name, '.');
		if ($dot === false || $dot === 0) {
			return '';
		}

		$extension = substr($name, $dot + 1);
		return preg_match('/^[A-Za-z0-9]{1,10}$/', $extension) === 1 ? $extension : '';
	}

	/**
	 * Returns the usual extension of a MIME type, or an empty string if it is unknown.
	 */
	public function extensionForMimeType(string $mimeType): string {
		if ($mimeType === '' || $mimeType === 'application/octet-stream') {
			return '';
		}
		if (isset(self::EXTENSIONS[$mimeType])) {
			return self::EXTENSIONS[$mimeType];
		}

		foreach ($this->mimeTypeDetector->getAllMappings() as $extension => $mimeTypes) {
			if (is_string($extension)
				&& preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1
				&& is_array($mimeTypes)
				&& ($mimeTypes[0] ?? null) === $mimeType) {
				return $extension;
			}
		}
		return '';
	}

	private static function parseMimeType(string $contentType): string {
		return strtolower(trim(explode(';', $contentType, 2)[0]));
	}

	/**
	 * Adapts the extension of a name taken from a URL to the received content.
	 */
	private static function fixUrlExtension(string $name, string $mimeType, string $extension): string {
		$current = strtolower(self::getExtension($name));

		if ($current === '') {
			return $extension !== '' ? $name . '.' . $extension : $name;
		}
		if ($extension !== '' && in_array($current, self::SCRIPT_EXTENSIONS, true)) {
			return substr($name, 0, -strlen($current)) . $extension;
		}
		// A login or error page is returned instead of the expected file.
		if (in_array($mimeType, ['text/html', 'application/xhtml+xml'], true) && !in_array($current, self::HTML_EXTENSIONS, true)) {
			return $name . '.html';
		}
		return $name;
	}

	/**
	 * Shortens the name if needed, and falls back to a generic name if it is still not valid.
	 *
	 * @param string $typeExtension The extension matching the type of the content
	 */
	private function finalize(string $name, string $typeExtension): string {
		$name = self::truncate($name);
		if ($this->filenameValidator->isFilenameValid($name)) {
			return $name;
		}

		// Some databases cannot store 4-byte characters such as emojis.
		$stripped = trim(preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $name) ?? '');
		if ($stripped !== '' && !str_starts_with($stripped, '.') && $this->filenameValidator->isFilenameValid($stripped)) {
			return $stripped;
		}

		foreach ([self::getExtension($name), $typeExtension] as $extension) {
			$fallback = self::FALLBACK_NAME . ($extension !== '' ? '.' . $extension : '');
			if ($this->filenameValidator->isFilenameValid($fallback)) {
				return $fallback;
			}
		}
		return self::FALLBACK_NAME;
	}

	private static function truncate(string $name): string {
		if (strlen($name) <= self::MAX_LENGTH) {
			return $name;
		}

		$extension = self::getExtension($name);
		$suffix = $extension !== '' ? '.' . $extension : '';
		$base = substr($name, 0, strlen($name) - strlen($suffix));
		return rtrim(mb_strcut($base, 0, self::MAX_LENGTH - strlen($suffix), 'UTF-8')) . $suffix;
	}

	/**
	 * Parses the parameters of a header such as `attachment; filename="name.pdf"`.
	 *
	 * @return array<string, string> Values by lowercase parameter name
	 */
	private static function parseHeaderParameters(string $header): array {
		$parameters = [];
		$length = strlen($header);
		$i = 0;

		while ($i < $length) {
			while ($i < $length && ($header[$i] === ';' || ctype_space($header[$i]))) {
				$i++;
			}

			$start = $i;
			while ($i < $length && $header[$i] !== '=' && $header[$i] !== ';') {
				$i++;
			}
			$name = strtolower(trim(substr($header, $start, $i - $start)));
			if ($i >= $length || $header[$i] === ';') {
				// The disposition type, or a parameter without value.
				continue;
			}

			$i++;
			while ($i < $length && ($header[$i] === ' ' || $header[$i] === "\t")) {
				$i++;
			}

			if ($i < $length && $header[$i] === '"') {
				$value = '';
				for ($i++; $i < $length && $header[$i] !== '"'; $i++) {
					// Only \" and \\ are escapes, as servers often send Windows paths unescaped.
					if ($header[$i] === '\\' && $i + 1 < $length && ($header[$i + 1] === '"' || $header[$i + 1] === '\\')) {
						$i++;
					}
					$value .= $header[$i];
				}
				while ($i < $length && $header[$i] !== ';') {
					$i++;
				}
			} else {
				$start = $i;
				while ($i < $length && $header[$i] !== ';') {
					$i++;
				}
				$value = trim(substr($header, $start, $i - $start));
			}

			if ($name !== '' && !isset($parameters[$name])) {
				$parameters[$name] = $value;
			}
		}

		return $parameters;
	}

	/**
	 * Decodes an RFC 8187 value such as `UTF-8''na%C3%AFve.txt`.
	 */
	private static function decodeExtendedValue(string $value): string {
		$parts = explode("'", $value, 3);
		if (count($parts) !== 3) {
			return '';
		}

		$charset = strtoupper(trim($parts[0]));
		$decoded = rawurldecode($parts[2]);
		if ($charset === '' || $charset === 'UTF-8' || $charset === 'UTF8') {
			return mb_check_encoding($decoded, 'UTF-8') ? $decoded : '';
		}

		try {
			$converted = mb_convert_encoding($decoded, 'UTF-8', $charset);
		} catch (\ValueError) {
			return '';
		}
		return is_string($converted) ? $converted : '';
	}

	/**
	 * Decodes a plain filename parameter, accepting the encodings used by real servers.
	 */
	private static function decodePlainValue(string $value): string {
		// RFC 2047 encoded words.
		if (preg_match('/^=\?[^?]+\?[BQbq]\?.*\?=$/s', $value) === 1) {
			$decoded = mb_decode_mimeheader($value);
			if ($decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
				return $decoded;
			}
		}

		// Percent-encoded UTF-8.
		if (preg_match('/%[0-9A-Fa-f]{2}/', $value) === 1) {
			$decoded = rawurldecode($value);
			if (mb_check_encoding($decoded, 'UTF-8')) {
				return $decoded;
			}
		}

		if (mb_check_encoding($value, 'UTF-8')) {
			return $value;
		}
		return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
	}
}
