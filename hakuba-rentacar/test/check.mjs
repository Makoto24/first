import { chromium } from 'playwright';
import fs from 'fs';

const theme = fs.readFileSync('theme.css', 'utf8');
const pages = process.argv.slice(2);
const browser = await chromium.launch({executablePath:'/opt/pw-browsers/chromium-1194/chrome-linux/chrome'});
let fail = 0;

for (const p of pages) {
  const body = fs.readFileSync(p, 'utf8');
  // WordPressのページを模した最小のホスト文書（テーマCSSを先に読み込む）
  const doc = `<!doctype html><html lang="ja"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>${theme}</style><style>body{margin:0}.entry-content{max-width:1140px;margin:0 auto}</style>
    </head><body><div class="entry-content">${body}</div></body></html>`;

  const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
  await page.route('**/*', r => {
    const u = r.request().url();
    if (u.startsWith('data:') || u.startsWith('about:')) return r.continue();
    if (r.request().resourceType() === 'document') return r.continue();
    return r.abort();               // 外部画像/地図は読み込まない
  });
  await page.setContent(doc, { waitUntil: 'domcontentloaded' });

  const r = await page.evaluate(() => {
    const css = (sel, prop) => {
      const el = document.querySelector(sel);
      return el ? getComputedStyle(el)[prop] : null;
    };
    return {
      campaignBg : css('.hrc-campaign', 'backgroundColor'),
      campaignFg : css('.hrc-campaign p', 'color'),
      theadBg    : css('.hrc-table thead th', 'backgroundColor'),
      theadFg    : css('.hrc-table thead th', 'color'),
      hlBg       : css('.hrc-table thead th.hl', 'backgroundColor'),
      keyfactsBd : css('.hrc-keyfacts', 'borderTopColor'),
      h2Border   : css('.hrc-h2', 'borderBottomColor'),
      h1Size     : css('.hrc-h1', 'fontSize'),
      btnBg      : css('.hrc-btn--gold', 'backgroundColor'),
      ctabandBg  : css('.hrc-ctaband', 'backgroundColor'),
      pillBg     : css('.hrc-pill', 'backgroundColor'),
      stepNumBg  : css('.hrc-step__num', 'backgroundColor'),
      tbodyThFg  : css('.hrc-table tbody th', 'color'),
      faqSumFg   : css('.hrc-faq summary', 'color'),
      overflowPx : document.documentElement.scrollWidth - document.documentElement.clientWidth,
      varUsed    : /var\(--hrc/.test(document.documentElement.innerHTML),
    };
  });

  const name = p.split('/').slice(-2).join('/');
  const problems = [];
  const clear = v => v === 'rgba(0, 0, 0, 0)' || v === 'transparent';
  // null = そのページに要素が存在しない → 判定対象外
  const expect = (v, want, label) => { if (v !== null && v !== want) problems.push(`${label}=${v}`); };
  if (r.campaignBg !== null && clear(r.campaignBg)) problems.push('campaign背景が透明');
  if (r.theadBg   !== null && clear(r.theadBg))     problems.push('表ヘッダ背景が透明');
  if (r.ctabandBg !== null && clear(r.ctabandBg))   problems.push('CTA帯背景が透明');
  if (r.stepNumBg !== null && clear(r.stepNumBg))   problems.push('ステップ番号背景が透明');
  if (r.pillBg    !== null && clear(r.pillBg))      problems.push('ピル背景が透明');
  expect(r.theadFg,   'rgb(255, 255, 255)', '表ヘッダ文字色');
  expect(r.btnBg,     'rgb(254, 204, 1)',   'ゴールドボタン背景');
  expect(r.h2Border,  'rgb(254, 204, 1)',   'h2下線');
  expect(r.keyfactsBd,'rgb(26, 122, 60)',   '要点ボックス枠');
  expect(r.tbodyThFg, 'rgb(42, 42, 42)',    '表の行見出し色');
  expect(r.faqSumFg,  'rgb(17, 17, 17)',    'FAQ見出し色');
  if (r.overflowPx > 0) problems.push(`横スクロール ${r.overflowPx}px`);
  if (r.varUsed) problems.push('var(--hrc-*) が残存');

  console.log(`${problems.length ? 'NG' : 'OK'}  ${name}`);
  if (problems.length) { fail++; console.log('      → ' + problems.join(' / ')); }
  await page.close();
}
await browser.close();
console.log(fail ? `\n${fail} 件に問題あり` : '\n全ページ問題なし');
process.exit(fail ? 1 : 0);
