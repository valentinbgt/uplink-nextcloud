<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Uplink\Controller;

use OCA\Uplink\AppInfo\Application;
use OCA\Uplink\Exception\DownloadException;
use OCA\Uplink\Http\EventStreamResponse;
use OCA\Uplink\Service\DownloadService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IL10N;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class DownloadController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private ISession $session,
		private DownloadService $downloadService,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Downloads a file from a URL into a folder of the current user.
	 *
	 * Invalid requests get a JSON error response. Once the download has
	 * started, the response is a stream of newline-delimited JSON events:
	 * - `{"type": "progress", "loaded": int, "total": int|null}`, about every second
	 * - `{"type": "saving"}`, when the file is being written to the folder
	 * - `{"type": "done", "file": {...}}`, see {@see DownloadService::describe()}
	 * - `{"type": "error", "message": string}`
	 *
	 * @param ?string $url The http(s) URL of the file
	 * @param ?string $path The destination folder, relative to the files of the user
	 * @param ?string $name The file name, empty to detect it from the response
	 */
	#[NoAdminRequired]
	#[FrontpageRoute(verb: 'POST', url: '/download')]
	public function download(?string $url = null, ?string $path = null, ?string $name = null): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => $this->l10n->t('You are not logged in.')], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$download = $this->downloadService->prepare($user->getUID(), $url ?? '', $path ?? '/', $name ?? '');
		} catch (DownloadException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}

		// Other requests of the user must not wait for the session lock during the download.
		$this->session->close();

		return new EventStreamResponse(function (\Closure $send) use ($download): void {
			// The download is completed even if the user leaves the page.
			if (function_exists('ignore_user_abort')) {
				ignore_user_abort(true);
			}
			if (function_exists('set_time_limit')) {
				@set_time_limit(0);
			}

			$send(['type' => 'progress', 'loaded' => 0, 'total' => null]);
			try {
				$file = $this->downloadService->run(
					$download,
					static function (int $loaded, ?int $total) use ($send): void {
						$send(['type' => 'progress', 'loaded' => $loaded, 'total' => $total]);
					},
					static function () use ($send): void {
						$send(['type' => 'saving']);
					},
				);
				$send(['type' => 'done', 'file' => $this->downloadService->describe($file)]);
			} catch (DownloadException $e) {
				$send(['type' => 'error', 'message' => $e->getMessage()]);
			} catch (\Throwable $e) {
				$this->logger->error('Unexpected error while downloading a file from a link', ['exception' => $e]);
				$send(['type' => 'error', 'message' => $this->l10n->t('An unexpected error occurred.')]);
			}
		});
	}
}
