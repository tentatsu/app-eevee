#!/usr/bin/env python3
"""
GitHub の zip（api.github.com / codeload.github.com）をダウンロードできない環境
（Claude Code のクラウド環境など）で composer install するための前処理。

composer.lock の各パッケージを、GitHub から git で浅く（--depth 1）取得して zip にし、
Composer のキャッシュ（cache-files-dir）に置く。そのあと `composer install --prefer-dist` を実行すると、
Composer はキャッシュから展開するので、zip のダウンロードが起きない。

  python3 scripts/composer-prefetch.py && composer install --prefer-dist --no-interaction

- キャッシュのファイル名は「パッケージ名/sha1(ダウンロード URL).zip」（Composer 2.8 で確認）
- `--prefer-source`（git clone）と違い、各パッケージに .git が残らないので、ディスクを数 GB 使わずに済む
"""
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from concurrent.futures import ThreadPoolExecutor

LOCK = sys.argv[1] if len(sys.argv) > 1 else "composer.lock"
env = dict(os.environ, COMPOSER_ALLOW_SUPERUSER="1", GIT_TERMINAL_PROMPT="0")
cache_dir = subprocess.run(["composer", "config", "cache-files-dir"], capture_output=True, text=True, env=env, check=True).stdout.strip()

with open(LOCK) as f:
    lock = json.load(f)


def repo_and_ref(pkg):
    dist = pkg.get("dist") or {}
    url = dist.get("url", "")
    m = re.match(r"https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/(\w+)", url) or \
        re.match(r"https://codeload\.github\.com/([^/]+/[^/]+)/(?:legacy\.)?zip/(?:refs/tags/)?(.+)", url)
    if not m:
        return None
    return m.group(1), dist.get("reference") or m.group(2)


def fetch(pkg):
    found = repo_and_ref(pkg)
    if found is None:
        return pkg["name"], "skip"
    repo, ref = found
    target = os.path.join(cache_dir, pkg["name"], hashlib.sha1(pkg["dist"]["url"].encode()).hexdigest() + ".zip")
    if os.path.exists(target):
        return pkg["name"], "cached"
    work = tempfile.mkdtemp(prefix="prefetch-")
    try:
        git = lambda *a: subprocess.run(["git", *a], cwd=work, env=env, check=True, capture_output=True)
        git("init", "-q")
        git("remote", "add", "origin", f"https://github.com/{repo}.git")
        git("fetch", "-q", "--depth", "1", "origin", ref)
        git("-c", "advice.detachedHead=false", "checkout", "-q", "FETCH_HEAD")
        shutil.rmtree(os.path.join(work, ".git"))
        os.makedirs(os.path.dirname(target), exist_ok=True)
        tmp = target + ".part"
        with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as z:
            for root, dirs, files in os.walk(work):
                for name in files:
                    path = os.path.join(root, name)
                    if os.path.islink(path):
                        continue
                    z.write(path, os.path.relpath(path, work))
        os.replace(tmp, target)
        return pkg["name"], "fetched"
    except subprocess.CalledProcessError as e:
        return pkg["name"], "failed: " + (e.stderr or b"").decode(errors="replace").strip()[:200]
    finally:
        shutil.rmtree(work, ignore_errors=True)


packages = lock.get("packages", []) + lock.get("packages-dev", [])
with ThreadPoolExecutor(max_workers=8) as pool:
    results = list(pool.map(fetch, packages))

failed = [(n, r) for n, r in results if r.startswith("failed")]
counts = {k: sum(1 for _, r in results if r == k) for k in ("fetched", "cached", "skip")}
print(f"prefetch: fetched={counts['fetched']} cached={counts['cached']} skipped={counts['skip']} failed={len(failed)}")
for name, reason in failed:
    print(f"  {name}: {reason}")
sys.exit(1 if failed else 0)
