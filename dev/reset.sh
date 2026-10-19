#!/usr/bin/env bash
set -euo pipefail

COMPOSE="docker compose"
SERVICE="wordpress"
SITE_URL="http://localhost:8080"
ADMIN_USER="admin"
ADMIN_PASS="admin"
ADMIN_EMAIL="admin@example.com"
SITE_TITLE="AntiSpam Wizard Test"

dc_exec() {
  $COMPOSE exec -T "$SERVICE" "$@"
}

wp() {
  dc_exec wp --allow-root "$@"
}

echo "=== AntiSpam Wizard WordPress Reset ==="
echo ""

# Verify WordPress container is running.
echo "Checking WordPress container..."
if ! $COMPOSE ps --status running "$SERVICE" 2>/dev/null | grep -q "$SERVICE"; then
  echo "ERROR: WordPress container is not running."
  echo "Start it first: docker compose up -d wordpress"
  exit 1
fi

# Ensure WP-CLI is installed (same pattern as setup.sh).
echo "Ensuring WP-CLI is installed..."
dc_exec bash -c '
  if ! command -v wp &>/dev/null; then
    curl -fsSO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar || { echo "Failed to download WP-CLI"; exit 1; }
    chmod +x wp-cli.phar
    mv wp-cli.phar /usr/local/bin/wp
  fi
'

# Ensure mysql client is installed (needed by wp db reset).
echo "Ensuring mysql client is installed..."
dc_exec bash -c '
  if ! command -v mysql &>/dev/null; then
    apt-get update -qq && apt-get install -y -qq default-mysql-client >/dev/null
  fi
'

# Wait for database to be ready (same pattern as setup.sh).
echo "Waiting for database connection..."
for i in $(seq 1 30); do
  if dc_exec php -r "
    \$c = @new mysqli('db', 'wpuser', 'wppass', 'wordpress', 3306);
    if (\$c->connect_error) exit(1);
    echo 'ok';
  " 2>/dev/null | grep -q ok; then
    break
  fi
  if [ "$i" -eq 30 ]; then
    echo "ERROR: Could not connect to database."
    exit 1
  fi
  sleep 2
done

# Reset the WordPress database and reinstall.
echo "Resetting WordPress database..."
wp db reset --yes

echo "Reinstalling WordPress..."
wp core install \
  --url="$SITE_URL" \
  --title="$SITE_TITLE" \
  --admin_user="$ADMIN_USER" \
  --admin_password="$ADMIN_PASS" \
  --admin_email="$ADMIN_EMAIL" \
  --skip-email

# Delete all existing posts and comments.
echo "Cleaning up default content..."
wp post delete $(wp post list --post_type=any --format=ids --post_status=any 2>/dev/null) --force 2>/dev/null || true
wp comment delete $(wp comment list --format=ids 2>/dev/null) --force 2>/dev/null || true

# Create one default post with comments open.
echo "Creating default post..."
POST_ID=$(wp post create \
  --post_title="Welcome to AntiSpam Wizard Test" \
  --post_content="This is a test post for AntiSpam Wizard development." \
  --post_status=publish \
  --comment_status=open \
  --porcelain)

# Create one approved comment on that post.
echo "Creating default comment..."
wp comment create \
  --comment_post_ID="$POST_ID" \
  --comment_content="This is a test comment." \
  --comment_author="Test User" \
  --comment_author_email="test@example.com" \
  --comment_approved=1

# Reset training data files to their git-tracked baseline.
# The plugin stores Bayesian training samples in on-disk files under data/,
# which are bind-mounted from the repo and survive database resets.
echo "Restoring training data to git baseline..."
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
git -C "$REPO_DIR" checkout HEAD -- antispam-wizard/data/

# Activate the antispam-wizard plugin (triggers fresh activation with defaults).
echo "Activating antispam-wizard plugin..."
wp plugin activate antispam-wizard

echo ""
echo "=== Reset complete! ==="
echo "  Site:    $SITE_URL"
echo "  Admin:   $SITE_URL/wp-admin"
echo "  Login:   $ADMIN_USER / $ADMIN_PASS"
echo "  Post:    ID $POST_ID (comments open)"
echo "  Comment: 1 approved comment on the post"
