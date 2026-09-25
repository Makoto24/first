#!/bin/sh
# プラグインのZipを作る。
# ファイル名にバージョンを入れて、PCに保存したときに前の版を上書きしないようにする。
#   例： bv-rental-manager-1.33.1.zip
#
# Zipの中のフォルダ名（bv-rental-manager/）は変えないこと。
# WordPressはこのフォルダ名でプラグインを識別するため、ここにバージョンを入れると
# 更新ではなく「別のプラグイン」として二重にインストールされてしまう。
#
# 使い方： sh build.sh [出力先ディレクトリ]   （既定は ./dist）

set -e
cd "$(dirname "$0")"
out="${1:-dist}"
mkdir -p "$out"

for dir in bv-rental-manager bv-booking-form; do
	main="$dir/$dir.php"
	[ -f "$main" ] || { echo "見つかりません: $main"; exit 1; }

	# プラグインヘッダーの Version: から版を読む
	ver=$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9][0-9.]*\).*/\1/p' "$main" | head -1)
	[ -n "$ver" ] || { echo "バージョンを読み取れません: $main"; exit 1; }

	zipfile="$out/$dir-$ver.zip"
	rm -f "$zipfile"
	zip -rq "$zipfile" "$dir" -x '*.DS_Store' '*/.*'
	echo "$zipfile"
done
