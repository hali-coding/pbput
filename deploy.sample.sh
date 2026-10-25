#!/bin/sh
# Deploy pbput to the live server.
# Files there are owned by www-data (deploy user is in that group): existing
# files are updated in place to keep their owner, new ones get group www-data.
set -eu
TARGET="${TARGET:-user@server:/path}"
cd "$(dirname "$0")"
rsync -rl --inplace --omit-dir-times --no-perms --no-owner --no-group \
  --exclude '.git' --exclude '.gitignore' --exclude '.claude' --exclude 'config.php' --exclude 'config.sample.php' \
  --exclude 'README.md' --exclude 'deploy.sh' --exclude 'prompt.md' --exclude 'var/' --exclude 'server/' \
  ./ "$TARGET"
host="${TARGET%%:*}"
dir="${TARGET#*:}"
ssh "$host" "cd '$dir' && find . -user \"\$(id -un)\" -exec chgrp www-data {} + -exec chmod g+w {} + \
  && for f in *.php lib/*.php lang/*.php; do php -l \"\$f\" >/dev/null || exit 1; done \
  && echo deployed: \$(grep -o \"PBPUT_VERSION', '[0-9.]*\" lib/bootstrap.php | cut -d\"'\" -f3)"
