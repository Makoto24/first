// SEOプラグイン向けのメタ情報を、フロントマターから組み立てる。
// 対応プラグインは SEO_PLUGIN 環境変数で切り替え（既定: rankmath）。
//   meta_description … メタディスクリプション
//   seo_title        … 検索結果用タイトル
//   focus_keyword    … フォーカスキーワード
export function getSeoPlugin() {
  return (process.env.SEO_PLUGIN || 'rankmath').toLowerCase();
}

export function buildSeoMeta(meta, plugin = getSeoPlugin()) {
  const title = meta.seo_title;
  const desc = meta.meta_description;
  const kw = meta.focus_keyword;
  const out = {};

  if (plugin === 'rankmath') {
    if (title) out.rank_math_title = title;
    if (desc) out.rank_math_description = desc;
    if (kw) out.rank_math_focus_keyword = kw;
  } else if (plugin === 'seopress') {
    if (title) out._seopress_titles_title = title;
    if (desc) out._seopress_titles_desc = desc;
    if (kw) out._seopress_analysis_target_kw = kw;
  } else if (plugin === 'yoast') {
    // Yoast の保護メタは REST で書き込めない場合があります（抜粋でフォールバック）。
    if (title) out._yoast_wpseo_title = title;
    if (desc) out._yoast_wpseo_metadesc = desc;
    if (kw) out._yoast_wpseo_focuskw = kw;
  }
  return out;
}
