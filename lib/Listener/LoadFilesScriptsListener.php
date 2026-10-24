<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Uplink\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the "Add from link" entry of the Files "New" menu.
 *
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}

		// Init scripts run before the Files app reads the "New" menu entries.
		Util::addInitScript(Application::APP_ID, 'uplink-main');
		Util::addStyle(Application::APP_ID, 'uplink');
	}
}
