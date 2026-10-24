/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import CloseSvg from '@mdi/svg/svg/close.svg'
import { getCurrentUser } from '@nextcloud/auth'
import { emit } from '@nextcloud/event-bus'
import { File, formatFileSize } from '@nextcloud/files'
import { addFromLink, UplinkError } from './api.js'
import { translate } from './l10n.js'
import { notify } from './notify.js'

let dialogCount = 0

/**
 * Opens the dialog adding a file from a link to a folder.
 *
 * @param {import('@nextcloud/files').IFolder} folder The destination folder
 */
export function openDialog(folder) {
	new AddFromLinkDialog(folder).open()
}

class AddFromLinkDialog {
	/**
	 * @param {import('@nextcloud/files').IFolder} folder The destination folder
	 */
	constructor(folder) {
		this.folder = folder
		this.running = false
		const id = `uplink-dialog-${++dialogCount}`

		this.urlInput = h('input', {
			id: `${id}-url`,
			class: 'uplink-dialog__input',
			type: 'url',
			inputmode: 'url',
			placeholder: 'https://',
			autocomplete: 'off',
			spellcheck: 'false',
			required: true,
		})
		this.nameInput = h('input', {
			id: `${id}-name`,
			class: 'uplink-dialog__input',
			type: 'text',
			placeholder: translate('Detected automatically'),
			autocomplete: 'off',
			spellcheck: 'false',
		})
		this.fields = h('div', { class: 'uplink-dialog__fields' },
			h('label', { class: 'uplink-dialog__label', for: this.urlInput.id, text: translate('Link') }),
			this.urlInput,
			h('label', { class: 'uplink-dialog__label', for: this.nameInput.id },
				translate('File name'), ' ',
				h('span', { class: 'uplink-dialog__optional', text: translate('(optional)') })),
			this.nameInput,
			h('p', { class: 'uplink-dialog__hint', text: translate('The file will be added to "{folder}".', { folder: getFolderName(folder) }) }),
		)

		this.status = h('p', { class: 'uplink-dialog__status', 'aria-live': 'polite' })
		this.barFill = h('div', { class: 'uplink-dialog__bar-fill' })
		this.bar = h('div', {
			class: 'uplink-dialog__bar',
			role: 'progressbar',
			'aria-label': translate('Download progress'),
			'aria-valuemin': '0',
			'aria-valuemax': '100',
		}, this.barFill)
		this.progress = h('div', { class: 'uplink-dialog__progress', hidden: true },
			this.status,
			this.bar,
			h('p', { class: 'uplink-dialog__hint', text: translate('You can close this window, the download continues on the server.') }),
		)

		this.error = h('p', { class: 'uplink-dialog__error', role: 'alert', hidden: true })

		this.cancelButton = h('button', {
			type: 'button',
			class: 'uplink-dialog__button uplink-dialog__button--tertiary',
			text: translate('Cancel'),
		})
		this.submitButton = h('button', {
			type: 'submit',
			class: 'uplink-dialog__button uplink-dialog__button--primary',
			text: translate('Add'),
		})
		const closeButton = h('button', {
			type: 'button',
			class: 'uplink-dialog__close',
			'aria-label': translate('Close'),
			title: translate('Close'),
		})
		closeButton.innerHTML = CloseSvg

		const titleId = `${id}-title`
		const form = h('form', { class: 'uplink-dialog__form', novalidate: true },
			h('div', { class: 'uplink-dialog__header' },
				h('h2', { id: titleId, class: 'uplink-dialog__title', text: translate('Add from link') }),
				closeButton),
			this.fields,
			this.progress,
			this.error,
			h('div', { class: 'uplink-dialog__actions' }, this.cancelButton, this.submitButton),
		)
		this.dialog = h('dialog', { class: 'uplink-dialog', 'aria-labelledby': titleId }, form)

		form.addEventListener('submit', (event) => {
			event.preventDefault()
			this.start()
		})
		closeButton.addEventListener('click', () => this.dialog.close())
		this.cancelButton.addEventListener('click', () => this.dialog.close())
		// A running download continues in the background once the dialog is closed.
		this.dialog.addEventListener('close', () => this.dialog.remove())
	}

	open() {
		document.body.append(this.dialog)
		this.dialog.showModal()
		this.urlInput.focus()
	}

	async start() {
		if (this.running) {
			return
		}

		const url = normalizeUrl(this.urlInput.value)
		if (url === null) {
			this.showError(translate('Enter a link starting with http:// or https://.'))
			this.urlInput.focus()
			return
		}

		this.showError('')
		this.setRunning(true)
		try {
			const file = await addFromLink(
				{ url, path: this.folder.path, name: this.nameInput.value.trim() },
				(event) => this.showProgress(event),
			)
			addToFileList(this.folder, file)
			this.dialog.close()
			notify('success', translate('"{name}" has been added.', { name: file.name }))
		} catch (error) {
			if (!(error instanceof UplinkError)) {
				console.error('[uplink] Could not add the file', error)
			}
			const message = error instanceof UplinkError ? error.message : translate('An unexpected error occurred.')
			if (this.dialog.open) {
				this.setRunning(false)
				this.showError(message)
				this.urlInput.focus()
			} else {
				notify('error', message)
			}
		}
	}

	/**
	 * @param {boolean} running Whether a download is running
	 */
	setRunning(running) {
		this.running = running
		this.fields.hidden = running
		this.progress.hidden = !running
		this.submitButton.hidden = running
		this.cancelButton.textContent = running ? translate('Close') : translate('Cancel')
		if (running) {
			this.setStatus(translate('Connecting…'), null)
			this.cancelButton.focus()
		}
	}

	/**
	 * @param {{type: string, loaded?: number, total?: number|null}} event A progress event of the server
	 */
	showProgress(event) {
		if (event.type === 'saving') {
			this.setStatus(translate('Saving the file…'), null)
			return
		}
		if (event.type !== 'progress' || !(event.loaded > 0)) {
			return
		}

		const loaded = formatFileSize(event.loaded)
		if (event.total > 0) {
			const total = formatFileSize(event.total)
			this.setStatus(translate('Downloading… {loaded} of {total}', { loaded, total }), event.loaded / event.total)
		} else {
			this.setStatus(translate('Downloading… {loaded}', { loaded }), null)
		}
	}

	/**
	 * @param {string} text The status text
	 * @param {number|null} fraction The completed fraction, null if unknown
	 */
	setStatus(text, fraction) {
		this.status.textContent = text
		this.bar.classList.toggle('uplink-dialog__bar--indeterminate', fraction === null)
		if (fraction === null) {
			this.barFill.style.removeProperty('width')
			this.bar.removeAttribute('aria-valuenow')
		} else {
			const percent = Math.round(Math.min(Math.max(fraction, 0), 1) * 100)
			this.barFill.style.width = `${percent}%`
			this.bar.setAttribute('aria-valuenow', String(percent))
		}
	}

	/**
	 * @param {string} message The error to show, empty to hide it
	 */
	showError(message) {
		this.error.textContent = message
		this.error.hidden = message === ''
	}
}

/**
 * Adds a saved file to the list of the Files app, without reloading the folder.
 *
 * @param {import('@nextcloud/files').IFolder} folder The folder of the file
 * @param {import('./api.js').SavedFile} file The saved file
 */
function addToFileList(folder, file) {
	try {
		// The Files app expects numeric ids, as long as they are representable.
		const id = Number(file.id)
		const node = new File({
			source: `${folder.source}/${file.name}`,
			root: folder.root,
			id: Number.isSafeInteger(id) ? id : file.id,
			owner: folder.owner ?? getCurrentUser()?.uid ?? null,
			mime: file.mimetype,
			mtime: new Date(file.mtime * 1000),
			size: file.size,
			permissions: file.permissions,
			attributes: {
				// Inherited from the parent folder, like for the files created by the Files app.
				'mount-type': folder.attributes?.['mount-type'],
				'owner-id': folder.attributes?.['owner-id'],
				'owner-display-name': folder.attributes?.['owner-display-name'],
				'has-preview': file.hasPreview === true,
			},
		})
		emit('files:node:created', node)
	} catch (error) {
		// The file is saved anyway and is listed once the folder is reloaded.
		console.error('[uplink] Could not add the file to the list', error)
	}
}

/**
 * Returns an absolute http(s) URL, or null if the value is not a valid link.
 *
 * @param {string} value The link entered by the user
 * @return {string|null}
 */
function normalizeUrl(value) {
	let url = value.trim()
	if (url === '') {
		return null
	}
	// A link copied without its scheme, such as "example.com/file.pdf".
	if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(url)) {
		url = `https://${url}`
	}

	try {
		const parsed = new URL(url)
		// Some browsers accept spaces in host names and encode them.
		if ((parsed.protocol === 'http:' || parsed.protocol === 'https:') && /^[^\s%]+$/.test(parsed.hostname)) {
			return parsed.href
		}
	} catch {
		// Not a URL.
	}
	return null
}

/**
 * @param {import('@nextcloud/files').IFolder} folder A folder
 * @return {string} The name to show for the folder
 */
function getFolderName(folder) {
	return folder.path === '/' ? translate('All files') : folder.displayname || folder.basename
}

/**
 * Creates an element.
 *
 * @param {string} tag The tag name
 * @param {Record<string, string|boolean>} attributes The attributes, and `text` for the text content
 * @param {...(Node|string)} children The children
 * @return {HTMLElement}
 */
function h(tag, attributes = {}, ...children) {
	const element = document.createElement(tag)
	for (const [name, value] of Object.entries(attributes)) {
		if (name === 'text') {
			element.textContent = value
		} else if (value === true) {
			element.setAttribute(name, '')
		} else if (value !== false) {
			element.setAttribute(name, value)
		}
	}
	element.append(...children)
	return element
}
