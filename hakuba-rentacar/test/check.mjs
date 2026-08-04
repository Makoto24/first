import { chromium } from 'playwright';
import fs from 'fs';

const theme = fs.readFileSync(new URL('./theme.css', import.meta.url), 'utf8');
const files = process.argv.slice(2);
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
let fail = 0;

for (const f of files) {
  let body = fs.readFileSync(f, 'utf8');
  // 残った <style> を丸ごと削除 —— それでも崩れないことを確認する
  body = body.replace(/<style>[\s\S]*?<\/style>/g, '');

  // テーマCSSは本文より「後」に置く。!important 同士なら後勝ちになる最悪条件。
  const doc = `<!doctype html><html lang="ja"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"></head>
    <body style="margin:0"><div class="entry-content" style="max-width:1140px;margin:0 auto">${body}</div>
    <style>${theme}</style></body></html>`;

  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.route('**/*', r => {
    const t = r.request().resourceType();
    if (t === 'document') return r.continue();
    if (t === 'image') return r.fulfill({ status: 200, contentType: 'image/svg+xml',
      body: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="9"><rect width="16" height="9" fill="#d5d8dc"/></svg>' });
    return r.abort();
  });
  await page.setContent(doc, { waitUntil: 'domcontentloaded' });

  const r = await page.evaluate(() => {
    const g = (sel, prop) => { const el = document.querySelector(sel); return el ? getComputedStyle(el)[prop] : null; };
    return {
      campaignBg: g('.hrc-campaign', 'backgroundColor'),
      campaignFg: g('.hrc-campaign p', 'color'),
      ctabandBg:  g('.hrc-ctaband', 'backgroundColor'),
      topbarBg:   g('.hrc-topbar', 'backgroundColor'),
      alertBg:    g('.hrc-alert', 'backgroundColor'),
      keyfactsBd: g('.hrc-keyfacts', 'borderTopColor'),
      keyfactsBg: g('.hrc-keyfacts', 'backgroundColor'),
      theadBg:    g('.hrc-table thead th', 'backgroundColor'),
      theadFg:    g('.hrc-table thead th', 'color'),
      hlBg:       g('.hrc-table thead th.hl', 'backgroundColor'),
      tdBorder:   g('.hrc-table tbody td', 'borderBottomColor'),
      btnBg:      g('a.hrc-btn.hrc-btn--gold:not(.hrc-btn--sm)', 'backgroundColor'),
      btnPadTop:  g('a.hrc-btn.hrc-btn--gold:not(.hrc-btn--sm)', 'paddingTop'),
      btnDisplay: g('a.hrc-btn.hrc-btn--gold:not(.hrc-btn--sm)', 'display'),
      btnRadius:  g('a.hrc-btn.hrc-btn--gold:not(.hrc-btn--sm)', 'borderTopLeftRadius'),
      h1Size:     g('.hrc-h1', 'fontSize'),
      h1BdTop:    g('.hrc-h1', 'borderTopWidth'),
      h2Bd:       g('.hrc-h2', 'borderBottomColor'),
      cardBg:     g('.hrc-card', 'backgroundColor'),
      stepNumBg:  g('.hrc-step__num', 'backgroundColor'),
      shopHeadBg: g('.hrc-shop__head', 'backgroundColor'),
      pillBg:     g('.hrc-pill', 'backgroundColor'),
      gridCols:   g('.hrc-grid--4', 'gridTemplateColumns'),
      linkBg:     g('nav.hrc-links a', 'backgroundColor'),
      overflow:   document.documentElement.scrollWidth - document.documentElement.clientWidth,
    };
  });

  const clear = v => v === 'rgba(0, 0, 0, 0)' || v === 'transparent';
  const p = [];
  const bg = (v, label) => { if (v !== null && clear(v)) p.push(`${label}の背景が消えた`); };
  const eq = (v, want, label) => { if (v !== null && v !== want) p.push(`${label}=${v}`); };

  bg(r.campaignBg, 'キャンペーン'); bg(r.ctabandBg, 'CTA帯'); bg(r.topbarBg, '告知バー');
  bg(r.alertBg, 'アラート');       bg(r.keyfactsBg, '要点');   bg(r.theadBg, '表ヘッダ');
  bg(r.cardBg, 'カード');          bg(r.stepNumBg, 'ステップ番号');
  bg(r.shopHeadBg, '店舗ヘッダ');  bg(r.pillBg, 'ピル');       bg(r.linkBg, '関連リンク');
  bg(r.btnBg, 'ボタン');
  eq(r.theadFg,   'rgb(255, 255, 255)', '表ヘッダ文字色');
  eq(r.campaignFg,'rgb(255, 255, 255)', 'キャンペーン文字色');
  eq(r.btnBg,     'rgb(254, 204, 1)',   'ゴールドボタン背景');
  eq(r.btnPadTop, '15px',               'ボタン上余白');
  // flex の子は display が block に正規化される（仕様どおり）ため両方許容
  if (r.btnDisplay !== null && !['inline-block','block'].includes(r.btnDisplay))
    p.push(`ボタンdisplay=${r.btnDisplay}`);
  eq(r.btnRadius, '10px',               'ボタン角丸');
  eq(r.h2Bd,      'rgb(254, 204, 1)',   'h2下線');
  eq(r.h1BdTop,   '0px',                'h1の上線(テーマ由来)');
  eq(r.keyfactsBd,'rgb(26, 122, 60)',   '要点の枠');
  eq(r.tdBorder,  'rgb(232, 232, 230)', '表の罫線');
  if (r.gridCols && r.gridCols.split(' ').length < 2) p.push(`グリッド段組=${r.gridCols}`);
  if (r.overflow > 0) p.push(`横スクロール ${r.overflow}px`);

  const name = f.split('/').slice(-2).join('/');
  console.log(`${p.length ? 'NG' : 'OK'}  ${name}`);
  if (p.length) { fail++; p.forEach(x => console.log('        → ' + x)); }
  await page.close();
}
await browser.close();
console.log(fail ? `\n${fail} 件に問題あり` : '\n全ページ、敵対的CSS下でも崩れなし');
process.exit(fail ? 1 : 0);
