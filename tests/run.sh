#!/bin/sh
# Runs every test file, each in its own PHP process. Exits non-zero if any
# assertion fails. No WordPress or MySQL needed: see harness.php.
status=0
for file in "$(dirname "$0")"/test-*.php; do
	echo "== $(basename "$file")"
	php "$file" || status=1
	echo
done
exit $status
