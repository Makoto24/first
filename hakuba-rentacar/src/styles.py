# -*- coding: utf-8 -*-
"""
白馬レンタカー スタイル定義（v3・完全インライン化）

build.py がこの定義を読み、各要素の style 属性へ !important 付きで直接埋め込みます。

なぜインラインなのか
--------------------
インライン style の !important はCSSカスケードの最上位にあり、
テーマ・プラグイン・CSSの読み込み順に関係なく上書きされません。
また CSS最適化プラグインが <style> を結合・移動・削除しても影響を受けません。

メディアクエリを使わない理由
----------------------------
インライン style にはメディアクエリを書けないため、レスポンシブは
  - clamp() による可変サイズ
  - grid-template-columns: repeat(auto-fit, minmax(Npx, 1fr))
  - flex-wrap: wrap
だけで実現しています。ブレークポイント不要で、CSSが1行も無くても崩れません。
"""

# ── ブランドカラー ────────────────────────────────
GREEN       = "#1a7a3c"
GREEN_DARK  = "#14612f"
GREEN_PALE  = "#f0faf4"
GOLD        = "#fecc01"
GOLD_DARK   = "#b89000"
GOLD_PALE   = "#fff8e1"
INK         = "#111111"
INK_SOFT    = "#2a2a2a"
BODY        = "#3f3f46"
MUTED       = "#767678"
LINE        = "#e8e8e6"
BG          = "#f6f7f9"
WHITE       = "#ffffff"
DANGER      = "#c0392b"
BORDER      = "#e2e2e0"

SHADOW_SM = "0 6px 18px rgba(0,0,0,.05)"
SHADOW    = "0 10px 24px rgba(0,0,0,.08)"

_RESET_HEADING = (
    f"margin:0;padding:0;border:0;background:none;background-color:transparent;"
    f"box-shadow:none;text-shadow:none;text-transform:none;text-align:left;"
    f"font-weight:800;color:{INK};letter-spacing:.02em;line-height:1.4;"
)

# ══════════════════════════════════════════════════
#  タグ別の既定値（テーマの p/li/td/th/a 指定を無効化する）
# ══════════════════════════════════════════════════
TAG_DEFAULTS = {
    "h1": _RESET_HEADING, "h2": _RESET_HEADING, "h3": _RESET_HEADING,
    "h4": _RESET_HEADING, "h5": _RESET_HEADING,
    "p":  f"margin:0 0 1em;color:{BODY};font-size:15.5px;line-height:1.9;text-align:left;",
    "ul": "margin:0 0 1em;padding-left:1.4em;list-style:disc;",
    "ol": "margin:0 0 1em;padding-left:1.5em;list-style:decimal;",
    "li": f"margin:0 0 .45em;color:{BODY};font-size:15px;line-height:1.9;text-align:left;",
    "dl": "margin:0;padding:0;",
    "dt": "margin:0;padding:0;",
    "dd": "margin:0;padding:0;",
    "a":  f"color:{GREEN};text-decoration:none;",
    "strong": f"font-weight:800;color:{INK};",
    "small":  "font-size:12.5px;font-weight:400;",
    "table":  "border-collapse:collapse;width:100%;margin:0;",
    "figure": "margin:0;",
    "figcaption": f"margin:0;font-size:12.5px;color:{MUTED};line-height:1.7;text-align:left;",
    "caption": (f"caption-side:top;text-align:left;font-size:11.5px;font-weight:800;"
                f"color:{MUTED};letter-spacing:.09em;padding:14px 18px 0;"),
    "img": "display:block;max-width:100%;height:auto;",
    "hr":  f"border:0;border-top:1px solid {LINE};margin:0;height:0;",
    "nav": "margin:0;padding:0;",
    "details": (f"background:{WHITE};border:1px solid {BORDER};border-radius:12px;"
                f"margin:0 0 12px;box-shadow:{SHADOW_SM};overflow:hidden;"),
    "summary": (f"display:flex;justify-content:space-between;align-items:flex-start;gap:16px;"
                f"cursor:pointer;padding:20px 22px;font-size:15.5px;font-weight:800;"
                f"color:{INK};line-height:1.75;background:{WHITE};text-align:left;"),
    "iframe": "border:0;display:block;",
    "section": "display:block;margin:0;padding:0;",
    "article": "display:block;",
    "span": "",   # span は個別クラスでのみ指定
}

# ══════════════════════════════════════════════════
#  クラス別スタイル
#  （HTML上の class 属性の並び順に適用される。基本クラス → 修飾クラスの順に書くこと）
# ══════════════════════════════════════════════════
CLASS_STYLES = {
    # ── ルート・レイアウト ──
    "hrc": (f"color:{INK};line-height:1.8;text-align:left;max-width:100%;"
            f"box-sizing:border-box;overflow-wrap:break-word;"),
    "hrc-full": "position:relative;width:auto;left:auto;margin-left:0;",
    "hrc-container": ("max-width:1080px;margin-left:auto;margin-right:auto;"
                      "padding-left:clamp(16px,4vw,24px);padding-right:clamp(16px,4vw,24px);"
                      "box-sizing:border-box;"),
    "hrc-container--narrow": "max-width:840px;",
    "hrc-section": "padding-top:clamp(34px,5vw,52px);padding-bottom:clamp(34px,5vw,52px);",
    "hrc-section--tight": "padding-top:clamp(22px,3vw,32px);padding-bottom:clamp(22px,3vw,32px);",
    "hrc-section--bg": f"background:{BG};border-radius:18px;margin-top:14px;margin-bottom:14px;",
    "hrc-section--white": "background:transparent;",
    "hrc-rule": f"border:0;border-top:1px solid {LINE};margin:0;height:0;",

    # ── 見出し ──
    "hrc-eyebrow": (f"font-size:11px;letter-spacing:.26em;text-transform:uppercase;"
                    f"color:{GOLD_DARK};font-weight:700;margin:0 0 10px;line-height:1.6;"),
    "hrc-h1": ("font-size:clamp(23px,3.4vw,31px);margin:0 0 16px;line-height:1.45;"
               "border:0;padding:0;"),
    "hrc-h2": (f"font-size:clamp(20px,2.6vw,25px);margin:0 0 24px;line-height:1.45;"
               f"padding:0 0 12px;border:0;border-bottom:3px solid {GOLD};display:inline-block;"),
    "hrc-h3": "font-size:clamp(17px,2vw,19px);margin:0 0 14px;line-height:1.5;border:0;padding:0;",
    "hrc-h4": "font-size:16px;margin:0 0 12px;line-height:1.55;border:0;padding:0;",
    "hrc-lead": f"color:{BODY};font-size:15.5px;line-height:2;margin:0 0 28px;max-width:740px;",
    "hrc-center": "text-align:center;",
    "hrc-body-text": f"color:{BODY};font-size:15.5px;line-height:2;margin:0 0 1em;",
    "hrc-muted-text": f"color:{MUTED};font-size:13.5px;line-height:1.9;margin:0 0 1em;",
    "hrc-updated": f"font-size:12.5px;color:{MUTED};margin:0 0 24px;",
    "hrc-sign": f"text-align:right;color:{MUTED};font-size:14px;margin:20px 0 0;",
    "hrc-hint": (f"display:block;font-size:12.5px;color:{MUTED};margin-top:5px;"
                 f"line-height:1.75;font-weight:400;"),
    "hrc-mt24": "margin-top:24px;",

    # ── ボタン ──
    "hrc-btn": ("display:inline-block;font-weight:800;font-size:15px;line-height:1;"
                "padding:15px 26px;border-radius:10px;text-align:center;text-decoration:none;"
                "border:1px solid transparent;cursor:pointer;box-sizing:border-box;"),
    "hrc-btn--gold":  f"background:{GOLD};color:{INK};border-color:rgba(0,0,0,.10);",
    "hrc-btn--green": f"background:{GREEN};color:{WHITE};border-color:{GREEN};",
    "hrc-btn--dark":  f"background:{INK};color:{WHITE};border-color:{INK};",
    "hrc-btn--ghost": f"background:{WHITE};color:{INK};border-color:#b0b0b0;",
    "hrc-btn--sm":    "padding:10px 18px;font-size:13px;border-radius:999px;",
    "hrc-btnrow": "display:flex;flex-wrap:wrap;gap:12px;margin:0;padding:0;list-style:none;",
    "hrc-btnrow--center": "justify-content:center;",

    # ── パンくず ──
    "hrc-crumb": f"font-size:12.5px;color:{MUTED};padding:14px 0;border-bottom:1px solid {LINE};margin:0;",

    # ── バッジ・ピル ──
    "hrc-badgebar": (f"background:{GOLD};display:flex;flex-wrap:wrap;justify-content:center;"
                     f"gap:10px 24px;padding:14px 20px;border-radius:12px;margin:0;"),
    "hrc-pill": (f"display:inline-block;background:{GREEN};color:{WHITE};font-size:11.5px;"
                 f"font-weight:800;letter-spacing:.05em;padding:5px 12px;border-radius:999px;"
                 f"line-height:1.7;"),
    "hrc-pill--gold": f"background:{GOLD};color:{INK};",
    "hrc-pill--dark": f"background:{INK};color:{WHITE};",

    # ── 要点まとめ ──
    "hrc-keyfacts": (f"background:{WHITE};border:2px solid {GREEN};border-radius:16px;"
                     f"padding:clamp(18px,3vw,26px) clamp(16px,3vw,28px);"
                     f"box-shadow:0 12px 28px rgba(26,122,60,.10);margin:0 0 36px;"),
    "hrc-keyfacts-title": (f"font-size:15px;font-weight:800;color:{GREEN};margin:0 0 14px;"
                           f"letter-spacing:.06em;line-height:1.6;border:0;padding:0;"),
    "hrc-kf-list": "list-style:none;margin:0;padding:0;",
    "hrc-kf-item": (f"display:flex;gap:10px;align-items:flex-start;margin:0 0 9px;"
                    f"font-size:14.5px;line-height:1.9;color:{BODY};"),
    "hrc-kf-check": f"color:{GREEN};font-weight:900;font-size:14px;flex:0 0 auto;line-height:1.9;",

    # ── グリッド（メディアクエリ不要の自動折返し） ──
    "hrc-grid": "display:grid;gap:20px;",
    "hrc-grid--2": "grid-template-columns:repeat(auto-fit,minmax(300px,1fr));",
    "hrc-grid--3": "grid-template-columns:repeat(auto-fit,minmax(250px,1fr));",
    "hrc-grid--4": "grid-template-columns:repeat(auto-fit,minmax(210px,1fr));",
    "hrc-links": "display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin:0;padding:0;",
    "hrc-gal": "",

    # ── カード ──
    "hrc-card": (f"background:{WHITE};border:1px solid {BORDER};border-radius:14px;"
                 f"overflow:hidden;box-shadow:{SHADOW_SM};display:flex;flex-direction:column;"),
    "hrc-card__body": "padding:20px;display:flex;flex-direction:column;flex:1 1 auto;",
    "hrc-card__title": "font-size:16.5px;margin:0 0 10px;line-height:1.55;border:0;padding:0;",
    "hrc-card__text": f"color:{BODY};font-size:14.5px;line-height:1.95;flex:1 1 auto;margin:0 0 16px;",

    # ── 店舗カード ──
    "hrc-shop": (f"background:{WHITE};border:1px solid {BORDER};border-radius:14px;"
                 f"overflow:hidden;box-shadow:{SHADOW_SM};display:flex;flex-direction:column;"),
    "hrc-shop__head": f"background:{GREEN};padding:22px 24px;",
    "hrc-shop__name": f"font-size:19px;color:{WHITE};margin:10px 0 0;line-height:1.5;border:0;padding:0;",
    "hrc-shop__sub": "font-size:13.5px;color:#e4f2e9;margin:6px 0 0;line-height:1.7;",
    "hrc-shop__body": "padding:24px;flex:1 1 auto;",
    "hrc-shop__map": (f"position:relative;padding-bottom:62%;height:0;overflow:hidden;"
                      f"border-top:1px solid {LINE};background:#eeeeee;"),

    # ── 定義リスト ──
    "hrc-dl": "margin:0 0 20px;padding:0;",
    "hrc-dl__row": (f"display:flex;flex-wrap:wrap;gap:4px 12px;padding:12px 0;"
                    f"border-bottom:1px solid #efefed;align-items:flex-start;"),
    "hrc-dt": f"flex:0 0 100px;font-size:12px;font-weight:800;color:{MUTED};letter-spacing:.04em;padding-top:3px;line-height:1.8;margin:0;",
    "hrc-dd": f"flex:1 1 220px;margin:0;font-size:14.5px;color:{BODY};line-height:1.9;",

    # ── テーブル ──
    "hrc-tablewrap": (f"overflow-x:auto;border:1px solid {LINE};border-radius:12px;"
                      f"background:{WHITE};box-shadow:0 6px 18px rgba(0,0,0,.04);"
                      f"-webkit-overflow-scrolling:touch;max-width:100%;"),
    "hrc-table": "font-size:14px;min-width:520px;border-collapse:collapse;width:100%;",

    # ── 注意ボックス ──
    "hrc-note": f"border-radius:0 10px 10px 0;padding:18px 22px;font-size:14.5px;line-height:1.95;color:{BODY};",
    "hrc-note--info": f"background:{WHITE};border-left:4px solid {GOLD};box-shadow:0 6px 18px rgba(0,0,0,.04);",
    "hrc-note--warn": f"background:{GOLD_PALE};border-left:4px solid {GOLD};",
    "hrc-note--danger": f"background:#fff5f5;border:2px solid {DANGER};border-radius:10px;",
    "hrc-alert": (f"background:#fff3cd;border:2px solid #f2b600;border-radius:14px;"
                  f"padding:clamp(18px,3vw,24px) clamp(16px,3vw,26px);box-shadow:{SHADOW};"
                  f"max-width:760px;margin:0 auto;text-align:center;"),
    "hrc-alert__title": "font-size:16.5px;color:#8a6200;margin:0 0 10px;line-height:1.6;border:0;padding:0;",

    # ── キャンペーン・CTA ──
    "hrc-topbar": (f"background:{GREEN};padding:14px 22px;border-radius:12px;display:flex;"
                   f"align-items:center;justify-content:center;gap:14px;flex-wrap:wrap;margin:0;"),
    "hrc-campaign": (f"background:{GREEN};border-radius:16px;border-left:5px solid {GOLD};"
                     f"padding:clamp(20px,3vw,26px) clamp(18px,3vw,28px);"
                     f"box-shadow:0 12px 28px rgba(26,122,60,.18);"),
    "hrc-campaign__excl": (f"font-size:13px;background:rgba(0,0,0,.26);padding:12px 16px;"
                           f"border-radius:8px;line-height:1.85;margin:0;color:{WHITE};"),
    "hrc-ctaband": (f"background:{INK};border-radius:16px;border-left:5px solid {GOLD};"
                    f"padding:clamp(20px,3vw,26px) clamp(18px,3vw,28px);box-shadow:{SHADOW};"),

    # ── ステップ ──
    "hrc-step": (f"background:{WHITE};border:1px solid {BORDER};border-radius:14px;"
                 f"padding:24px;box-shadow:0 6px 18px rgba(0,0,0,.04);"),
    "hrc-step__num": (f"display:inline-flex;align-items:center;justify-content:center;"
                      f"width:34px;height:34px;border-radius:50%;background:{GREEN};"
                      f"color:{WHITE};font-weight:800;font-size:15px;margin-bottom:12px;line-height:1;"),
    "hrc-step__title": "font-size:16px;margin:0 0 10px;line-height:1.55;border:0;padding:0;",

    # ── 保険プラン ──
    "hrc-plan": f"background:{BG};border:1px solid {BORDER};border-radius:12px;padding:28px 22px 30px;position:relative;",
    "hrc-plan--best": f"background:{INK};border:2px solid {GOLD};padding-top:46px;",
    "hrc-plan__ribbon": (f"position:absolute;top:0;left:0;right:0;background:{GOLD};text-align:center;"
                         f"font-size:10.5px;font-weight:800;letter-spacing:.14em;color:{INK};"
                         f"padding:7px 0;border-radius:10px 10px 0 0;line-height:1.5;"),
    "hrc-plan__label": f"font-size:10.5px;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:{MUTED};margin:0 0 6px;",
    "hrc-plan__name": "font-size:14px;margin:0 0 16px;line-height:1.55;border:0;padding:0;",
    "hrc-plan__price": f"font-size:30px;font-weight:800;line-height:1.15;margin:0 0 4px;color:{INK};",
    "hrc-plan__row": "display:flex;justify-content:space-between;gap:8px;margin:0 0 9px;font-size:12.5px;",
    "hrc-plan__foot": f"font-size:12px;color:{MUTED};line-height:1.75;margin:8px 0 0;",
    "hrc-yes": f"color:{GREEN};font-weight:800;",
    "hrc-no": f"color:{DANGER};font-weight:800;",

    # ── FAQ ──
    "hrc-faq": "max-width:840px;margin:0 auto;",
    "hrc-faq__a": f"padding:18px 22px 22px;background:{WHITE};border-top:1px solid {LINE};",
    "hrc-faq__mark": f"color:{GREEN};font-size:20px;font-weight:700;line-height:1.4;flex:0 0 auto;",

    # ── 相互リンクカード ──
    "hrc-linkcard": (f"display:block;background:{WHITE};border:1px solid {BORDER};border-radius:12px;"
                     f"padding:16px 18px;font-size:14.5px;font-weight:800;color:{INK};"
                     f"box-shadow:0 6px 18px rgba(0,0,0,.04);text-decoration:none;line-height:1.65;"),
    "hrc-linkcard__sub": f"display:block;font-size:12.5px;font-weight:400;color:{MUTED};margin-top:5px;line-height:1.75;",

    # ── ヒーロー ──
    "hrc-hero": "display:block;margin:0 0 8px;",
    "hrc-hero__img": "width:100%;height:auto;aspect-ratio:16/7;object-fit:cover;border-radius:16px;margin:0 0 26px;",
    "hrc-hero__sub": f"font-size:clamp(15px,1.6vw,16.5px);font-weight:700;color:{INK};line-height:1.9;margin:0 0 18px;",
    "hrc-hero__list": "margin:0 0 24px;padding-left:1.3em;list-style:disc;",
    "hrc-hero__card": (f"background:{WHITE};border:1px solid {BORDER};border-radius:14px;"
                       f"padding:clamp(16px,3vw,22px);box-shadow:{SHADOW};max-width:560px;margin:0;"),

    # ── 画像 ──
    "hrc-figure": "margin:0 0 24px;position:relative;",
    "hrc-photo": "margin:0 0 28px;",

    # ── 注記リスト ──
    "hrc-notelist": "list-style:none;margin:20px 0 32px;padding:0;",
    "hrc-notelist__item": f"font-size:13.5px;color:{BODY};line-height:1.9;margin:0 0 10px;padding-left:14px;text-indent:-14px;",

    # ── 表内の装飾 ──
    "price": f"font-size:15px;font-weight:800;color:{INK};",
    "price-green": f"font-size:16px;font-weight:800;color:{GREEN};",
    "strike": "text-decoration:line-through;color:#ababab;font-size:12.5px;font-weight:400;margin-right:5px;",
    "off": (f"display:inline-block;background:{GREEN};color:{WHITE};font-size:10px;font-weight:700;"
            f"letter-spacing:.06em;padding:3px 7px;border-radius:4px;margin-right:6px;"),
    "dnote": "font-size:11.5px;color:#8e8e91;font-weight:400;margin-right:6px;",
    "unit": "font-size:12px;color:#999999;font-weight:400;",
    "sub": "display:block;margin-top:5px;font-size:10.5px;font-weight:400;letter-spacing:.06em;color:#d9d9d9;",
    "neg": f"color:{DANGER};font-weight:800;",
    "pos": f"color:{GREEN};font-weight:800;",
}

# ══════════════════════════════════════════════════
#  テーブル内の文脈依存スタイル（build.py が構造から適用）
# ══════════════════════════════════════════════════
TABLE = {
    "thead_th":       f"background:{INK};color:{WHITE};font-weight:700;font-size:11.5px;letter-spacing:.09em;padding:14px 18px;text-align:left;vertical-align:bottom;line-height:1.6;border:0;",
    "thead_th_num":   "text-align:right;",
    "thead_th_hl":    f"background:{GREEN};color:{GOLD};text-align:right;",
    "thead_sub_hl":   "color:#d8ecdf;",
    "tbody_th":       f"text-align:left;font-weight:700;padding:14px 18px;border-bottom:1px solid {LINE};color:{INK_SOFT};background:{WHITE};font-size:14px;line-height:1.75;vertical-align:top;",
    "tbody_td":       f"padding:14px 18px;border-bottom:1px solid {LINE};vertical-align:middle;color:{BODY};font-size:14px;background:{WHITE};line-height:1.75;",
    "row_even":       "background:#fbfbfa;",
    "row_last":       "border-bottom:0;",
    "td_num":         f"text-align:right;font-weight:700;color:{INK};white-space:nowrap;",
    "td_hl":          f"text-align:right;background:{GREEN_PALE};white-space:nowrap;",
    "td_hl_even":     "background:#e8f6ee;",
    "td_soft":        f"color:{MUTED};font-weight:600;font-style:italic;",
    "td_small":       "font-size:13px;",
    "foot_td":        f"text-align:center;font-size:13px;color:{MUTED};font-style:italic;background:{WHITE};font-weight:400;",
}

# ══════════════════════════════════════════════════
#  残す <style>（インラインでは表現できないものだけ）
#  この <style> が丸ごと消えても、見た目は崩れません。
# ══════════════════════════════════════════════════
RESIDUAL_CSS = f"""
/* インライン style では表現できない :hover と details の開閉のみ。
   このブロックが失われても、レイアウト・配色は一切崩れません。 */
.hrc a[data-hrc-btn]:hover{{transform:translateY(-2px);box-shadow:{SHADOW};opacity:.95;}}
.hrc a[data-hrc-link]:hover{{transform:translateY(-2px);box-shadow:{SHADOW};}}
.hrc summary::-webkit-details-marker{{display:none;}}
.hrc summary::marker{{content:"";}}
.hrc details[open] [data-hrc-mark]{{visibility:hidden;position:relative;}}
.hrc details[open] [data-hrc-mark]::after{{content:"−";visibility:visible;position:absolute;right:0;top:0;}}
"""
