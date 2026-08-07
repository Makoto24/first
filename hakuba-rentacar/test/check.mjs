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
      heroBg:     g('.hrc-hero', 'backgroundColor'),
      heroTitleFg:g('.hrc-hero__title', 'color'),
      heroScrim:  g('.hrc-hero__inner', 'zIndex'),
      statBg:     g('.hrc-stats > div', 'backgroundColor'),
      statFg:     g('.hrc-stat__value', 'color'),
      overflow:   document.documentElement.scrollWidth - document.documentElement.clientWidth,

      // 中央寄せ指定の中身が、本当に箱ごと中央に来ているか。
      // text-align だけだと max-width を持つ要素が左端に残り、
      // 文字だけ箱の中で中央になってページ全体では左にずれる。
      offCenter: [...document.querySelectorAll('.hrc-center')].flatMap(box => {
        const b = box.getBoundingClientRect();
        return [...box.querySelectorAll('p,h1,h2,h3')]
          .filter(el => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && Math.abs((r.left + r.right) / 2 - (b.left + b.right) / 2) > 2;
          })
          .map(el => el.className || el.tagName);
      }),

      // 強調列のセルが行ごとに寄せ方を変えていないか（偶数行だけ左寄せになる不具合）
      hlAlign: [...new Set([...document.querySelectorAll('.hrc-table tbody td.hl')]
        .map(td => getComputedStyle(td).textAlign))],

      // クラスなしの見出しが本文と密着していないか
      bareHeadGap: [...document.querySelectorAll('h1,h2,h3,h4,h5')]
        .filter(h => !/\bhrc-/.test(h.className || '') &&
                     parseFloat(getComputedStyle(h).marginBottom) < 8).length,

      // hrc-grid--N が本当に N 列で収まっているか。
      // minmax の下限を上げすぎると4枚目だけ次の行に落ちて不揃いに見える。
      gridShort: [...document.querySelectorAll('[class*="hrc-grid--"]')]
        .map(g => {
          const want = +g.className.match(/hrc-grid--(\d)/)[1];
          const cols = getComputedStyle(g).gridTemplateColumns.split(' ').length;
          const items = g.children.length;
          return (items >= want && cols < want) ? `${g.className.match(/hrc-grid--\d/)[0]}=${cols}列` : null;
        }).filter(Boolean),

      // 暗い面に暗い文字が残っていないか。
      // 反転処理は p/li/見出し が対象なので、素の <span> が黒いまま取り残されやすい。
      lowContrast: (() => {
        const lum = c => {
          const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number)
            .map(v => { v /= 255; return v <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; });
          return .2126 * r + .7152 * g + .0722 * b;
        };
        // 半透明の背景は下の色と合成する。不透明として扱うと
        // 「薄いゴールドの上のゴールド文字」を読めないと誤判定してしまう。
        const rgba = c => {
          const v = (c.match(/[\d.]+/g) || [255, 255, 255]).map(Number);
          return [v[0], v[1], v[2], v.length > 3 ? v[3] : 1];
        };
        const solidBg = el => {
          const layers = [];
          for (let n = el; n && n !== document.body; n = n.parentElement) {
            const [r, g, b, a] = rgba(getComputedStyle(n).backgroundColor);
            if (a === 0) continue;
            layers.push([r, g, b, a]);
            if (a >= 1) break;
          }
          let out = [255, 255, 255];                    // 一番下は白地とみなす
          for (const [r, g, b, a] of layers.reverse())  // 下から順に重ねる
            out = out.map((base, i) => [r, g, b][i] * a + base * (1 - a));
          return `rgb(${out.join(',')})`;
        };
        const bad = [];
        for (const box of document.querySelectorAll(
          '.hrc-plan--best, .hrc-campaign, .hrc-ctaband, .hrc-shop__head, .hrc-topbar')) {
          for (const el of box.querySelectorAll('*')) {
            const t = (el.textContent || '').trim();
            if (!t || el.children.length) continue;       // 文字を直接持つ要素だけ見る
            const a = lum(getComputedStyle(el).color), b = lum(solidBg(el));
            const ratio = (Math.max(a, b) + .05) / (Math.min(a, b) + .05);
            if (ratio < 3) bad.push(`${t.slice(0, 12)}(比${ratio.toFixed(1)}:1)`);
          }
        }
        return bad;
      })(),

      // 縦 flex の直下に置いたボタンが横いっぱいに伸びていないか
      stretchedBtn: [...document.querySelectorAll('a.hrc-btn')]
        .filter(a => !/hrc-btnrow/.test(a.parentElement?.className || '') &&
                     a.getBoundingClientRect().width >
                       a.parentElement.getBoundingClientRect().width * 0.9).length,
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
  bg(r.btnBg, 'ボタン'); bg(r.heroBg, 'ヒーロー'); bg(r.statBg, '実績ストリップ');
  eq(r.heroTitleFg, 'rgb(22, 24, 29)',    'ヒーロー見出し色');  // 明るいヒーロー＝濃い文字
  eq(r.heroScrim,   '2',                  'ヒーロー本文の重ね順');
  eq(r.statFg,      'rgb(22, 24, 29)',    '実績の数値色');
  // 期待値は src/styles.py と一致させること
  eq(r.theadFg,   'rgb(255, 255, 255)',        '表ヘッダ文字色');
  eq(r.campaignFg,'rgba(255, 255, 255, 0.86)', 'キャンペーン文字色');
  eq(r.btnBg,     'rgb(254, 204, 1)',          'ゴールドボタン背景');
  eq(r.btnPadTop, '17px',                      'ボタン上余白');
  // flex の子は display が block に正規化される（仕様どおり）ため両方許容
  if (r.btnDisplay !== null && !['inline-block','block'].includes(r.btnDisplay))
    p.push(`ボタンdisplay=${r.btnDisplay}`);
  eq(r.btnRadius, '4px',                       'ボタン角丸');
  eq(r.h2Bd,      'rgb(254, 204, 1)',          'h2下線');
  eq(r.h1BdTop,   '0px',                       'h1の上線(テーマ由来)');
  eq(r.keyfactsBd,'rgb(26, 122, 60)',          '要点の上罫');
  eq(r.tdBorder,  'rgb(240, 240, 236)',        '表の罫線');
  if (r.gridCols && r.gridCols.split(' ').length < 2) p.push(`グリッド段組=${r.gridCols}`);
  if (r.overflow > 0) p.push(`横スクロール ${r.overflow}px`);
  if (r.offCenter.length) p.push(`中央寄せがずれている: ${[...new Set(r.offCenter)].join(', ')}`);
  if (r.hlAlign.length > 1) p.push(`強調列の寄せが行ごとに違う: ${r.hlAlign.join(' / ')}`);
  if (r.bareHeadGap) p.push(`下余白のない見出しが${r.bareHeadGap}個（本文と密着する）`);
  if (r.stretchedBtn) p.push(`横いっぱいに伸びたボタンが${r.stretchedBtn}個`);
  if (r.gridShort.length) p.push(`グリッドが規定の列数に届かない: ${r.gridShort.join(', ')}`);
  if (r.lowContrast.length) p.push(`暗い面で読めない文字: ${[...new Set(r.lowContrast)].slice(0, 6).join(', ')}`);

  // ── 第2パス：残置 <style> を残したまま描画し、擬似要素の打ち消しを検証する ──
  // テーマの見出し装飾は ::before/::after で入るため style 属性では消せない。
  // 消せているのは残置 <style> の打ち消し規則だけなので、そこだけ別に確かめる。
  const doc2 = `<!doctype html><html lang="ja"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1"></head>
    <body style="margin:0"><div class="entry-content" style="max-width:1140px;margin:0 auto">${
      fs.readFileSync(f, 'utf8')}</div>
    <style>${theme}</style></body></html>`;
  await page.setContent(doc2, { waitUntil: 'domcontentloaded' });

  const q = await page.evaluate(() => {
    const pseudo = (sel, which, prop) => {
      const el = document.querySelector(sel);
      return el ? getComputedStyle(el, which)[prop] : null;
    };
    const gone = v => v === null || v === 'none' || v === 'normal';
    const bad = [];
    for (const sel of ['.hrc-h2', '.hrc-h3', '.hrc-h1', '.hrc-keyfacts .hrc-kf-item']) {
      for (const which of ['::before', '::after']) {
        if (!gone(pseudo(sel, which, 'content'))) bad.push(`${sel}${which} が残っている`);
        else if (!gone(pseudo(sel, which, 'display'))) bad.push(`${sel}${which} の箱が残っている`);
      }
    }
    // 自前の擬似要素（FAQ の開閉マーク）まで巻き込んでいないか
    const d = document.querySelector('.hrc-faq details');
    if (d) {
      d.open = true;
      const m = d.querySelector('[data-hrc-mark]');
      if (m && getComputedStyle(m, '::after').content === 'none')
        bad.push('FAQ開閉マークまで消えている');
    }
    return bad;
  });
  p.push(...q);

  const name = f.split('/').slice(-2).join('/');
  console.log(`${p.length ? 'NG' : 'OK'}  ${name}`);
  if (p.length) { fail++; p.forEach(x => console.log('        → ' + x)); }
  await page.close();
}
await browser.close();
console.log(fail ? `\n${fail} 件に問題あり` : '\n全ページ、敵対的CSS下でも崩れなし');
process.exit(fail ? 1 : 0);
