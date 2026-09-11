#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
	echo "Usage: $0 <monitor|agent> <version> <archive>" >&2
	exit 64
fi

plugin_key="$1"
expected_version="${2#v}"
archive_path="$3"

case "$plugin_key" in
	monitor)
		plugin_slug="od-wordpress-monitor"
		main_file="od-wordpress-monitor.php"
		version_constant="OD_WORDPRESS_MONITOR_VERSION"
		;;
	agent)
		plugin_slug="od-monitor-agent"
		main_file="od-monitor-agent.php"
		version_constant="OD_MONITOR_AGENT_VERSION"
		;;
	*)
		echo "Unknown plugin: $plugin_key" >&2
		exit 64
		;;
esac

if [[ ! -f "$archive_path" ]]; then
	echo "Release archive not found: $archive_path" >&2
	exit 66
fi

checksum_path="$archive_path.sha256"

if [[ ! -f "$checksum_path" ]]; then
	echo "Release checksum not found: $checksum_path" >&2
	exit 66
fi

for command_name in php unzip shasum; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Required command not found: $command_name" >&2
		exit 69
	fi
done

temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/odm-release-smoke.XXXXXX")"
trap 'rm -rf "$temporary_directory"' EXIT

unzip -q "$archive_path" -d "$temporary_directory"
package_directory="$temporary_directory/$plugin_slug"
plugin_file="$package_directory/$main_file"

if [[ ! -f "$plugin_file" || ! -f "$package_directory/vendor/autoload.php" ]]; then
	echo "Archive is missing its plugin bootstrap or production autoloader." >&2
	exit 65
fi

while IFS= read -r entry; do
	case "$entry" in
		"$plugin_slug"/*) ;;
		*)
			echo "Archive entry is outside the plugin directory: $entry" >&2
			exit 65
			;;
	esac

	case "$entry" in
		*/tests/*|*/vendor/bin/*|*/phpunit.xml*|*/phpcs.xml*|*/.git/*)
			echo "Development-only file found in archive: $entry" >&2
			exit 65
			;;
	esac
done < <(unzip -Z1 "$archive_path")

header_version="$(awk -F ':' '/^[[:space:]]*\*[[:space:]]*Version:/ { sub(/^[[:space:]]+/, "", $2); print $2; exit }' "$plugin_file")"
constant_version="$(sed -n "s/.*define( '${version_constant}', '\([^']*\)' );.*/\1/p" "$plugin_file" | head -n 1)"

if [[ "$header_version" != "$expected_version" || "$constant_version" != "$expected_version" ]]; then
	echo "Archive version does not match $expected_version." >&2
	exit 65
fi

while IFS= read -r php_file; do
	php -l "$php_file" >/dev/null
done < <(find "$package_directory" -type f -name '*.php' -print)

( cd "$(dirname "$archive_path")" && shasum -a 256 -c "$(basename "$checksum_path")" )
echo "Verified $plugin_slug $expected_version"
