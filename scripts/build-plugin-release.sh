#!/usr/bin/env bash

set -euo pipefail

if [[ $# -ne 3 ]]; then
	echo "Usage: $0 <monitor|agent> <version> <output-directory>" >&2
	exit 64
fi

plugin_key="$1"
expected_version="${2#v}"
output_directory="$3"
repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! "$expected_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: $expected_version" >&2
	exit 64
fi

case "$plugin_key" in
	monitor)
		plugin_directory="$repository_root/plugins/monitor"
		plugin_slug="od-wordpress-monitor"
		main_file="od-wordpress-monitor.php"
		version_constant="OD_WORDPRESS_MONITOR_VERSION"
		;;
	agent)
		plugin_directory="$repository_root/plugins/agent"
		plugin_slug="od-monitor-agent"
		main_file="od-monitor-agent.php"
		version_constant="OD_MONITOR_AGENT_VERSION"
		;;
	*)
		echo "Unknown plugin: $plugin_key" >&2
		exit 64
		;;
esac

for command_name in composer rsync zip; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Required command not found: $command_name" >&2
		exit 69
	fi
done

plugin_file="$plugin_directory/$main_file"
header_version="$(awk -F ':' '/^[[:space:]]*\*[[:space:]]*Version:/ { sub(/^[[:space:]]+/, "", $2); print $2; exit }' "$plugin_file")"
constant_version="$(sed -n "s/.*define( '${version_constant}', '\([^']*\)' );.*/\1/p" "$plugin_file" | head -n 1)"

if [[ "$header_version" != "$expected_version" ]]; then
	echo "Plugin header version ($header_version) does not match release version ($expected_version)." >&2
	exit 65
fi

if [[ "$constant_version" != "$expected_version" ]]; then
	echo "Version constant ($constant_version) does not match release version ($expected_version)." >&2
	exit 65
fi

mkdir -p "$output_directory"
output_directory="$(cd "$output_directory" && pwd)"
archive_path="$output_directory/$plugin_slug.zip"
distribution_directory="$output_directory/$plugin_slug"

if [[ -e "$archive_path" || -e "$distribution_directory" ]]; then
	echo "Release output already exists for: $plugin_slug" >&2
	exit 73
fi

temporary_directory="$(mktemp -d "${TMPDIR:-/tmp}/odm-release.XXXXXX")"
trap 'rm -rf "$temporary_directory"' EXIT

package_directory="$temporary_directory/$plugin_slug"
mkdir -p "$package_directory"

rsync -a \
	--exclude '/tests/' \
	--exclude '/vendor/' \
	--exclude '/.phpunit.result.cache' \
	"$plugin_directory/" \
	"$package_directory/"

composer install \
	--working-dir="$package_directory" \
	--no-dev \
	--prefer-dist \
	--optimize-autoloader \
	--no-interaction

mv "$package_directory" "$distribution_directory"

(
	cd "$output_directory"
	zip -q -r "$archive_path" "$plugin_slug"
)

printf '%s  %s\n' "$(shasum -a 256 "$archive_path" | awk '{ print $1 }')" "$(basename "$archive_path")" > "$archive_path.sha256"

echo "Created $archive_path"
echo "Created $archive_path.sha256"
