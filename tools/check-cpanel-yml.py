#!/usr/bin/env python3
"""Static check of .cpanel.yml (needs PyYAML). Run from anywhere: python tools/check-cpanel-yml.py   (npm run check:deploy)

- the file parses and has deployment.tasks (list of strings)
- every task is `/bin/cp [-R|-f|-a] <src> <dest>` with absolute paths and no shell variables/operators (so nothing is deleted)
- every source exists in this repo layout (the server repo path is mapped to the local project root)
- every destination is inside the docroot; inside /tienda only the child theme and mu-plugins are written
- shop sources are limited to the allowed set (child theme + elementor-safe-mode mu-plugin)
- dist/ exists, has index.html and ships no config.secret.php
"""
import re, shlex, sys
from pathlib import Path
import yaml

ROOT = Path(__file__).resolve().parent.parent
REPO = "/home/corpor46/repos/somosescuelacapacita"
DOCROOT = "/home/corpor46/somosescuelacapacita.com"
# The only shop (/tienda) sources a deploy may copy.
THEME = "tienda/wp-content/themes/hello-elementor-chilldren"
MUPLUGIN = "tienda/wp-content/mu-plugins/elementor-safe-mode.php"
ALLOWED_SHOP_SRC = {THEME + "/.", THEME, MUPLUGIN}
ALLOWED_SHOP_DST = (
    DOCROOT + "/tienda/wp-content/themes/hello-elementor-chilldren/",
    DOCROOT + "/tienda/wp-content/mu-plugins/",
)
fails = []


def check(ok, msg):
    print(("PASS  " if ok else "FAIL  ") + msg)
    if not ok:
        fails.append(msg)


doc = yaml.safe_load((ROOT / ".cpanel.yml").read_text(encoding="utf-8"))
tasks = (doc or {}).get("deployment", {}).get("tasks")
ok_list = isinstance(tasks, list) and bool(tasks) and all(isinstance(t, str) for t in tasks)
check(ok_list, "YAML parses: deployment.tasks is a non-empty list of strings")
if not ok_list:
    sys.exit(1)

check((ROOT / "dist" / "index.html").is_file(), "dist/index.html exists (run npm run build first)")
check(not (ROOT / "dist" / "api" / "config.secret.php").exists(), "dist/ must not ship config.secret.php")

for t in tasks:
    parts = shlex.split(t)
    check(parts[0] == "/bin/cp", f"only /bin/cp: {t[:70]}")
    check(not re.search(r"[$;&|`<>]", t), "no shell variables/operators")
    flags = [p for p in parts[1:] if p.startswith("-")]
    check(set(flags) <= {"-R", "-f", "-a"}, f"flags allowed (-R/-f/-a): {flags}")
    args = [p for p in parts[1:] if not p.startswith("-")]
    check(len(args) == 2, "exactly one source and one destination")
    if len(args) != 2:
        continue
    src, dst = args
    ok_paths = src.startswith(REPO + "/") and dst.startswith(DOCROOT + "/")
    check(ok_paths, "absolute src under repo, dst under docroot")
    if not ok_paths:
        continue
    rel = src[len(REPO) + 1:]
    check(".." not in dst and ".." not in src, "no path traversal")
    check((ROOT / rel).exists(), f"source exists locally: {rel}")
    if dst.startswith(DOCROOT + "/tienda"):
        check(dst.startswith(ALLOWED_SHOP_DST), "inside /tienda only the child theme and mu-plugins are written")
        check(rel in ALLOWED_SHOP_SRC, "shop source is in the allowed set (child theme, elementor-safe-mode.php)")
    else:
        check(rel in ("dist/.", "dist"), "non-shop source is dist/")

print(f"\n{len(tasks)} tasks, {len(fails)} failures")
sys.exit(1 if fails else 0)
