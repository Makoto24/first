#!/usr/bin/env bash
# 配布用 zip（vendor 同梱）を作る。WordPress の「プラグインのアップロード」でそのままインストールできる。
#   使い方: bash rentacar-ai-concierge/bin/build-zip.sh
#   出力:   dist/rentacar-ai-concierge.zip
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="$(basename "$PLUGIN_DIR")"
ROOT="$(cd "$PLUGIN_DIR/.." && pwd)"
BUILD="$(mktemp -d)"
trap 'rm -rf "$BUILD"' EXIT

mkdir -p "$BUILD/$SLUG"
tar -C "$PLUGIN_DIR" \
  --exclude ./vendor --exclude ./bin --exclude ./tests --exclude './.git*' --exclude '*.zip' \
  -cf - . | tar -C "$BUILD/$SLUG" -xf -

(cd "$BUILD/$SLUG" && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress)

# 実行に不要なファイルを除いてサイズを抑える
find "$BUILD/$SLUG/vendor" -type d \( -name .git -o -name .github -o -name tests -o -name Tests -o -name docs -o -name examples \) -prune -exec rm -rf {} +
find "$BUILD/$SLUG/vendor" -type f \( -name '*.md' -o -name 'phpunit.xml*' -o -name '.php-cs-fixer*' -o -name 'phpstan*' \) -delete
rm -f "$BUILD/$SLUG/composer.lock"

mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/$SLUG.zip"
(cd "$BUILD" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG")
echo "作成しました: $ROOT/dist/$SLUG.zip ($(du -h "$ROOT/dist/$SLUG.zip" | cut -f1))"
