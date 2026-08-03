// content/pages 配下の固定ページを WordPress に作成 / 更新する
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { WpClient } from '../lib/wpClient.js';
import { loadContentDir } from '../lib/content.js';
import { buildSeoMeta, getSeoPlugin } from '../lib/seo.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const pagesDir = join(__dirname, '..', 'content', 'pages');

// --dry-run で実際の送信をせず内容だけ確認できる
const dryRun = process.argv.includes('--dry-run');

async function main() {
  const items = loadContentDir(pagesDir);
  if (!items.length) {
    console.log('content/pages にページがありません。');
    return;
  }

  // dry-run は認証情報なしでも内容確認できるよう、クライアントを作らない
  const wp = dryRun ? null : new WpClient();
  console.log(`接続先: ${dryRun ? '（DRY RUN）' : wp.baseUrl}　SEO: ${getSeoPlugin()}\n`);

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
    if (meta.menu_order) payload.menu_order = Number(meta.menu_order);
    if (meta.template) payload.template = meta.template;

    // SEO: 抜粋（メタディスクリプションのフォールバック）と SEO プラグインのメタ
    if (meta.meta_description) payload.excerpt = meta.meta_description;
    const seoMeta = buildSeoMeta(meta);
    if (Object.keys(seoMeta).length) payload.meta = seoMeta;

    if (dryRun) {
      const seo = meta.meta_description ? ' +SEO' : '';
      console.log(`  [dry-run] ${meta.title}  /${meta.slug}  (${body.length}文字)${seo}`);
      continue;
    }

    try {
      const { action, result } = await wp.upsertPage(payload);
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
