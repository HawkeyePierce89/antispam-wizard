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

echo "Waiting for WordPress container to be ready..."
for i in $(seq 1 60); do
  if dc_exec php -r "echo 'ok';" 2>/dev/null | grep -q ok; then
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "ERROR: WordPress container did not become ready in time."
    echo "Make sure you ran: docker compose up -d wordpress"
    exit 1
  fi
  sleep 2
done

echo "Installing WP-CLI in the container..."
dc_exec bash -c '
  if ! command -v wp &>/dev/null; then
    curl -fsSO https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar || { echo "Failed to download WP-CLI"; exit 1; }
    chmod +x wp-cli.phar
    mv wp-cli.phar /usr/local/bin/wp
  fi
'

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

if wp core is-installed &>/dev/null; then
  echo "WordPress is already installed, skipping installation."
else
  echo "Installing WordPress..."
  wp core install \
    --url="$SITE_URL" \
    --title="$SITE_TITLE" \
    --admin_user="$ADMIN_USER" \
    --admin_password="$ADMIN_PASS" \
    --admin_email="$ADMIN_EMAIL" \
    --skip-email
fi

echo "Activating antispam-wizard plugin..."
wp plugin activate antispam-wizard

echo "Enabling comments on the default post..."
POST_ID=$(wp post list --post_type=post --posts_per_page=1 --format=ids 2>/dev/null)
if [ -n "$POST_ID" ]; then
  wp post update "$POST_ID" --comment_status=open
else
  echo "WARNING: No posts found. Create a post and enable comments manually."
fi

echo ""
echo "Setup complete!"
echo "  Site:   $SITE_URL"
echo "  Admin:  $SITE_URL/wp-admin"
echo "  Login:  $ADMIN_USER / $ADMIN_PASS"
