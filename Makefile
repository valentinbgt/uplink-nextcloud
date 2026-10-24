# SPDX-FileCopyrightText: 2026 Valentin and Uplink contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

app_name := uplink
version := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
build_dir := build
package := $(build_dir)/$(app_name)-$(version).tar.gz

.PHONY: build test appstore clean

# Builds js/uplink-main.js and l10n/*.js
build:
	npm ci
	npm run build

# Runs the PHP unit tests
test:
	composer install --no-interaction --no-progress
	vendor/bin/phpunit

# Creates an archive containing only the files needed at runtime
appstore: build
	rm -rf $(build_dir)/$(app_name)
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo css img js l10n lib LICENSE README.md $(build_dir)/$(app_name)/
	tar -czf $(package) -C $(build_dir) $(app_name)
	@echo "Created $(package)"

clean:
	rm -rf $(build_dir)
