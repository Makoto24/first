// 既存サイトの接続確認と、固定ページ・投稿の一覧表示
import { WpClient } from '../lib/wpClient.js';

async function main() {
  const wp = new WpClient();

  console.log(`接続先: ${wp.baseUrl}`);
  try {
    const me = await wp.verify();
    console.log(`認証OK: ${me.name}（権限: ${(me.roles || []).join(', ')}）\n`);
  } catch (e) {
    console.error(`認証に失敗しました: ${e.message}`);
    console.error('WP_BASE_URL / WP_USERNAME / WP_APP_PASSWORD を確認してください。');
    process.exit(1);
  }

  const pages = await wp.request(
    'GET',
    '/pages?per_page=100&status=publish,draft&context=edit&orderby=menu_order&order=asc'
  );
  console.log(`■ 固定ページ（${pages.length}件）`);
  for (const p of pages) {
    console.log(
      `  [${p.status}] ${p.title.rendered || p.title.raw}  /${p.slug}  → ${p.link}`
    );
  }

  const posts = await wp.request(
    'GET',
    '/posts?per_page=100&status=publish,draft,future&context=edit'
  );
  console.log(`\n■ 投稿（${posts.length}件）`);
  for (const p of posts) {
    console.log(
      `  [${p.status}] ${p.title.rendered || p.title.raw}  /${p.slug}  → ${p.link}`
    );
  }
}

main().catch((e) => {
  console.error(e.message);
  process.exit(1);
});
