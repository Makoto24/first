#!/usr/bin/env python3
"""
白馬レンタカー WordPress 貼り付け用HTMLビルダー v3

src/*.html（素のセマンティックHTML）を読み、src/styles.py の定義に従って
すべてのスタイルを style 属性へ !important 付きで埋め込み、dist/ に出力します。

    python3 build.py

なぜインラインなのか
--------------------
インライン style の !important はCSSカスケードの最上位にあり、テーマ・プラグイン・
CSSの読み込み順に関係なく上書きされません。CSS最適化プラグインが <style> を
結合・移動・削除しても影響を受けません。

チェック内容
------------
  - JSON-LD がすべて構文的に正しいか
  - 未置換のプレースホルダーが残っていないか
  - スタイルが当たっていない hrc-* クラスがないか
"""
import json
import re
import sys
from pathlib import Path

from lxml import html as lhtml
from lxml import etree

sys.path.insert(0, str(Path(__file__).parent / "src"))
import styles as S  # noqa: E402

ROOT = Path(__file__).parent
SRC = ROOT / "src"
DIST = ROOT / "dist"

LDJSON = re.compile(r'<script type="application/ld\+json">(.*?)</script>', re.S)
LEFTOVER = re.compile(r"\{\{[A-Z_]+\}\}|【要入力】|\[TO BE ADDED\]")


def cls(name: str) -> str:
    """クラス名の完全一致を判定する XPath 述語。
    {cls("hrc-hero")} は hrc-hero__inner などにも当たってしまうため必須。"""
    return f'contains(concat(" ",normalize-space(@class)," ")," {name} ")'


def important(decls: str) -> str:
    """`a:1;b:2` → `a:1 !important;b:2 !important`"""
    out = []
    for d in decls.split(";"):
        d = d.strip()
        if not d:
            continue
        out.append(d if "!important" in d else d + " !important")
    return ";".join(out) + (";" if out else "")


def add_style(el, decls: str, *, front: bool = False) -> None:
    """要素に宣言を追記する。既存のインライン style は常に後勝ち（＝優先）。"""
    if not decls:
        return
    cur = el.get("style", "")
    new = important(decls)
    el.set("style", (new + cur) if front or not cur else (cur.rstrip(";") + ";" + new))


ORIG = {}   # 元のインライン style を退避する場所


def stash_inline(root) -> None:
    """元の style 属性を退避して外す。最後に付け直すことで必ず最優先になる。"""
    ORIG.clear()
    for el in root.iter():
        if isinstance(el.tag, str) and el.get("style"):
            ORIG[id(el)] = el.get("style")
            del el.attrib["style"]


def restore_inline(root) -> None:
    for el in root.iter():
        if isinstance(el.tag, str) and id(el) in ORIG:
            add_style(el, ORIG[id(el)])


def dedupe(style: str) -> str:
    """同じプロパティが複数あれば最後の1つだけ残す（順序は保つ）。"""
    decls = [d.strip() for d in style.split(";") if d.strip()]
    last = {}
    for i, d in enumerate(decls):
        last[d.split(":", 1)[0].strip()] = i
    keep = set(last.values())
    return ";".join(d for i, d in enumerate(decls) if i in keep) + ";"


def apply_base(el) -> None:
    """タグ既定値 → クラススタイル の順に積む"""
    css = S.TAG_DEFAULTS.get(el.tag, "")
    for name in (el.get("class") or "").split():
        css += S.CLASS_STYLES.get(name, "")
    if css:
        add_style(el, css)


def style_tables(root) -> None:
    """テーブルは文脈（thead/tbody・行の偶奇・最終行）で見た目が変わるため個別に処理"""
    T = S.TABLE
    for table in root.xpath(f'//table[{cls("hrc-table")}]'):
        for th in table.xpath("./thead//th"):
            css = T["thead_th"]
            classes = (th.get("class") or "").split()
            if "num" in classes:
                css += T["thead_th_num"]
            if "hl" in classes:
                css += T["thead_th_hl"]
                for sub in th.xpath(f'.//span[{cls("sub")}]'):
                    add_style(sub, T["thead_sub_hl"])
            add_style(th, css)

        rows = table.xpath("./tbody/tr")
        for i, tr in enumerate(rows):
            even = (i % 2) == 1          # 1行目を奇数扱い（CSSの nth-child(even) と一致）
            last = i == len(rows) - 1
            is_foot = "foot" in (tr.get("class") or "").split()

            for cell in tr.xpath("./th|./td"):
                classes = (cell.get("class") or "").split()
                css = T["tbody_th"] if cell.tag == "th" else T["tbody_td"]
                if even:
                    css += T["row_even"]
                if "num" in classes:
                    css += T["td_num"]
                if "hl" in classes:
                    css += T["td_hl_even"] if even else T["td_hl"]
                if "small" in classes:
                    css += T["td_small"]
                if "soft" in classes:
                    css += T["td_soft"]
                if is_foot:
                    css += T["foot_td"]
                if last:
                    css += T["row_last"]
                add_style(cell, css)


def style_structures(root) -> None:
    """クラス名だけでは表現しきれない構造にスタイルとフックを付ける"""
    # 要点まとめ: ::before の代わりに実体の ✓ を置く。
    # li を flex にすると <strong> ごとに flex アイテム化して文が分断されるため、
    # 本文は必ず 1つの span にまとめてから並べる。
    for box in root.xpath(f'//*[{cls("hrc-keyfacts")}]'):
        for ul in box.xpath(".//ul"):
            add_style(ul, S.CLASS_STYLES["hrc-kf-list"])
            for li in ul.xpath("./li"):
                add_style(li, S.CLASS_STYLES["hrc-kf-item"])

                wrap = etree.Element("span")
                wrap.set("style", important("flex:1 1 auto;display:block;"))
                wrap.text = li.text
                li.text = None
                for child in list(li):
                    wrap.append(child)      # 子要素と tail をまとめて移動

                mark = etree.Element("span")
                mark.set("style", important(S.CLASS_STYLES["hrc-kf-check"]))
                mark.set("aria-hidden", "true")
                mark.text = "✓"

                li.append(mark)
                li.append(wrap)

    # 定義リスト: dt/dd に幅を与える（grid ではなく flex で折返し対応）
    for dl in root.xpath(f'//dl[{cls("hrc-dl")}]'):
        for dt in dl.xpath(".//dt"):
            add_style(dt, S.CLASS_STYLES["hrc-dt"])
        for dd in dl.xpath(".//dd"):
            add_style(dd, S.CLASS_STYLES["hrc-dd"])

    # パンくず: ol/li を横並びにし、区切り記号を実体で入れる
    for nav in root.xpath(f'//nav[{cls("hrc-crumb")}]'):
        for ol in nav.xpath(".//ol"):
            add_style(ol, "list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:8px;")
            for i, li in enumerate(ol.xpath("./li")):
                add_style(li, f"margin:0;font-size:12.5px;color:{S.MUTED};line-height:1.7;")
                for a in li.xpath(".//a"):
                    add_style(a, f"color:{S.MUTED};")
                if i:
                    sep = etree.Element("span")
                    sep.set("style", important("margin-right:8px;color:#bbbbbb;"))
                    sep.set("aria-hidden", "true")
                    sep.text = "›"
                    sep.tail = li.text
                    li.text = None
                    li.insert(0, sep)

    # 相互リンク: nav > a をカード化し、中の span を補足行にする
    for nav in root.xpath(f'//nav[{cls("hrc-links")}]'):
        for a in nav.xpath("./a"):
            add_style(a, S.CLASS_STYLES["hrc-linkcard"])
            a.set("data-hrc-link", "")
            for sp in a.xpath("./span"):
                add_style(sp, S.CLASS_STYLES["hrc-linkcard__sub"])

    # 注記リスト
    for ul in root.xpath(f'//ul[{cls("hrc-notelist")}]'):
        for li in ul.xpath("./li"):
            add_style(li, S.CLASS_STYLES["hrc-notelist__item"])

    # ギャラリー・記事写真・車種写真
    for g in root.xpath(f'//*[{cls("hrc-gal")}]'):
        for img in g.xpath("./img"):
            add_style(img, "border-radius:12px;aspect-ratio:4/3;object-fit:cover;width:100%;")
    for f in root.xpath(f'//figure[{cls("hrc-photo")}]'):
        for img in f.xpath("./img"):
            add_style(img, "width:100%;aspect-ratio:16/9;object-fit:cover;border-radius:14px;")
        for cap in f.xpath("./figcaption"):
            add_style(cap, "margin-top:8px;")
    for f in root.xpath(f'//figure[{cls("hrc-figure")}]'):
        for img in f.xpath("./img"):
            add_style(img, "width:100%;aspect-ratio:16/7;object-fit:cover;border-radius:12px;")
        for cap in f.xpath("./figcaption"):
            add_style(cap, "position:absolute;bottom:14px;right:14px;margin:0;")

    # 地図の iframe
    for box in root.xpath(f'//*[{cls("hrc-shop__map")}]'):
        for fr in box.xpath("./iframe"):
            add_style(fr, "position:absolute;top:0;left:0;width:100%;height:100%;border:0;")

    # ヒーロー: 背景画像とスクリムを絶対配置で敷く
    for hero in root.xpath(f'//*[{cls("hrc-hero")}]'):
        for img in hero.xpath("./img"):
            add_style(img, S.CLASS_STYLES["hrc-hero__bg"])
        scrim = etree.Element("div")
        scrim.set("style", important(S.CLASS_STYLES["hrc-hero__scrim"]))
        scrim.set("aria-hidden", "true")
        imgs = hero.xpath("./img")
        hero.insert(hero.index(imgs[-1]) + 1 if imgs else 0, scrim)

    # 実績ストリップ
    for strip in root.xpath(f'//*[{cls("hrc-stats")}]'):
        for cell in strip.xpath("./div"):
            add_style(cell, S.CLASS_STYLES["hrc-stat"])
            for v in cell.xpath(f'./*[{cls("hrc-stat__value")}]'):
                add_style(v, S.CLASS_STYLES["hrc-stat__value"])
            for l in cell.xpath(f'./*[{cls("hrc-stat__label")}]'):
                add_style(l, S.CLASS_STYLES["hrc-stat__label"])

    # ボタンに hover 用フックを付ける
    for a in root.xpath(f'//a[{cls("hrc-btn")}]'):
        a.set("data-hrc-btn", "")
    for el in root.xpath(f'//*[{cls("hrc-card")}]|//*[{cls("hrc-step")}]'):
        if "hrc-card__body" not in (el.get("class") or "") and "hrc-card__text" not in (el.get("class") or ""):
            el.set("data-hrc-card", "")

    # FAQ: summary に開閉マークを足す
    for faq in root.xpath(f'//*[{cls("hrc-faq")}]'):
        for summary in faq.xpath(".//summary"):
            mark = etree.Element("span")
            mark.set("style", important(S.CLASS_STYLES["hrc-faq__mark"]))
            mark.set("aria-hidden", "true")
            mark.set("data-hrc-mark", "")
            mark.text = "＋"
            summary.append(mark)

    # 暗い面の上に乗る文字色を反転させる
    inverse = [
        (f'//*[{cls("hrc-campaign")}]', "rgba(255,255,255,.86)", S.GOLD),
        (f'//*[{cls("hrc-ctaband")}]', "rgba(255,255,255,.72)", S.GOLD),
        (f'//*[{cls("hrc-topbar")}]', S.WHITE, S.GOLD),
        (f'//*[{cls("hrc-shop__head")}]', "rgba(255,255,255,.72)", S.WHITE),
        (f'//*[{cls("hrc-plan--best")}]', "rgba(255,255,255,.58)", S.GOLD),
    ]
    # 独自の色を持つクラスは反転の対象外にする
    KEEP_OWN_COLOR = {
        "hrc-hero__eyebrow", "hrc-hero__titlesub", "hrc-hero__lead",
        "hrc-campaign__excl", "hrc-plan__price", "hrc-plan__label",
        "hrc-shop__sub", "hrc-stat__value", "hrc-stat__label", "hrc-btn",
    }

    def keeps_own(el):
        return bool(KEEP_OWN_COLOR & set((el.get("class") or "").split()))

    for xp, text_color, strong_color in inverse:
        for box in root.xpath(xp):
            for el in box.xpath(".//p|.//li|.//h2|.//h3|.//h4|.//small"):
                if keeps_own(el):
                    continue
                if el.tag in ("h2", "h3", "h4"):
                    add_style(el, f"color:{S.WHITE};border:0;")
                elif el.tag == "small":
                    add_style(el, f"color:{text_color};opacity:.9;")
                else:
                    add_style(el, f"color:{text_color};")
            for st in box.xpath(".//strong"):
                if not keeps_own(st):
                    add_style(st, f"color:{strong_color};")
            # 暗い面の上ではアウトラインボタンを白抜きにする
            for gh in box.xpath(f'.//a[{cls("hrc-btn--ghost")}]'):
                add_style(gh, f"color:{S.WHITE};border-color:rgba(255,255,255,.55);background:transparent;")
    # 除外期間ボックスと推奨プランは個別に微調整
    for el in root.xpath(f'//*[{cls("hrc-campaign__excl")}]'):
        add_style(el, f"color:{S.WHITE};")
    for box in root.xpath(f'//*[{cls("hrc-plan--best")}]'):
        for el in box.xpath(f'.//*[{cls("hrc-plan__price")}]'):
            add_style(el, f"color:{S.GOLD};")
        for el in box.xpath(f'.//*[{cls("hrc-plan__label")}]'):
            add_style(el, "color:#8f8f8f;")
        for el in box.xpath(f'.//*[{cls("hrc-yes")}]'):
            add_style(el, "color:#4ec77a;")
        for el in box.xpath("./hr"):
            add_style(el, "border-top:1px solid #333333;")

    # 中央寄せ指定は子孫の見出し・段落にも及ぼす
    for box in root.xpath(f'//*[{cls("hrc-center")}]'):
        for el in box.xpath(".//p|.//h1|.//h2|.//h3"):
            add_style(el, "text-align:center;")
        for el in box.xpath(f'.//*[{cls("hrc-h2")}]'):
            add_style(el, "display:inline-block;text-align:center;")


def build_page(src_path: Path) -> tuple[str, list[str]]:
    raw = src_path.read_text(encoding="utf-8")

    # JSON-LD は lxml に触らせない（属性の書き換えで壊れないよう退避）
    scripts: list[str] = []
    def stash(m):
        # 改行があると wpautop に <br /> を挿し込まれて JSON が壊れるため1行に圧縮する
        try:
            payload = json.dumps(json.loads(m.group(1)), ensure_ascii=False, separators=(",", ":"))
        except json.JSONDecodeError:
            payload = m.group(1)
        scripts.append(f'<script type="application/ld+json">{payload}</script>')
        return f"<!--HRC_LD_{len(scripts)-1}-->"
    body = LDJSON.sub(stash, raw)

    root = lhtml.fragment_fromstring(body, create_parent="div")

    stash_inline(root)
    for el in root.iter():
        if isinstance(el.tag, str):
            apply_base(el)
    style_tables(root)
    style_structures(root)
    restore_inline(root)
    for el in root.iter():
        if isinstance(el.tag, str) and el.get("style"):
            el.set("style", dedupe(el.get("style")))

    out = "".join(
        lhtml.tostring(c, encoding="unicode", pretty_print=False)
        for c in root
    )
    if root.text:
        out = root.text + out

    # 退避した JSON-LD を戻す
    for i, s in enumerate(scripts):
        out = out.replace(f"<!--HRC_LD_{i}-->", s)

    # ── wpautop 対策 ──────────────────────────────────────
    # WordPress は本文の改行を <p></p> や <br> に変換する（wpautop）。
    # これが flex の中に <br> を差し込んだり JSON-LD を壊すため、
    # 出力から改行を1つ残らず取り除く。
    out = re.sub(r">\s*\n\s*<", "><", out)      # タグとタグの間の改行は詰める
    out = re.sub(r"\s*\n\s*", " ", out)          # 本文中の改行は空白1つに
    out = re.sub(r"<!--(?!HRC_LD_).*?-->", "", out, flags=re.S)   # コメントも除去

    header = ("<!-- 白馬レンタカー | スタイルは全て style 属性に埋め込み済み。"
              "テーマ・追加CSSの影響を受けません。WordPressの「カスタムHTML」ブロックに全文貼り付けてください。 -->")
    residual = "<style>" + re.sub(r"\s*\n\s*", " ", S.RESIDUAL_CSS).strip() + "</style>"

    errors = []
    for m in LDJSON.findall(out):
        try:
            json.loads(m)
        except json.JSONDecodeError as exc:
            errors.append(f"invalid JSON-LD ({exc})")
    if "\n" in out:
        errors.append("改行が残っている（wpautop に <br> を挿し込まれる）")
    for stray in set(LEFTOVER.findall(out)):
        errors.append(f"unresolved placeholder {stray}")
    for cls in set(re.findall(r'class="([^"]*)"', out)):
        for c in cls.split():
            if c.startswith("hrc-") and c not in S.CLASS_STYLES:
                errors.append(f"style未定義のクラス: {c}")

    return header + residual + out, errors


def main() -> int:
    pages = sorted(p for p in SRC.rglob("*.html"))
    if not pages:
        print("no source pages found", file=sys.stderr)
        return 1

    all_errors = []
    for page in pages:
        rel = page.relative_to(SRC)
        out = DIST / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        html, errors = build_page(page)
        out.write_text(html, encoding="utf-8")
        print(f"  built  dist/{rel}  ({len(html):,} bytes)")
        all_errors += [f"{rel}: {e}" for e in errors]

    if all_errors:
        print("\nFAILED:", file=sys.stderr)
        for e in sorted(set(all_errors)):
            print(f"  - {e}", file=sys.stderr)
        return 1

    print(f"\nOK — {len(pages)} pages built into dist/")
    return 0


if __name__ == "__main__":
    sys.exit(main())
