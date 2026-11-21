#!/usr/bin/env bash
# Runs the real uninstall in a wp-env site and checks what is left.
#
# Seeds data (on every site of a multisite network), deactivates the plugin,
# runs `wp plugin uninstall --skip-delete` (the files stay), checks each site,
# then activates the plugin again, which recreates its defaults and the Fresh
# Eyes user.
#
# Usage: npm run test:uninstall              (wp-env's development site)
#        WP_ENV_CLI=tests-cli npm run test:uninstall   (the tests site, eg. after
#        `npx wp-env run tests-cli wp core multisite-convert`)
set -euo pipefail

container=${WP_ENV_CLI:-cli}
cli() {
	npx wp-env run "$container" -- "$@"
}

# wp-env's tests site can hold a second copy; prefer the active one.
slug=$(cli wp plugin list --fields=name,file,status --format=csv | awk -F, '$2 ~ /\/fresh-eyes\.php$/ { print ($3 ~ /^active/ ? 0 : 1) "," $1 }' | sort | head -1 | cut -d, -f2)
if [ -z "$slug" ]; then
	echo "Fresh Eyes is not installed in wp-env." >&2
	exit 1
fi
dir="wp-content/plugins/$slug/tests/uninstall"

network=()
urls=("")
if [ "$(cli wp eval 'echo is_multisite() ? 1 : 0;' | tail -1)" = "1" ]; then
	network=(--network)
	urls=()
	# Captured first, so a failed lookup stops the script under set -e.
	site_list=$(cli wp site list --field=url)
	while IFS= read -r url; do
		if [[ $url == http* ]]; then
			urls+=("$url")
		fi
	done <<< "$site_list"
	if [ ${#urls[@]} -eq 0 ]; then
		echo "No sites found in the network." >&2
		exit 1
	fi
fi

cli wp plugin activate "$slug" ${network[@]+"${network[@]}"} >/dev/null || true
for url in "${urls[@]}"; do
	cli wp eval-file "$dir/seed.php" ${url:+--url="$url"}
done
cli wp plugin deactivate "$slug" ${network[@]+"${network[@]}"}
cli wp plugin uninstall "$slug" --skip-delete
status=0
for url in "${urls[@]}"; do
	cli wp eval-file "$dir/verify.php" --skip-plugins ${url:+--url="$url"} || status=$?
done
cli wp user meta delete admin myplugin_ai_feedback_personal_house_style >/dev/null || true
cli wp plugin activate "$slug" ${network[@]+"${network[@]}"}
exit $status
