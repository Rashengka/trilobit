#!/bin/bash
#
# Runs `composer check` inside the floor image, on a copy of the checkout.
#
# The checkout is mounted read-only and the gate runs on a copy of it, because
# the gate writes: a suite rebuilds var/build, PHPUnit and the analysers keep
# caches. Through a writable mount all of that would land in the files the
# development container works with - written by a different PHP - and nothing
# would say so. Read-only, it cannot happen, rather than having to be avoided
# path by path.
#
# The copy is what a CI checkout of this working tree would hold: every file git
# would commit, tracked or not yet added, as it is on disk right now. On top of
# it goes vendor/ as installed. Nothing else - no var/, no caches, no .env, no
# node_modules - so a result cannot lean on state a fresh checkout lacks. The
# database comes from the environment the compose service sets, as it does in
# CI.
#
set -euo pipefail

mkdir -p /app

cd /src

git ls-files -z --cached --others --exclude-standard \
  | while IFS= read -r -d '' path; do
      # A tracked file deleted in the working tree is still in the index. It is
      # not in the checkout, so it does not go into the copy.
      if [ -e "$path" ] || [ -L "$path" ]; then
        printf '%s\0' "$path"
      fi
    done \
  | tar --null --files-from=- --create --file=- \
  | tar --directory=/app --extract --file=-

tar --create --file=- vendor | tar --directory=/app --extract --file=-

# The copy's own pointer at the repository, which is mounted read-only at the
# path it has on the host: the path a worktree's .git file already names, and
# the one a main checkout's .git directory is at.
printf 'gitdir: %s\n' "$TRILOBIT_FLOOR_GIT_DIR" > /app/.git

cd /app

php -r 'echo "check-floor: composer check on PHP ", PHP_VERSION, PHP_EOL;'

exec composer check
