"""检查 VuePress 产物的内部链接是否有死链。

只查站内链接（以 / 开头或相对路径），跳过外链、锚点、静态资源。

部署到 GitHub Pages 时产物里的链接带 base 前缀（如 /typephp-qt/），
所以先探测产物根目录名并把它剥掉再比对。
"""
import io
import os
import re
import sys

dist = sys.argv[1]

# 探测 base：从 index.html 里第一个带前缀的站内链接反推。
# 本地构建 base='/' 时探测结果为空串。
base = ''
index_html = io.open(os.path.join(dist, 'index.html'), encoding='utf-8').read()
m = re.search(r'(?:href|src)="(/([^/"]+))/assets/', index_html)
if m:
    base = m.group(1)

html_files = []
for root, _dirs, files in os.walk(dist):
    for f in files:
        if f.endswith('.html'):
            html_files.append(os.path.join(root, f))

# 站内可达的路径集合
reachable = set()
for root, _dirs, files in os.walk(dist):
    for f in files:
        p = os.path.relpath(os.path.join(root, f), dist).replace(os.sep, '/')
        reachable.add(p)
        if p.endswith('.html'):
            reachable.add(p[:-5])            # /guide/quickstart 形式
            if p.endswith('index.html'):
                d = p[:-len('index.html')]
                reachable.add(d)             # /guide/ 形式
                reachable.add(d.rstrip('/'))

href_re = re.compile(r'href="([^"]+)"')
broken = []

for path in html_files:
    rel = os.path.relpath(path, dist).replace(os.sep, '/')
    html = io.open(path, encoding='utf-8').read()
    for raw in href_re.findall(html):
        if raw.startswith(('http://', 'https://', 'mailto:', '#', 'data:')):
            continue
        target = raw.split('#')[0].split('?')[0]
        if not target:
            continue
        if target.startswith('/'):
            key = target.lstrip('/')
            if base and key.startswith(base.lstrip('/')):
                key = key[len(base.lstrip('/')):]     # 剥掉 base 前缀
            key = key.lstrip('/')                     # 剥完可能带前导斜杠
        else:
            key = os.path.normpath(os.path.join(os.path.dirname(rel), target)).replace(os.sep, '/')
        if key not in reachable and key.rstrip('/') not in reachable:
            broken.append((rel, raw))

if broken:
    print('死链 %d 条（base=%r）：' % (len(broken), base))
    for src, href in broken[:40]:
        print('  %-40s -> %s' % (src, href))
    sys.exit(1)

print('无死链（base=%r，检查了 %d 个 HTML 文件）' % (base, len(html_files)))
