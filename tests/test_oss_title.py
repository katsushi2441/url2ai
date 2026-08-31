"""oss.php のタイトルが崩れる問題の回帰テスト。

READMEの体裁を場合分けして弾く方式は、READMEの書き方が無限にあるため
収束しなかった(何度直しても別のパターンが漏れる)。

**リポジトリ名を正**とし、READMEの見出しは「リポジトリ名を含む」と
確認できたときだけ採用する方式に変えた。ここには、実際にoss.phpへ
登録されてしまった壊れ方をそのまま残す。

実行: python3 -m pytest tests/test_oss_title.py -q
"""
import re
import pathlib

SRC = pathlib.Path(__file__).resolve().parents[1] / "src" / "oss_worker.py"


def _load():
    """ネットワークを触らずに、タイトル決定の関数だけ取り出す。"""
    src = SRC.read_text(encoding="utf-8")
    ns = {"re": re}
    m = re.search(r"^_TITLE_REJECT = .*$", src, re.M)
    exec(m.group(0), ns)
    for fn in ("repo_name_from_url", "looks_like_project_name", "extract_title_from_readme"):
        f = re.search(rf"^def {fn}\(.*?(?=^def |\Z)", src, re.S | re.M)
        exec(f.group(0), ns)
    return ns["extract_title_from_readme"]


extract = _load()


def title(readme, url):
    return extract(readme, url.replace("https://github.com/", ""))


def test_html_attribute_is_not_a_title():
    """<picture>のmedia属性が名前として登録されていた(LibreDesk)。"""
    readme = ('<picture>\n<source media="(prefers-color-scheme: dark)" srcset="a.png">\n'
              "</picture>\n# LibreDesk\n")
    assert title(readme, "https://github.com/abhinavxd/libredesk") == "LibreDesk"


def test_bare_image_url_is_not_a_title():
    readme = "https://github.com/user-attachments/assets/b123\n\n# Some Tool\n"
    assert title(readme, "https://github.com/foo/mobile-mcp") == "mobile-mcp"


def test_marketing_sentence_is_not_a_title():
    readme = "Get an immersive, multi-agent learning experience with OpenMAIC today\n"
    assert title(readme, "https://github.com/x/OpenMAIC") == "OpenMAIC"


def test_unrelated_heading_falls_back_to_repo_name():
    assert title("# 升级打怪开源社区 首批开放共建项目 ·\n",
                 "https://github.com/x/gotoujunshi") == "gotoujunshi"


def test_long_marketing_heading_falls_back():
    readme = "# Corsair: Connect your users to their apps and services\n"
    assert title(readme, "https://github.com/x/corsair") == "corsair"


def test_real_project_heading_is_kept():
    """リポジトリ名を含む見出しは、そのほうが分かりやすいので残す。"""
    readme = "# Checkstyle - Java Code Quality Tool\n"
    assert title(readme, "https://github.com/checkstyle/checkstyle") == \
        "Checkstyle - Java Code Quality Tool"


def test_no_readme_uses_repo_name():
    assert title("", "https://github.com/anydoc/anydoc") == "anydoc"


def test_badge_lines_are_skipped():
    readme = "[![build](https://shields.io/x.svg)](https://ci)\n# ComfyUI\n"
    assert title(readme, "https://github.com/comfyanonymous/ComfyUI") == "ComfyUI"


def test_title_never_contains_url_or_markup():
    """どんな入力でも、URLやHTMLの断片を名前にしない。"""
    junk = [
        '<div align="center">\n',
        "https://example.com/a.png\n",
        '<img src="x.png" width="100">\n',
        "| 表 | 見出し |\n",
        "> 引用\n",
    ]
    for readme in junk:
        got = title(readme, "https://github.com/owner/myrepo")
        assert got == "myrepo", got
        assert "://" not in got and "<" not in got and "=" not in got
