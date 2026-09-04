#!/bin/sh
#
# Let the application's own user create the schemas the tests run in.
#
# The suites that need a database create one per test class, named after the
# application's database with a suffix, and drop it again afterwards, so that
# two of them can never be looking at each other's tables. The image grants the
# user rights on one schema only, which is right for a deployment and one short
# for a checkout.
#
# The grant stays narrow: schemas whose name begins with the application's own,
# and nothing else on the server. The escaped underscore matters - unescaped it
# is a wildcard for any single character, and the grant would reach a good deal
# further than it reads.
#
# The image runs this once, on an empty volume. A checkout whose database
# volume predates this file needs `docker compose down -v` for it to apply.
#
set -eu

mariadb -uroot -p"${MARIADB_ROOT_PASSWORD}" -e \
    "GRANT ALL PRIVILEGES ON \`${MARIADB_DATABASE}\\_%\`.* TO '${MARIADB_USER}'@'%'; FLUSH PRIVILEGES;"
