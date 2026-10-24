<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OC\Hooks;

/**
 * Stub of a private interface extended by OCP\Files\IRootFolder before Nextcloud 34.
 */
interface Emitter {
	public function listen($scope, $method, callable $callback);

	public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
