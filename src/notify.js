/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Shows a short message, with the toasts of Nextcloud when they are available.
 *
 * @param {'success'|'error'} type The kind of message
 * @param {string} message The plain text message
 */
export function notify(type, message) {
	const toasts = window.OCP?.Toast
	if (typeof toasts?.[type] === 'function') {
		try {
			toasts[type](message)
			return
		} catch {
			// Use the fallback below.
		}
	}

	const notice = document.createElement('div')
	notice.className = `uplink-notice uplink-notice--${type}`
	notice.setAttribute('role', type === 'error' ? 'alert' : 'status')
	notice.textContent = message
	notice.addEventListener('click', () => notice.remove())
	document.body.append(notice)
	setTimeout(() => notice.remove(), type === 'error' ? 10000 : 5000)
}
