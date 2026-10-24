/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'
import { translate } from './l10n.js'

/**
 * An error whose message can be shown to the user.
 */
export class UplinkError extends Error {}

/**
 * @typedef {object} SavedFile
 * @property {string} id The file id
 * @property {string} name The final name of the file
 * @property {number} size The size in bytes
 * @property {number} mtime The modification time in seconds
 * @property {string} mimetype The MIME type
 * @property {number} permissions The Nextcloud permissions of the file
 * @property {boolean} hasPreview Whether a preview can be generated
 */

/**
 * Asks the server to download a file into a folder, and follows the download.
 *
 * @param {object} request The download to start
 * @param {string} request.url The http(s) link of the file
 * @param {string} request.path The destination folder, relative to the files of the user
 * @param {string} request.name The file name, empty to detect it
 * @param {(event: {type: string, loaded?: number, total?: number|null}) => void} onProgress Receives the progress events
 * @return {Promise<SavedFile>}
 * @throws {UplinkError}
 */
export async function addFromLink({ url, path, name }, onProgress) {
	let response
	try {
		response = await fetch(generateUrl('/apps/uplink/download'), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				Accept: 'application/x-ndjson, application/json',
				'Content-Type': 'application/json',
				requesttoken: getRequestToken() ?? '',
			},
			body: JSON.stringify({ url, path, name }),
		})
	} catch {
		throw new UplinkError(translate('The server could not be reached. Check your connection and try again.'))
	}

	if (!response.ok) {
		throw new UplinkError(await getErrorMessage(response))
	}
	// Some proxies answer with their own page instead of the response of Nextcloud.
	if (!(response.headers.get('Content-Type') ?? '').includes('application/x-ndjson')) {
		throw new UplinkError(translate('The server returned an unexpected response.'))
	}

	let file = null
	let error = null
	try {
		await readEvents(response, (event) => {
			if (event.type === 'done') {
				file = event.file
			} else if (event.type === 'error') {
				error = String(event.message ?? '')
			} else {
				try {
					onProgress(event)
				} catch (e) {
					console.error('[uplink] Could not show the progress', e)
				}
			}
		})
	} catch {
		// The connection was lost: reported below unless the result was already received.
	}

	if (file !== null) {
		return file
	}
	if (error !== null) {
		throw new UplinkError(error || translate('The file could not be downloaded.'))
	}
	throw new UplinkError(translate('The connection to the server was lost. The file will still be added if the server completes the download.'))
}

/**
 * Reads the newline-delimited JSON events of a response.
 *
 * @param {Response} response The streamed response
 * @param {(event: object) => void} onEvent Receives each event
 */
async function readEvents(response, onEvent) {
	const handleLine = (line) => {
		if (line.trim() === '') {
			return
		}
		let event
		try {
			event = JSON.parse(line)
		} catch {
			return
		}
		if (event !== null && typeof event === 'object') {
			onEvent(event)
		}
	}

	if (!response.body?.getReader) {
		(await response.text()).split('\n').forEach(handleLine)
		return
	}

	const reader = response.body.getReader()
	const decoder = new TextDecoder()
	let buffer = ''
	for (;;) {
		const { done, value } = await reader.read()
		buffer += decoder.decode(value, { stream: !done })
		const lines = buffer.split('\n')
		buffer = lines.pop()
		lines.forEach(handleLine)
		if (done) {
			break
		}
	}
	handleLine(buffer)
}

/**
 * Returns the message to show for a request rejected by the server.
 *
 * @param {Response} response The error response
 * @return {Promise<string>}
 */
async function getErrorMessage(response) {
	if (response.status === 401) {
		return translate('Your session has expired. Reload the page and log in again.')
	}
	if (response.status === 412) {
		return translate('This page has expired. Reload it and try again.')
	}

	try {
		const data = await response.json()
		if (typeof data?.message === 'string' && data.message !== '') {
			return data.message
		}
	} catch {
		// Not a JSON response.
	}
	return translate('The server returned an error ({status}).', { status: response.status })
}
