"""验证文档源码里跨页锚点链接的目标是否存在。

做法：把 markdown 标题按 VuePress 的 slug 规则转成锚点，再比对链接里的 #fragment。
VuePress 用 markdown-it-anchor 的默认 slugify：小写、空格转 -、去掉标点。
中文保留，标点（含全角）被删。
"""
import io
import os
import re
import sys

src = sys.argv[1]
link_re = re.compile(r'\]\((/[^)\s]+?\.md)#([^)\s]+)\)')
head_re = re.compile(r'^(#{1,6})\s+(.+?)\s*$', re.M)


def slugify(text):
    """近似 markdown-it-anchor 的默认 slugify（与 VuePress 产物的 id 对齐）。

    实测规则：小写 → 去行内代码标记 → 去粗斜体标记 → 空格转 '-' →
    全角括号转 '-' → 去 ASCII 标点（**保留 `-` 和 `_`**）→ 去其余全角标点 →
    下划线转 '-'。

    全角括号那一步是踩过坑的：`Linux（Debian/Ubuntu）` 的产物 id 是
    `linux-debian-ubuntu` —— 括号变成分隔符，不是被删掉。早先直接删，
    于是把**正确**的链接报成死锚点（检查器撒谎比没有更糟）。
    """
    s = text.strip().lower()
    s = re.sub(r'`([^`]*)`', r'\1', s)          # 去掉行内代码标记
    s = re.sub(r'\*\*?([^*]*)\*\*?', r'\1', s)  # 去掉粗体/斜体
    s = s.replace(' ', '-')
    s = s.replace('（', '-').replace('）', '-')  # 全角括号 → 分隔符
    s = s.replace('/', '-')                     # 斜杠 → 分隔符
    # ASCII 标点，但 '-' 与 '_' 不在内（前者是分隔符，后者后面要转）
    s = re.sub(r'[!"#$%&\'()*+,.:;<=>?@\[\\\]^`{|}~]', '', s)
    s = re.sub(r'[，。、；：？！【】《》""''—…·]', '', s)   # 其余全角标点
    s = s.replace('_', '-')                     # 下划线 → 连字符
    # 收尾：去掉首尾多余的连字符。`Linux（Debian/Ubuntu）` 里收尾的全角括号
    # 会留下一个尾部 '-'，而 VuePress 的 id 是 `linux-debian-ubuntu`（不带尾杠）。
    s = s.strip('-')
    return s


def anchors_of(md_path):
    text = io.open(md_path, encoding='utf-8').read()
    # 去掉 frontmatter 与代码块，避免误把注释当标题
    text = re.sub(r'^---\n.*?\n---\n', '', text, flags=re.S)
    text = re.sub(r'```.*?```', '', text, flags=re.S)
    out = set()
    for _level, title in head_re.findall(text):
        out.add(slugify(title))
    return out


cache = {}
problems = []

for root, _dirs, files in os.walk(src):
    for f in files:
        if not f.endswith('.md'):
            continue
        path = os.path.join(root, f)
        text = io.open(path, encoding='utf-8').read()
        for target, frag in link_re.findall(text):
            md = os.path.normpath(os.path.join(src, target.lstrip('/')))
            if not os.path.isfile(md):
                problems.append((path, target + '#' + frag, '目标文件不存在'))
                continue
            if md not in cache:
                cache[md] = anchors_of(md)
            if frag not in cache[md]:
                problems.append((path, target + '#' + frag, '锚点不存在'))

if problems:
    print('锚点问题 %d 处：' % len(problems))
    for p, link, why in problems:
        print('  %-34s -> %-56s (%s)' % (os.path.relpath(p, src), link, why))
    sys.exit(1)

print('全部锚点有效（检查了 %d 个目标文件）' % len(cache))
