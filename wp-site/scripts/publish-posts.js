// content/posts 配下のブログ記事を WordPress に作成 / 更新する
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { WpClient } from '../lib/wpClient.js';
import { loadContentDir } from '../lib/content.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const postsDir = join(__dirname, '..', 'content', 'posts');

const dryRun = process.argv.includes('--dry-run');

async function main() {
  const items = loadContentDir(postsDir);
  if (!items.length) {
    console.log('content/posts に記事がありません。');
    return;
  }

  // dry-run は認証情報なしでも内容確認できるよう、クライアントを作らない
  const wp = dryRun ? null : new WpClient();
  console.log(`接続先: ${dryRun ? '（DRY RUN）' : wp.baseUrl}\n`);

  // カテゴリ名 → ID のキャッシュ
  const catCache = new Map();
  async function resolveCategories(names) {
    const ids = [];
    for (const name of names) {
      if (!catCache.has(name)) {
        catCache.set(name, await wp.ensureCategory(name));
      }
      ids.push(catCache.get(name));
    }
    return ids;
  }

  for (const { file, meta, body } of items) {
    if (!meta.slug || !meta.title) {
      console.warn(`  スキップ: ${file}（title または slug が未設定）`);
      continue;
    }

    const payload = {
      title: meta.title,
      slug: meta.slug,
      status: meta.status || 'publish',
      content: body,
    };
    if (meta.excerpt) payload.excerpt = meta.excerpt;
    if (meta.date) payload.date = meta.date; // 予約投稿は status: future + 未来日時

    if (dryRun) {
      console.log(`  [dry-run] ${meta.title}  /${meta.slug}  (${body.length}文字)`);
      continue;
    }

    if (meta.categories) {
      const names = meta.categories.split(',').map((s) => s.trim()).filter(Boolean);
      if (names.length) payload.categories = await resolveCategories(names);
    }

    try {
      const { action, result } = await wp.upsertPost(payload);
      console.log(`  ${action === 'created' ? '作成' : '更新'}: ${meta.title}  → ${result.link}`);
    } catch (e) {
      console.error(`  失敗: ${meta.title}  ${e.message}`);
    }
  }
}

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
