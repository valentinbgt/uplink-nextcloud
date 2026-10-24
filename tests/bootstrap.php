<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The unit tests only need the public API of Nextcloud: either the
// nextcloud/ocp package installed with Composer, or a server checkout set
// in NEXTCLOUD_SERVER (by default the server the app is installed in).

$appRoot = dirname(__DIR__);

if (is_file($appRoot . '/vendor/autoload.php')) {
	require_once $appRoot . '/vendor/autoload.php';
}

if (!interface_exists(\OCP\Files\IFilenameValidator::class)) {
	$server = getenv('NEXTCLOUD_SERVER') ?: dirname($appRoot, 2);
	if (!is_file($server . '/lib/public/Files/IFilenameValidator.php')) {
		fwrite(STDERR, "Run `composer install` or set NEXTCLOUD_SERVER to a Nextcloud server checkout.\n");
		exit(1);
	}
	require_once $server . '/3rdparty/autoload.php';
	spl_autoload_register(static function (string $class) use ($server): void {
		if (str_starts_with($class, 'OCP\\')) {
			$file = $server . '/lib/public/' . str_replace('\\', '/', substr($class, 4)) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
		}
	});
}

// Private interface extended by OCP\Files\IRootFolder before Nextcloud 34.
spl_autoload_register(static function (string $class): void {
	if ($class === 'OC\\Hooks\\Emitter') {
		require_once __DIR__ . '/stubs/Emitter.php';
	}
});

spl_autoload_register(static function (string $class) use ($appRoot): void {
	if (str_starts_with($class, 'OCA\\Uplink\\')) {
		$file = $appRoot . '/lib/' . str_replace('\\', '/', substr($class, strlen('OCA\\Uplink\\'))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
