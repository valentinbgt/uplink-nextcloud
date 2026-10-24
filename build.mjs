/**
 * SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Bundles src/main.js into js/uplink-main.js, a single classic script,
// and generates the l10n/*.js translations from the l10n/*.json ones.
// Usage: node build.mjs [--dev] [--watch]

import * as esbuild from 'esbuild'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'

const dev = process.argv.includes('--dev')
const watch = process.argv.includes('--watch')
const outfile = 'js/uplink-main.js'

/**
 * Writes the translations used by the browser, in the format of Nextcloud.
 */
function writeTranslations() {
	for (const file of readdirSync('l10n').filter((name) => name.endsWith('.json'))) {
		const { translations, pluralForm } = JSON.parse(readFileSync(`l10n/${file}`, 'utf8'))
		const entries = Object.entries(translations)
			.map(([source, translation]) => `    ${JSON.stringify(source)} : ${JSON.stringify(translation)}`)
		const script = `OC.L10N.register(\n    "uplink",\n    {\n${entries.join(',\n')}\n},\n${JSON.stringify(pluralForm)});\n`
		writeFileSync(`l10n/${file.replace(/\.json$/, '.js')}`, script)
	}
}

writeTranslations()

/**
 * Lists the npm packages included in the bundle, with their license.
 *
 * @param {import('esbuild').Metafile} metafile The metafile of the build
 */
function writeLicenseFile(metafile) {
	const packages = new Map()
	for (const input of Object.keys(metafile.inputs)) {
		const match = input.match(/^(.*node_modules\/((?:@[^/]+\/)?[^/]+))\//)
		if (!match || packages.has(match[1])) {
			continue
		}
		const { name, version, license } = JSON.parse(readFileSync(`${match[1]}/package.json`, 'utf8'))
		packages.set(match[1], { name, version, license })
	}

	const sorted = [...packages.values()].sort((a, b) => a.name.localeCompare(b.name))
	const licenses = [...new Set(['AGPL-3.0-or-later', ...sorted.map((pkg) => pkg.license)])]
	const lines = [
		...licenses.map((license) => `SPDX-License-Identifier: ${license}`),
		'SPDX-FileCopyrightText: Valentin and Uplink contributors',
		'',
		'This file is generated from multiple sources. Included packages:',
		...sorted.map((pkg) => `- ${pkg.name}\n\t- version: ${pkg.version}\n\t- license: ${pkg.license}`),
		'',
	]
	writeFileSync(`${outfile}.license`, lines.join('\n'))
}

const options = {
	entryPoints: ['src/main.js'],
	outfile,
	bundle: true,
	format: 'iife',
	target: ['es2020'],
	minify: !dev,
	sourcemap: dev ? 'inline' : false,
	legalComments: 'none',
	loader: { '.svg': 'text' },
	define: { 'process.env.NODE_ENV': JSON.stringify(dev ? 'development' : 'production') },
	banner: { js: '/*! Uplink for Nextcloud, see uplink-main.js.license for the included packages */' },
	metafile: true,
	logLevel: 'info',
	plugins: [{
		name: 'license-file',
		setup(build) {
			build.onEnd((result) => {
				if (result.metafile) {
					writeLicenseFile(result.metafile)
				}
			})
		},
	}],
}

if (watch) {
	const context = await esbuild.context(options)
	await context.watch()
} else {
	await esbuild.build(options)
}
