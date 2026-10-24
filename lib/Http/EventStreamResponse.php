<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Http;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;

/**
 * Streams newline-delimited JSON events while a long task runs.
 *
 * Each event is flushed as soon as it is sent, so the client can follow the
 * task and proxies do not consider the connection idle.
 *
 * @template-extends Response<Http::STATUS_OK, array<string, mixed>>
 */
final class EventStreamResponse extends Response implements ICallbackResponse {
	/**
	 * @param \Closure(\Closure(array<string, mixed>): void): void $task Receives the function sending an event
	 */
	public function __construct(
		private \Closure $task,
	) {
		parent::__construct(Http::STATUS_OK);
		$this->addHeader('Content-Type', 'application/x-ndjson; charset=utf-8');
		// Disables response buffering in nginx.
		$this->addHeader('X-Accel-Buffering', 'no');
	}

	public function callback(IOutput $output): void {
		while (ob_get_level() > 0 && @ob_end_flush()) {
		}

		$send = static function (array $event) use ($output): void {
			$output->setOutput(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
			flush();
		};

		($this->task)($send);
	}
}
