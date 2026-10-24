<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Service;

use OCP\Files\Folder;

/**
 * A validated download request.
 */
final class PendingDownload {
	/**
	 * @param string $url The http(s) URL of the file
	 * @param Folder $folder The folder receiving the file
	 * @param string $name The validated name chosen by the user, empty to detect it
	 */
	public function __construct(
		public readonly string $url,
		public readonly Folder $folder,
		public readonly string $name,
	) {
	}
}
