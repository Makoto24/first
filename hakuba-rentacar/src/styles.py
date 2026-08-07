# -*- coding: utf-8 -*-
"""
白馬レンタカー デザインシステム v4

build.py がこの定義を読み、各要素の style 属性へ !important 付きで埋め込みます。

設計方針
--------
1. インライン style の !important でテーマ干渉を完全に排除
2. メディアクエリを使わない（clamp / auto-fit グリッド / flex-wrap だけで可変）
3. コンテナ幅は 1120px に統一。長文だけ 760px の「読みやすい行長」に落とす
4. 余白とタイプスケールを1つの尺度に揃える
"""

# ── カラーパレット ────────────────────────────────
GREEN       = "#1a7a3c"   # ブランドグリーン
GREEN_DEEP  = "#0f5a2b"   # 面で使う濃いグリーン
GREEN_DARK  = "#125c2c"
GREEN_PALE  = "#eef8f2"
GOLD        = "#fecc01"   # ブランドゴールド
GOLD_DEEP   = "#a37f00"
GOLD_PALE   = "#fffaeb"
INK         = "#16181d"   # 見出し・強調
INK_MID     = "#2c2f36"
BODY        = "#4a4d55"   # 本文
MUTED       = "#7c8089"   # 補足
LINE        = "#e6e6e2"   # 罫線
LINE_SOFT   = "#f0f0ec"
BG          = "#f7f7f4"   # セクション背景
BG_DEEP     = "#101319"   # ダークセクション
WHITE       = "#ffffff"
DANGER      = "#c0392b"

SH_XS = "0 2px 6px rgba(20,22,26,.04)"
SH_SM = "0 4px 16px rgba(20,22,26,.06)"
SH    = "0 12px 32px rgba(20,22,26,.09)"
SH_LG = "0 24px 60px rgba(20,22,26,.14)"

_H = (f"margin:0;padding:0;border:0;background:none;background-color:transparent;"
      f"box-shadow:none;text-shadow:none;text-transform:none;text-align:left;"
      f"color:{INK};letter-spacing:.01em;font-feature-settings:'palt';")

# ══════════════════════════════════════════════════
#  タグ既定値
# ══════════════════════════════════════════════════
TAG_DEFAULTS = {
    # クラスを付けない見出しでも成立するよう、既定でサイズと下余白を持たせる。
    # margin:0 のままだと CTA帯のような素の <h3> が本文と密着する。
    # hrc-h1〜hrc-h4 は後から上書きするので影響しない。
    "h1": _H + "font-weight:800;line-height:1.35;font-size:clamp(27px,4.2vw,42px);margin:0 0 20px;",
    "h2": _H + "font-weight:800;line-height:1.4;font-size:clamp(21px,3vw,30px);margin:0 0 18px;",
    "h3": _H + "font-weight:800;line-height:1.5;font-size:clamp(17px,2.1vw,21px);margin:0 0 14px;",
    "h4": _H + "font-weight:700;line-height:1.55;font-size:16px;margin:0 0 12px;",
    "h5": _H + "font-weight:700;line-height:1.6;font-size:14.5px;margin:0 0 10px;",
    "p":  f"margin:0 0 1.15em;color:{BODY};font-size:15.5px;line-height:2;text-align:left;letter-spacing:.01em;",
    "ul": "margin:0 0 1.15em;padding-left:1.35em;list-style:disc;",
    "ol": "margin:0 0 1.15em;padding-left:1.45em;list-style:decimal;",
    "li": f"margin:0 0 .5em;color:{BODY};font-size:15px;line-height:2;text-align:left;",
    "dl": "margin:0;padding:0;",
    "a":  f"color:{GREEN};text-decoration:none;",
    "strong": f"font-weight:700;color:{INK};",
    "small":  "font-size:12.5px;font-weight:400;",
    "table":  "border-collapse:collapse;width:100%;margin:0;",
    "figure": "margin:0;",
    "figcaption": f"margin:0;font-size:12.5px;color:{MUTED};line-height:1.75;text-align:left;",
    "caption": (f"caption-side:top;text-align:left;font-size:11px;font-weight:700;"
                f"color:{MUTED};letter-spacing:.14em;padding:16px 20px 2px;"),
    "img": "display:block;max-width:100%;height:auto;",
    "hr":  f"border:0;border-top:1px solid {LINE};margin:0;height:0;",
    "nav": "margin:0;padding:0;",
    "details": (f"background:{WHITE};border:1px solid {LINE};border-radius:10px;"
                f"margin:0 0 10px;box-shadow:{SH_XS};overflow:hidden;"),
    "summary": (f"display:flex;justify-content:space-between;align-items:flex-start;gap:20px;"
                f"cursor:pointer;padding:21px 24px;font-size:15.5px;font-weight:700;"
                f"color:{INK};line-height:1.8;background:{WHITE};text-align:left;"),
    "iframe": "border:0;display:block;",
    "section": "display:block;margin:0;padding:0;",
    "article": "display:block;",
    # 素の span / div は色を持たないので、テーマの
    # `span,div{color:#3a3b3d !important}` に負けて継承が断ち切られる。
    # 濃い面の上では文字が読めなくなるため、継承すること自体を !important で宣言する。
    # クラスで色を指定している要素では、後から上書きされてこの指定は消える。
    "span": "color:inherit;",
    "div":  "color:inherit;",
    "dt": "margin:0;padding:0;color:inherit;",
    "dd": "margin:0;padding:0;color:inherit;",
}

# ══════════════════════════════════════════════════
#  クラス
# ══════════════════════════════════════════════════
CLASS_STYLES = {
    # ── ルート・レイアウト ────────────────────
    "hrc": (f"color:{INK};line-height:1.9;text-align:left;max-width:100%;"
            f"box-sizing:border-box;overflow-wrap:break-word;font-feature-settings:'palt';"),
    "hrc-full": "position:relative;width:auto;left:auto;margin-left:0;",
    "hrc-container": ("max-width:1120px;margin-left:auto;margin-right:auto;"
                      "padding-left:clamp(18px,4vw,32px);padding-right:clamp(18px,4vw,32px);"
                      "box-sizing:border-box;"),
    # 長文セクションは行長を落として読みやすくする
    "hrc-container--narrow": "max-width:812px;",
    "hrc-section": "padding-top:clamp(44px,6.5vw,84px);padding-bottom:clamp(44px,6.5vw,84px);",
    "hrc-section--tight": "padding-top:clamp(24px,3.5vw,44px);padding-bottom:clamp(24px,3.5vw,44px);",
    "hrc-section--bg": f"background:{BG};",
    "hrc-section--white": f"background:{WHITE};",
    "hrc-rule": f"border:0;border-top:1px solid {LINE};margin:0;height:0;",

    # ── 見出し ────────────────────────────────
    "hrc-eyebrow": (f"font-size:11.5px;letter-spacing:.22em;text-transform:uppercase;"
                    f"color:{GOLD_DEEP};font-weight:700;margin:0 0 14px;line-height:1.7;"),
    "hrc-h1": ("font-size:clamp(27px,4.2vw,42px);margin:0 0 20px;line-height:1.35;"
               "letter-spacing:-.005em;border:0;padding:0;font-weight:800;"),
    "hrc-h2": (f"font-size:clamp(21px,3vw,30px);margin:0 0 22px;line-height:1.4;"
               f"padding:0 0 14px;border:0;border-bottom:2px solid {GOLD};"
               f"display:inline-block;font-weight:800;"),
    "hrc-h3": "font-size:clamp(17px,2.1vw,21px);margin:0 0 14px;line-height:1.5;border:0;padding:0;font-weight:800;",
    "hrc-h4": "font-size:16px;margin:0 0 12px;line-height:1.6;border:0;padding:0;font-weight:700;",
    "hrc-lead": (f"color:{BODY};font-size:clamp(15px,1.35vw,16.5px);line-height:2.05;"
                 f"margin:0 0 30px;max-width:680px;"),
    "hrc-center": "text-align:center;",
    "hrc-body-text": f"color:{BODY};font-size:15.5px;line-height:2.05;margin:0 0 1.2em;",
    "hrc-muted-text": f"color:{MUTED};font-size:13.5px;line-height:1.95;margin:0 0 1.2em;",
    "hrc-updated": f"font-size:12px;color:{MUTED};margin:0 0 28px;letter-spacing:.04em;",
    "hrc-sign": f"text-align:right;color:{MUTED};font-size:13.5px;margin:24px 0 0;",
    "hrc-hint": (f"display:block;font-size:12.5px;color:{MUTED};margin-top:6px;"
                 f"line-height:1.8;font-weight:400;"),
    "hrc-mt24": "margin-top:28px;",

    # ── ボタン ────────────────────────────────
    "hrc-btn": ("display:inline-block;font-weight:700;font-size:14.5px;line-height:1;"
                "padding:17px 30px;border-radius:4px;text-align:center;text-decoration:none;"
                "border:1px solid transparent;cursor:pointer;box-sizing:border-box;"
                "letter-spacing:.06em;"),
    "hrc-btn--gold":  f"background:{GOLD};color:{INK};border-color:{GOLD};box-shadow:{SH_SM};",
    "hrc-btn--green": f"background:{GREEN};color:{WHITE};border-color:{GREEN};",
    "hrc-btn--dark":  f"background:{INK};color:{WHITE};border-color:{INK};",
    "hrc-btn--ghost": f"background:transparent;color:{INK};border-color:{INK};",
    "hrc-btn--sm":    "padding:11px 20px;font-size:12.5px;border-radius:3px;box-shadow:none;",
    "hrc-btnrow": "display:flex;flex-wrap:wrap;gap:14px;margin:0;padding:0;list-style:none;align-items:center;",
    "hrc-btnrow--center": "justify-content:center;",

    # ── パンくず ──────────────────────────────
    "hrc-crumb": f"font-size:12px;color:{MUTED};padding:16px 0;border-bottom:1px solid {LINE_SOFT};margin:0;",

    # ── ヒーロー（明るいスクリム＋濃い文字：ブランドに合わせた配色） ──
    "hrc-hero": (f"position:relative;border-radius:6px;overflow:hidden;background:#e9edf1;"
                 f"border:1px solid {LINE};margin:0;isolation:isolate;"),
    "hrc-hero__bg": ("position:absolute;top:0;left:0;width:100%;height:100%;"
                     "object-fit:cover;object-position:center 42%;z-index:0;"),
    "hrc-hero__scrim": ("position:absolute;top:0;left:0;width:100%;height:100%;z-index:1;"
                        "background:linear-gradient(100deg,rgba(255,255,255,.96) 0%,"
                        "rgba(255,255,255,.90) 38%,rgba(255,255,255,.55) 66%,"
                        "rgba(255,255,255,.10) 100%);"),
    "hrc-hero__inner": ("position:relative;z-index:2;padding:clamp(34px,6.5vw,76px) clamp(22px,5vw,60px);"
                        "max-width:660px;"),
    "hrc-hero__eyebrow": (f"font-size:11.5px;letter-spacing:.24em;text-transform:uppercase;"
                          f"color:{GOLD_DEEP};font-weight:700;margin:0 0 16px;line-height:1.7;"),
    "hrc-hero__title": (f"font-size:clamp(26px,4.4vw,44px);line-height:1.32;color:{INK};"
                        f"margin:0 0 20px;font-weight:800;letter-spacing:-.005em;border:0;padding:0;"),
    "hrc-hero__titlesub": (f"display:block;font-size:clamp(13px,1.3vw,16px);font-weight:600;"
                           f"color:{BODY};margin-top:14px;line-height:1.8;letter-spacing:.04em;"),
    "hrc-hero__lead": (f"font-size:clamp(14.5px,1.4vw,16.5px);line-height:2;color:{BODY};"
                       f"margin:0 0 32px;max-width:500px;font-weight:500;"),

    # ── 実績ストリップ ────────────────────────
    # grid + gap で罫線を作ると、折り返しで余ったマスに下地の灰色がそのまま出る。
    # flex にして各セルに罫線を持たせると、最終行の項目が伸びて隙間が生まれない。
    "hrc-stats": (f"display:flex;flex-wrap:wrap;background:{WHITE};"
                  f"border:1px solid {LINE};border-radius:6px;overflow:hidden;margin:0;"),
    "hrc-stat": (f"flex:1 1 158px;box-sizing:border-box;background:{WHITE};"
                 f"padding:26px 18px;text-align:center;"
                 f"border-left:1px solid {LINE};border-top:1px solid {LINE};"
                 f"margin:-1px 0 0 -1px;"),   # 外枠と重ねて二重線を防ぐ
    "hrc-stat__value": (f"display:block;font-size:clamp(19px,2.2vw,25px);font-weight:800;"
                        f"color:{INK};line-height:1.3;margin:0 0 8px;letter-spacing:-.01em;"),
    "hrc-stat__label": (f"display:block;font-size:11.5px;color:{MUTED};letter-spacing:.14em;"
                        f"font-weight:600;line-height:1.6;margin:0;"),

    # ── 要点（AIO用サマリー） ─────────────────
    "hrc-keyfacts": (f"background:{WHITE};border:1px solid {LINE};border-top:3px solid {GREEN};"
                     f"border-radius:4px;padding:clamp(24px,3.5vw,38px) clamp(20px,3.5vw,40px);"
                     f"box-shadow:{SH_SM};margin:0 0 8px;"),
    "hrc-keyfacts-title": (f"font-size:11.5px;font-weight:700;color:{GREEN};margin:0 0 22px;"
                           f"letter-spacing:.2em;text-transform:uppercase;line-height:1.7;border:0;padding:0;"),
    "hrc-kf-list": ("list-style:none;margin:0;padding:0;display:grid;"
                    "grid-template-columns:repeat(auto-fit,minmax(310px,1fr));gap:0 44px;"),
    "hrc-kf-item": (f"display:flex;gap:12px;align-items:flex-start;margin:0;padding:13px 0;"
                    f"border-bottom:1px solid {LINE_SOFT};font-size:14px;line-height:1.95;color:{BODY};"),
    # 1行目の行ボックス（14px × 1.95 ≒ 27px）と同じ高さの箱に入れて中央寄せする。
    # line-height での目分量合わせだと文字サイズが変わった途端にずれるため。
    "hrc-kf-check": (f"color:{GREEN};font-weight:700;font-size:11px;flex:0 0 auto;"
                     f"display:inline-flex;align-items:center;justify-content:center;"
                     f"width:15px;height:27px;line-height:1;"),

    # ── 禁止事項リスト（要点の ✓ と同じ作りの × 版） ──
    "hrc-xlist": "list-style:none;margin:0;padding:0;",
    "hrc-xlist__item": (f"display:flex;gap:11px;align-items:flex-start;margin:0 0 9px;"
                        f"padding:0;font-size:14px;line-height:1.95;color:{BODY};"),
    "hrc-x-mark": (f"color:{DANGER};font-weight:700;font-size:11px;flex:0 0 auto;"
                   f"display:inline-flex;align-items:center;justify-content:center;"
                   f"width:15px;height:27px;line-height:1;"),

    # ── グリッド ──────────────────────────────
    "hrc-grid": "display:grid;gap:clamp(16px,2vw,26px);",
    "hrc-grid--2": "grid-template-columns:repeat(auto-fit,minmax(320px,1fr));",
    "hrc-grid--3": "grid-template-columns:repeat(auto-fit,minmax(230px,1fr));",
    "hrc-grid--4": "grid-template-columns:repeat(auto-fit,minmax(240px,1fr));",
    "hrc-links": "display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin:0;padding:0;",
    "hrc-gal": "",

    # ── カード ────────────────────────────────
    "hrc-card": (f"background:{WHITE};border:1px solid {LINE};border-radius:4px;"
                 f"overflow:hidden;box-shadow:{SH_XS};display:flex;flex-direction:column;"),
    "hrc-card__body": "padding:clamp(22px,2.4vw,28px);display:flex;flex-direction:column;flex:1 1 auto;",
    "hrc-card__title": "font-size:16.5px;margin:0 0 13px;line-height:1.65;border:0;padding:0;font-weight:700;letter-spacing:.015em;",
    "hrc-card__text": f"color:{BODY};font-size:14px;line-height:1.92;flex:1 1 auto;margin:0 0 20px;",

    # ── 店舗カード ────────────────────────────
    "hrc-shop": (f"background:{WHITE};border:1px solid {LINE};border-radius:4px;"
                 f"overflow:hidden;box-shadow:{SH_SM};display:flex;flex-direction:column;"),
    "hrc-shop__head": f"background:{GREEN_DEEP};padding:clamp(24px,3vw,30px) clamp(22px,3vw,30px);",
    "hrc-shop__name": f"font-size:clamp(18px,2vw,21px);color:{WHITE};margin:12px 0 0;line-height:1.5;border:0;padding:0;font-weight:800;",
    "hrc-shop__sub": "font-size:13px;color:rgba(255,255,255,.72);margin:8px 0 0;line-height:1.75;",
    "hrc-shop__body": "padding:clamp(22px,3vw,30px);flex:1 1 auto;",
    "hrc-shop__map": (f"position:relative;padding-bottom:60%;height:0;overflow:hidden;"
                      f"border-top:1px solid {LINE};background:#eeeeea;"),

    # ── 定義リスト ────────────────────────────
    "hrc-dl": "margin:0 0 26px;padding:0;",
    "hrc-dl__row": (f"display:flex;flex-wrap:wrap;gap:4px 16px;padding:15px 0;"
                    f"border-bottom:1px solid {LINE_SOFT};align-items:flex-start;"),
    "hrc-dt": (f"flex:0 0 96px;font-size:11.5px;font-weight:700;color:{MUTED};"
               f"letter-spacing:.1em;padding-top:5px;line-height:1.8;margin:0;"),
    "hrc-dd": f"flex:1 1 240px;margin:0;font-size:14.5px;color:{BODY};line-height:1.95;",

    # ── テーブル ──────────────────────────────
    "hrc-tablewrap": (f"overflow-x:auto;border:1px solid {LINE};border-radius:4px;"
                      f"background:{WHITE};box-shadow:{SH_XS};"
                      f"-webkit-overflow-scrolling:touch;max-width:100%;"),
    "hrc-table": "font-size:14px;min-width:520px;border-collapse:collapse;width:100%;",

    # ── 注意ボックス ──────────────────────────
    "hrc-note": f"border-radius:0 4px 4px 0;padding:22px 26px;font-size:14px;line-height:2;color:{BODY};",
    "hrc-note--info": f"background:{WHITE};border-left:3px solid {GOLD};box-shadow:{SH_XS};",
    "hrc-note--warn": f"background:{GOLD_PALE};border-left:3px solid {GOLD};",
    "hrc-note--danger": f"background:#fdf3f2;border-left:3px solid {DANGER};border-radius:0 4px 4px 0;",
    "hrc-alert": (f"background:{GOLD_PALE};border:1px solid #f2d98a;border-top:3px solid {GOLD};"
                  f"border-radius:4px;padding:clamp(26px,3.5vw,36px) clamp(22px,3.5vw,40px);"
                  f"box-shadow:{SH_XS};max-width:720px;margin:0 auto;text-align:center;"),
    "hrc-alert__title": f"font-size:17px;color:{INK};margin:0 0 12px;line-height:1.6;border:0;padding:0;font-weight:800;",

    # ── キャンペーン・CTA ─────────────────────
    "hrc-topbar": (f"background:{GREEN_DEEP};padding:16px clamp(20px,3vw,28px);border-radius:4px;"
                   f"display:flex;align-items:center;justify-content:center;gap:18px;"
                   f"flex-wrap:wrap;margin:0;"),
    "hrc-campaign": (f"background:{GREEN_DEEP};border-radius:4px;"
                     f"padding:clamp(28px,4vw,44px) clamp(22px,4vw,48px);box-shadow:{SH};"),
    "hrc-campaign__excl": (f"font-size:12.5px;background:rgba(0,0,0,.28);padding:14px 18px;"
                           f"border-radius:3px;line-height:1.95;margin:0;color:rgba(255,255,255,.9);"),
    "hrc-ctaband": (f"background:{BG_DEEP};border-radius:4px;"
                    f"padding:clamp(30px,4vw,48px) clamp(22px,4vw,48px);box-shadow:{SH};"),

    # ── ステップ ──────────────────────────────
    "hrc-step": (f"background:{WHITE};border:1px solid {LINE};border-radius:4px;"
                 f"padding:clamp(24px,2.6vw,30px);box-shadow:{SH_XS};"),
    "hrc-step__num": (f"display:inline-flex;align-items:center;justify-content:center;"
                      f"width:30px;height:30px;border-radius:50%;background:{GREEN};"
                      f"color:{WHITE};font-weight:700;font-size:13px;margin-bottom:16px;line-height:1;"),
    "hrc-step__title": "font-size:16px;margin:0 0 13px;line-height:1.65;border:0;padding:0;font-weight:700;letter-spacing:.015em;",

    # ── 保険プラン ────────────────────────────
    "hrc-plan": f"background:{WHITE};border:1px solid {LINE};border-radius:4px;padding:32px 26px 34px;position:relative;",
    "hrc-plan--best": f"background:{BG_DEEP};border:1px solid {BG_DEEP};padding-top:52px;box-shadow:{SH};",
    "hrc-plan__ribbon": (f"position:absolute;top:0;left:0;right:0;background:{GOLD};text-align:center;"
                         f"font-size:10.5px;font-weight:700;letter-spacing:.2em;color:{INK};"
                         f"padding:9px 0;line-height:1.5;"),
    "hrc-plan__label": f"font-size:10.5px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:{MUTED};margin:0 0 8px;",
    "hrc-plan__name": "font-size:14px;margin:0 0 20px;line-height:1.6;border:0;padding:0;font-weight:700;",
    "hrc-plan__price": f"font-size:clamp(26px,3vw,32px);font-weight:800;line-height:1.15;margin:0 0 6px;color:{INK};letter-spacing:-.02em;",
    "hrc-plan__row": f"display:flex;justify-content:space-between;gap:10px;margin:0 0 11px;font-size:12.5px;",
    "hrc-plan__foot": f"font-size:12px;color:{MUTED};line-height:1.85;margin:12px 0 0;",
    "hrc-yes": f"color:{GREEN};font-weight:700;",
    "hrc-no": f"color:{DANGER};font-weight:700;",
    # 良し悪しではなく「金額そのもの」を示す欄（免責額など）
    "hrc-amount": f"color:{INK};font-weight:700;",

    # ── FAQ ───────────────────────────────────
    "hrc-faq": "max-width:812px;margin:0 auto;",
    "hrc-faq__a": f"padding:4px 24px 26px;background:{WHITE};",
    "hrc-faq__mark": f"color:{GREEN};font-size:17px;font-weight:400;line-height:1.8;flex:0 0 auto;",

    # ── 相互リンク ────────────────────────────
    "hrc-linkcard": (f"display:block;background:{WHITE};border:1px solid {LINE};border-radius:4px;"
                     f"padding:22px 24px;font-size:14.5px;font-weight:700;color:{INK};"
                     f"box-shadow:{SH_XS};text-decoration:none;line-height:1.65;"),
    "hrc-linkcard__sub": f"display:block;font-size:12.5px;font-weight:400;color:{MUTED};margin-top:7px;line-height:1.8;",

    # ── 画像 ──────────────────────────────────
    "hrc-figure": "margin:0 0 28px;position:relative;",
    "hrc-photo": "margin:0 0 32px;",

    # ── 注記リスト ────────────────────────────
    "hrc-notelist": "list-style:none;margin:22px 0 36px;padding:0;",
    "hrc-notelist__item": f"font-size:13px;color:{MUTED};line-height:2;margin:0 0 10px;padding-left:15px;text-indent:-15px;",

    # ── 表内の装飾 ────────────────────────────
    "price": f"font-size:15px;font-weight:700;color:{INK};letter-spacing:-.01em;",
    "price-green": f"font-size:16.5px;font-weight:800;color:{GREEN};letter-spacing:-.01em;",
    "strike": "text-decoration:line-through;color:#b3b3ae;font-size:12px;font-weight:400;margin-right:6px;",
    "off": (f"display:inline-block;background:{GREEN};color:{WHITE};font-size:9.5px;font-weight:700;"
            f"letter-spacing:.1em;padding:4px 7px;border-radius:2px;margin-right:8px;vertical-align:1px;"),
    "dnote": f"font-size:11px;color:{MUTED};font-weight:400;margin-right:8px;letter-spacing:.06em;",
    "unit": f"font-size:11.5px;color:{MUTED};font-weight:400;",
    "sub": "display:block;margin-top:6px;font-size:10px;font-weight:400;letter-spacing:.1em;color:rgba(255,255,255,.6);",
    "neg": f"color:{DANGER};font-weight:700;",
    "pos": f"color:{GREEN};font-weight:700;",

    # 旧クラス（互換のため定義だけ残す）
    "hrc-hero__img": "width:100%;height:auto;object-fit:cover;",
    "hrc-hero__sub": f"font-size:16px;font-weight:700;color:{INK};line-height:1.9;margin:0 0 20px;",
    "hrc-hero__list": "margin:0 0 26px;padding-left:1.3em;list-style:disc;",
    "hrc-hero__card": f"background:{WHITE};border:1px solid {LINE};border-radius:4px;padding:26px;box-shadow:{SH_SM};max-width:560px;margin:0;",
    # 枠付きの箱に中央寄せで入れると、中身だけが他の要素より内側に寄って
    # ページの左端（パンくず・見出し・本文）と揃わない。
    # 枠と余白をやめ、左端から始まる素の帯にする。
    "hrc-badgebar": ("display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-start;"
                     "gap:6px 0;margin:0;padding:0;background:none;border:0;list-style:none;"),
    "hrc-badgebar__item": (f"font-size:12.5px;font-weight:600;color:{MUTED};"
                           f"letter-spacing:.06em;line-height:1.9;white-space:nowrap;"),
    "hrc-badgebar__sep": f"color:{LINE};margin:0 15px;line-height:1.9;font-size:12.5px;",
    "hrc-pill": (f"display:inline-block;background:{GREEN_PALE};color:{GREEN_DEEP};font-size:11px;"
                 f"font-weight:700;letter-spacing:.12em;padding:6px 13px;border-radius:2px;line-height:1.7;"),
    "hrc-pill--gold": f"background:rgba(254,204,1,.16);color:{GOLD};",
    "hrc-pill--dark": f"background:rgba(16,19,25,.86);color:{WHITE};",
    "hrc-keyfacts-wrap": "",
}

# ══════════════════════════════════════════════════
#  テーブルの文脈依存スタイル
# ══════════════════════════════════════════════════
TABLE = {
    "thead_th":     f"background:{INK};color:{WHITE};font-weight:700;font-size:10.5px;letter-spacing:.16em;padding:17px 20px;text-align:left;vertical-align:bottom;line-height:1.7;border:0;text-transform:uppercase;",
    "thead_th_num": "text-align:right;",
    "thead_th_hl":  f"background:{GREEN_DEEP};color:{GOLD};text-align:right;",
    "thead_sub_hl": "color:rgba(255,255,255,.62);",
    "tbody_th":     f"text-align:left;font-weight:700;padding:17px 20px;border-bottom:1px solid {LINE_SOFT};color:{INK};background:{WHITE};font-size:14px;line-height:1.8;vertical-align:top;",
    "tbody_td":     f"padding:17px 20px;border-bottom:1px solid {LINE_SOFT};vertical-align:middle;color:{BODY};font-size:14px;background:{WHITE};line-height:1.8;",
    "row_even":     "background:#fcfcfa;",
    "row_last":     "border-bottom:0;",
    "td_num":       f"text-align:right;font-weight:700;color:{INK};white-space:nowrap;",
    "td_hl":        f"text-align:right;background:{GREEN_PALE};white-space:nowrap;",
    "td_hl_even":   "background:#e7f4ed;",
    "td_soft":      f"color:{MUTED};font-weight:400;font-style:normal;",
    "td_small":     "font-size:13px;",
    "foot_td":      f"text-align:center;font-size:12.5px;color:{MUTED};font-style:normal;background:#fcfcfa;font-weight:400;letter-spacing:.02em;",
}

# ══════════════════════════════════════════════════
#  残す <style>（:hover と details の開閉のみ）
# ══════════════════════════════════════════════════
RESIDUAL_CSS = f"""
/* インライン style では表現できないものだけ（:hover・開閉状態・擬似要素の打ち消し）。
   このブロックが失われてもレイアウトと配色は崩れません。
   ただしテーマ由来の見出し装飾（下線など）は復活します。 */

/* テーマの ::before/::after による見出し装飾を消す。
   擬似要素は style 属性から一切触れないため、ここでしか止められない。
   本文側で使っている擬似要素は [data-hrc-mark]::after だけなので巻き込まない。 */
.hrc h1::before,.hrc h1::after,.hrc h2::before,.hrc h2::after,
.hrc h3::before,.hrc h3::after,.hrc h4::before,.hrc h4::after,
.hrc h5::before,.hrc h5::after,
.hrc p::before,.hrc p::after,
.hrc li::before,.hrc li::after,
.hrc dt::before,.hrc dt::after,.hrc dd::before,.hrc dd::after,
.hrc a::before,.hrc a::after,
.hrc table::before,.hrc table::after,
.hrc th::before,.hrc th::after,.hrc td::before,.hrc td::after,
.hrc figure::before,.hrc figure::after,
.hrc figcaption::before,.hrc figcaption::after,
.hrc blockquote::before,.hrc blockquote::after
{{content:none !important;display:none !important;border:0 !important;
  background:none !important;width:0 !important;height:0 !important;}}
.hrc a[data-hrc-btn]{{transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;}}
.hrc a[data-hrc-btn]:hover{{transform:translateY(-2px);box-shadow:{SH};opacity:.94;}}
.hrc a[data-hrc-link]{{transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;}}
.hrc a[data-hrc-link]:hover{{transform:translateY(-2px);box-shadow:{SH_SM};border-color:{GOLD};}}
.hrc [data-hrc-card]{{transition:transform .2s ease,box-shadow .2s ease;}}
.hrc [data-hrc-card]:hover{{transform:translateY(-2px);box-shadow:{SH_SM};}}
.hrc summary::-webkit-details-marker{{display:none;}}
.hrc summary::marker{{content:"";}}
.hrc details[open] summary{{border-bottom:1px solid {LINE_SOFT};}}
.hrc details[open] [data-hrc-mark]{{visibility:hidden;position:relative;}}
.hrc details[open] [data-hrc-mark]::after{{content:"−";visibility:visible;position:absolute;right:0;top:0;}}
"""
