/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/**
 * Translates a text of the app, to be used as plain text rather than HTML.
 *
 * @param {string} text The English text
 * @param {Record<string, string|number>} [vars] The values of the {placeholders}
 * @return {string}
 */
export function translate(text, vars = {}) {
	return t('uplink', text, vars, { escape: false, sanitize: false })
}
