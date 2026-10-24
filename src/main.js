/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import LinkPlusSvg from '@mdi/svg/svg/link-variant-plus.svg'
import { getCurrentUser } from '@nextcloud/auth'
import { addNewFileMenuEntry, Permission } from '@nextcloud/files'
import { openDialog } from './dialog.js'
import { translate } from './l10n.js'

addNewFileMenuEntry({
	id: 'uplink-add-from-link',
	displayName: translate('Add from link'),
	iconSvgInline: LinkPlusSvg,
	// Right after "New folder".
	order: 5,

	enabled(folder) {
		const user = getCurrentUser()
		// Only in the files of the current user, not on public shares or in end-to-end encrypted folders.
		return user !== null
			&& folder.root === `/files/${user.uid}`
			&& (folder.permissions & Permission.CREATE) !== 0
			&& !folder.attributes?.['is-encrypted']
	},

	handler(folder) {
		openDialog(folder)
	},
})
