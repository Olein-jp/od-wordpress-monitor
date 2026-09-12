#!/usr/bin/env bash

set -euo pipefail

if [[ $# -gt 1 ]]; then
	echo "Usage: $0 [monitor|agent]" >&2
	exit 64
fi

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
requested_plugin="${1:-}"

for command_name in npx msgmerge msgfmt; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Required command not found: $command_name" >&2
		exit 69
	fi
done

update_plugin() {
	local plugin_key="$1"
	local text_domain

	case "$plugin_key" in
		monitor)
			text_domain="od-wordpress-monitor"
			;;
		agent)
			text_domain="od-monitor-agent"
			;;
		*)
			echo "Unknown plugin: $plugin_key" >&2
			exit 64
			;;
	esac

	local plugin_directory="plugins/$plugin_key"
	local languages_directory="$repository_root/$plugin_directory/languages"
	local pot_file="$languages_directory/$text_domain.pot"
	local po_file="$languages_directory/$text_domain-ja.po"
	local mo_file="$languages_directory/$text_domain-ja.mo"

	mkdir -p "$languages_directory"

	(
		cd "$repository_root"
		npx wp-env run cli --config=.wp-env.test.json \
			--env-cwd=wp-content/od-wordpress-monitor-workspace \
			wp i18n make-pot "$plugin_directory" "$plugin_directory/languages/$text_domain.pot" \
			--domain="$text_domain" \
			--exclude=tests,vendor \
			--skip-js
	)

	msgmerge --quiet --update --backup=none "$po_file" "$pot_file"
	msgfmt --check-format --output-file="$mo_file" "$po_file"

	echo "Updated $plugin_key translations."
}

if [[ -n "$requested_plugin" ]]; then
	update_plugin "$requested_plugin"
else
	update_plugin monitor
	update_plugin agent
fi
