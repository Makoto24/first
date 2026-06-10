// content/ 配下の Markdown(風) ファイルを読み込むユーティリティ
// フォーマット:
//   ---
//   title: ページタイトル
//   slug: page-slug
//   status: publish        # publish | draft
//   menu_order: 1          # 固定ページの並び順（任意）
//   categories: お知らせ    # 投稿のカテゴリ（任意・カンマ区切り）
//   ---
//   <本文 HTML>
//
// 本文は WordPress がそのまま受け付ける HTML として扱う。
import { readFileSync, readdirSync } from 'node:fs';
import { join, basename } from 'node:path';

export function parseFrontmatter(raw) {
  const text = raw.replace(/^﻿/, ''); // BOM除去
  const match = text.match(/^---\s*\n([\s\S]*?)\n---\s*\n?([\s\S]*)$/);
  if (!match) {
    return { meta: {}, body: text.trim() };
  }
  const meta = {};
  for (const line of match[1].split('\n')) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const idx = trimmed.indexOf(':');
    if (idx === -1) continue;
    const key = trimmed.slice(0, idx).trim();
    let value = trimmed.slice(idx + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    meta[key] = value;
  }
  return { meta, body: match[2].trim() };
}

export function loadContentDir(dir) {
  const files = readdirSync(dir)
    .filter((f) => f.endsWith('.md') || f.endsWith('.html'))
    .sort();

  return files.map((file) => {
    const raw = readFileSync(join(dir, file), 'utf8');
    const { meta, body } = parseFrontmatter(raw);
    return {
      file: basename(file),
      meta,
      body,
    };
  });
}
