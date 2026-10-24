<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Exception;

use OCP\AppFramework\Http;

/**
 * A download failure whose message is translated and safe to show to the user.
 */
class DownloadException extends \Exception {
	public function __construct(
		string $message,
		private int $status = Http::STATUS_BAD_REQUEST,
		?\Throwable $previous = null,
	) {
		parent::__construct($message, 0, $previous);
	}

	public function getStatus(): int {
		return $this->status;
	}
}
