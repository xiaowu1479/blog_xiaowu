# 小悟大人的个人主页 · v4（静态前台 + PHP 后台）

一个首页 + 五个各自独立的栏目页（作品集 / 博客 / 相册 / 友链 / 文章）。
前台仍是纯静态 HTML/CSS/JS（无构建、无 npm），后台是原生的 PHP 8 + SQLite ——
没有框架、没有 Composer 依赖，改内容不用再动代码文件。

首页 `index.html` 保持「一屏往下滑」的形态，把作品、最新文章、照片、友链都摊在首页；
左侧每个菜单项则各自进自己的页面，适合内容变多以后慢慢翻。

```
zhanshiye/
├── index.php               ★ 前台守卫：未安装跳安装页，已安装原样输出 index.html
├── install.php             ★ 安装页（单页，装完自动锁定）
├── index.html              首页（单页结构，内容由 JS 注入）
├── works.html              ★ 作品集页：全部作品 + 点开看详情
├── blog.html               ★ 博客列表页：关键词搜索 + 分页
├── post.html               ★ 文章页：post.html?id=N（带上一篇 / 下一篇）
├── gallery.html            ★ 相册页：全部照片 + 灯箱
├── links.html              ★ 友链页：全部友链
├── api.php                 ★ 前台数据接口：首页内容 / 文章列表 / 单篇文章
├── router.php              仅给 php -S 用的安全路由（生产环境用不到）
├── nginx.htaccess          ★ nginx 拦截规则（phpstudy 的 vhost 会 include 它）
│
├── admin/                  ★ 后台
│   ├── index.php           单入口：登录 + 概览 + 六个内容模块 + 改密码
│   └── assets/admin.css    后台样式（独立一份，不影响前台）
│
├── lib/                    ★ 后端基础设施
│   ├── bootstrap.php       常量、错误处理、输入助手、CSRF、HMAC 鉴权、上传、安装状态
│   ├── db.php              SQLite 连接、建表、首次运行灌默认数据
│   └── repo.php            数据访问层（唯一的 SQL 出口）
│
├── data/                   ★ 数据库（禁止外网访问）
│   ├── site.db             SQLite 单文件库，备份＝拷走这个文件
│   ├── install.lock        ★ 安装标记：存在即「已安装」，不可重装
│   ├── .htaccess           Apache 拒绝规则
│   └── login-attempts.json 登录失败计数（防爆破）
│
├── css/  js/  img/  fonts/ 资源与前台逻辑（js/site.js 里是内容兜底数据）
│                            js/shell.js：五个子页面共用的侧栏 + 弹窗
│                            css/blog.css + js/blog.js 只在博客两个页面加载
├── _backup/index.v1.html   改造前的原始首页
└── _preview/              审计 / 截图 / 测试脚本 + 产物
```

## 一、怎么跑起来

### 全新部署到一台新服务器（走安装流程）

1. 把整个目录拷过去，**不要带 `data/site.db` 和 `data/install.lock`**（要让新站从头安装）；
2. 给 `data/` 和 `img/` 写权限；
3. 浏览器打开站点根目录 → 自动跳到 `install.php`；
4. 安装页先跑环境自检（PHP 版本、`pdo_sqlite`/`fileinfo`/`mbstring`/`json`、目录可写、服务器类型），
   不过关会标红且禁用提交按钮；
5. 填站点名、管理员账号、密码 → 安装完成后跳到后台登录页。

安装做的事：建六张表 → 灌示例内容 → 按你填的写入管理员 → 生成 `data/install.lock`。

装完之后 **`install.php` 会永久返回 403**，不再提供"重新安装"入口。真要重装，删掉
`data/install.lock`（必要时连 `data/site.db` 一起删）再访问安装页。

想用后台的「系统更新」自动拉代码，就再把这个目录推到自己的 GitHub 仓库
（`.gitignore` 已经把 `data/`、`img/uploads/` 排除在外，不会把你的内容和图片传上去）。

### 迁移已有站点（带数据）

直接把 `data/` 整个目录一起拷过去即可，不用跑安装：锁文件在，就视为已安装，
访问根目录直接出前台，账号密码和你原站一样。

### 改完看不到变化？缓存规则

`nginx.htaccess` 里有一条 `add_header Cache-Control "no-cache" always;`，`index.php` 里也补了同样的头，
含义是「**每次回来问一下有没有变**」：没变就回 304（几十字节），变了立刻拿新的。
所以手改 HTML / CSS / JS 之后普通刷新（F5）就够了，不用按 Ctrl+F5。

换到 Apache 部署时，这条要换成 `.htaccess` 里的（需要 `mod_headers`）：

```apache
<FilesMatch "\.(html|css|js)$">
  Header set Cache-Control "no-cache, must-revalidate"
</FilesMatch>
```

### 本机开发

```
http://zhanshiye:779/               首页
http://zhanshiye:779/works.html     作品集
http://zhanshiye:779/blog.html      博客（搜索 + 分页）
http://zhanshiye:779/post.html?id=1 文章
http://zhanshiye:779/gallery.html   相册
http://zhanshiye:779/links.html     友链
http://zhanshiye:779/admin/         后台
```

> nginx 与 php-cgi 都要在 phpstudy 面板里跑着（php-cgi 占 9006）。

**不想开 nginx，只想临时看看**：

```powershell
# 在站点根目录执行；必须带 router.php 参数，否则 data/site.db 会被直接下载
php -S 127.0.0.1:8099 router.php
```

**只想看界面、不装 PHP**：直接双击 `index.html`。`js/site.js` 检测到 `file://` 就跳过接口、
使用文件里内置的默认内容，页面照常显示（只是后台改动不会体现）。

## 二、后台怎么用

本机现有站点的账号是 `admin` / `admin123`（早期自动灌入的默认值）；
**全新安装时由安装页自己设定**，不再有硬编码默认密码。

打开 `/admin/`，用对应的账号登录：
登录后**先做两件事**：① 去「修改密码」换掉默认密码；② 去「站点信息」把签名、友链、作品换成自己的。

| 菜单 | 管什么 |
|---|---|
| 概览 | 各模块条数、隐藏条数、PHP 版本、库文件大小、默认密码告警 |
| 站点信息 | 名称/签名/状态/头像、技能标签、关于弹窗、旗下站点、赞赏码、备案、运行计时、两个行为开关 |
| 友链 | 名称、描述、链接、排序、显隐 |
| 作品集 | 标题、年份、图标、简介、标签、链接、详情、排序、显隐 |
| 博客 | 日期（留空自动填今天）、标题、分类、摘要、正文（Markdown）、外链、排序、显隐 |
| 分类 | 博客分类：名称、简介、排序、显隐（删分类不会删文章，那些文章退回「未分类」） |
| 相册 | 图片（可直接上传）、图说、排序、显隐 |
| 社交图标 | 左侧栏社交图标（social）与名片卡两个快捷入口（quick） |
| 修改密码 | 校验旧密码 → 存新哈希 |

列表里的眼睛图标是「前台显示/隐藏」，箭头调顺序，垃圾桶删除（都会二次确认，且全部是 POST + CSRF）。

几个填写格式（都在页面里有提示）：

- 技能标签：每行一条 `标签|颜色`，如 `CSS|#f70808`
- 关于弹窗：每行一条 `图标|颜色|文字`，如 `fa-regular fa-comment|#63e6be|相信未来皆有可能。`
- 作品标签：用逗号分隔，如 `HTML,CSS,JS`
- 图片字段：可以直接上传（jpg/png/gif/webp/svg，≤5MB），也可以手填 `img/xxx.jpg`

### 发文章：Markdown 编辑器 + 分类

「博客」页的正文是 Markdown 编辑器：上面一排按钮插语法，**右边实时预览**，
预览用的渲染器（`js/markdown.js`）和前台正文是同一个文件，所以预览什么样、发出去就什么样。

| 按钮 | 插入 | 说明 |
|---|---|---|
| B / I | `**粗体**` / `*斜体*` | 选中文字再点，会包起来；没选中就插占位词 |
| H | `## 标题` | 给选中行加 `## ` |
| 列表 / 引用 | `- 条目` / `> 引用` | 逐行加前缀 |
| 代码 / 代码块 | `` `code` `` / 三反引号 | 代码块后面可以跟语言名（`css`、`js`） |
| 链接 | `[文字](https://)` | 选中文字再点，会变成链接 |
| 图片 | 打开文件选择框 | 选本地图片 → 自动上传 → 插入 `![文件名](img/uploads/…)`（不用手填路径） |

顺手的小功能：

- 输入停顿 120ms 自动预览，右下角显示字数（中文按字、英文按词）
- 「只看预览」在编辑/预览之间切换，窄屏（<900px）自动上下排
- Tab 键在编辑区里是缩进，不会跳走
- **截图直接 Ctrl+V 粘进正文**（或把图片拖进来），自动上传并插入 `![图片](img/uploads/…)`
- 草稿存在浏览器（`localStorage`），提交成功后自动清掉

**分类**（对标 bbs1 的「版块」）：在「分类」页增删改，博客页顶部会按排序显示一排 chips，
点一下只看这个分类（地址变成 `blog.html?cat=2`，可以直接分享）；文章页标题下面也显示所属分类。

删除分类时，它下面的文章会变成「未分类」，不会跟着被删——这条在测试里有专门的断言。

### 正文里插图片：三种方式

| 方式 | 怎么用 |
|---|---|
| **点工具栏「图片」按钮** | 打开文件选择框（可多选），选本地图片 → 自动上传 → 在光标处插 `![文件名](img/uploads/…)` |
| **粘贴** | 截图后 `Ctrl+V`（微信、QQ、系统截图都行） |
| **拖拽** | 从资源管理器把图片拖进编辑区任意位置（工具栏、预览区都算），拖进来时编辑区会显示虚线框 |

三条路走的是同一个流程：

1. 前端先拦一道：超过 5MB 的当场拒绝并提示「超过 5MB」，不用等传完；
2. POST 到 `admin/upload.php`（要登录态 + CSRF；未登录返回 401 JSON，不会跳登录页把 fetch 弄成 HTML）；
3. 服务端走和相册同一个 `save_upload()`：`finfo` 嗅探真实类型（**不看扩展名**）、5MB 上限、落盘后自动压缩；
4. 返回 `{ path: "img/uploads/20261030-xxxxxxxx.png" }`，编辑器插入 `![文件名](那个路径)`（截图没有像样的文件名时用「图片」当说明），
   工具栏右侧显示「已插入 1/1」，失败则红字提示原因。

一次选/拖/粘多张会一张张传、按顺序插入。**纯文字粘贴和拖文字不受影响**：没有图片文件时完全交给浏览器默认处理。
拖到编辑区**外面**也会被拦下来（否则浏览器会把图片当页面打开，正在写的正文就没了）。

### 「文字 + 图片」一起复制（网页 / 公众号 / Word）

单独复制一张图好办，但**从网页上连着文字一起选中复制**时，剪贴板里没有图片*文件*，
只有 HTML（`<img src="https://…">`）——什么都按文件找就会把图丢掉。所以粘贴分两条路：

| 剪贴板里有什么 | 怎么处理 |
|---|---|
| 图片**文件**（系统截图、资源管理器复制、Office 里的图） | 文字先落进正文，图片文件逐个上传后插在后面 |
| 只有 HTML，里面有 `<img>`（网页/公众号） | 解析 HTML：文字按段落/列表/标题/粗体/链接粗转成 Markdown，图片按下表处理 |

HTML 里的图分两种来源：

- `data:image/…;base64,…`（富文本编辑器内嵌的图）→ 浏览器里转成文件，POST 上传；
- `http(s)://…`（外链图床/CDN）→ **服务端抓回本地**：`upload.php` 带 `url` 参数，
  用 curl 下载到临时文件 → `finfo` 验类型 → 存进 `img/uploads/` → 走同一条压缩；
  这样以后图床挂了、对方开防盗链、站点关张，你的文章都还在。

抓不回来的情况（内网地址被 SSRF 防护挡住、防盗链、超时、非图片）**保留原始链接**并在状态栏写原因，
至少图片不会消失。远程抓取只允许公网 `http/https`，`127.0.0.1`、`10.x`、`192.168.x` 这类地址直接拒绝。

> HTTPS 抓取要根证书。Windows 版 PHP 默认不带，仓库里放了一份 `lib/cacert.pem`（curl 官方 bundle）；
> `php.ini` 里配了 `curl.cainfo` 就优先用你的。

预览区会把相对路径补成 `../img/uploads/…`，因为后台在 `/admin/` 下；同一段 Markdown
在后台预览能看到图、发到前台 `post.html` 也能看到图。

删文章**不会**连带删图（图片可能被别的文章引用，自动删很危险）。攒多了跑一次维护脚本：

```powershell
# 先看有哪些没人引用的图（10 分钟内刚传的会跳过）
php _preview\clean-uploads.php

# 确认没问题再真删
php _preview\clean-uploads.php apply
```

判断「有没有人用」会同时看两处：数据库里所有表的文本字段，**以及** `*.html` / `js/*.js` / `css/*.css` / `lib` / `admin` 里写死的文件名。
后者是必要的——首页音乐播放器的封面（`img/zainideshenbian.jpg`）不经过数据库，
只查表会把它当孤儿删掉，前台就裂图了。

> 那张 `img/zainideshenbian.jpg` 现在只给音乐播放器当封面用，相册里已经没有它了；
> 示例数据（`lib/db.php` 的 seed）和 `_preview/restore-seed.php` 都不再往相册里塞它。

### 上传的图片会自动压小

放进去就完事，不用先手动压一遍——`save_upload()` 落盘后会过一遍
`optimize_upload()`（`lib/bootstrap.php`），手机直出的几 MB 照片通常只剩几百 KB：

| 情况 | 处理 |
|---|---|
| 最长边 > 1920 或体积 > 400KB | 等比缩到最长边 1920（双三次插值） |
| JPEG | 先按 EXIF 方向**转正**，再重编码，质量 82 |
| PNG（有透明通道） | 保持 PNG（透明区不会变黑），只缩尺寸 + 压缩级别 6 |
| PNG（无透明通道） | 改存成同名的 `.jpg`（照片类 PNG 能小一个数量级），返回路径的后缀跟着变 |
| WebP | 重编码，质量 80 |
| GIF / SVG | 不碰（GIF 可能是动图，SVG 是矢量） |
| 本来就小（≤1920 且 ≤400KB） | 原样保留，不重编码、不掉画质 |
| 没装 GD / 格式不认 / 写不进去 / 压完反而更大 / 内存不够解这张图 | 保留原图，上传照常成功 |

实测一张 3200×2400 的照片：**1,040 KB → 304 KB**，尺寸变成 1920×1440。

**为什么要自己解析 EXIF 方向**：这台 PHP 没装 `exif` 扩展，而 GD 重编码会把 EXIF 丢掉。
不处理的话，手机竖拍的照片（像素是横的 + `orientation=6`）压完就躺着显示了。
所以 `jpeg_orientation()` 直接读 JPEG 的 APP1 段，`rotate_by_orientation()` 按 1–8 转正。

**内存保护**：解码一张 `宽×高` 的图大约要 `宽×高×4` 字节，缩放还要一份。所以动手前先按
`ini_bytes()` 读到的 `memory_limit` 估算，不够就跳过——硬解会在 `memory_limit` 上直接 OOM
（后台白屏），比"压不动"严重得多。本机 `memory_limit = 512M`，实测 48M 下会安全跳过。

背景图 `css/resources/2.jpg` 也一次性压过：**1,387 KB → 177 KB**（同分辨率、质量 62）。
它上面盖着 42%–66% 的白色渐变（深色模式是 82%–90% 的藏青），实测叠上渐变后差异为 0；
原图备份在 `_backup/img-original/2.jpg`，想换回来直接拷回去即可。
整个站的冷启动流量因此从 2,150 KB 降到 940 KB。

### 系统更新（后台直接从 GitHub 拉代码）

后台左侧多了「系统更新」。它只做一件事：**从 GitHub 仓库拿最新代码，覆盖服务器上的站点文件**。
默认仓库 `xiaowu1479/blog_xiaowu`、分支 `main`；换仓库/分支不用改代码，改 `data/update.json` 里的
`repo` / `branch` 就行。

| 按钮 | 干了什么 |
|---|---|
| 检查更新 | 问 GitHub 最新提交 → 下载源码包 → 逐文件比对 → **只列结论，不动任何文件** |
| 立即更新 | 重新取最新 → 下载 → 比对 → 备份 → 覆盖 → 记下版本号和文件台账 |

几个关键取舍（都是坑）：

- **不走 git。** 服务器不用装 git，也不会出现「本地改过导致 pull 冲突」。直接下 zipball 覆盖。
- **永不覆盖：** `data/`（数据库、安装锁、本页配置）、`img/uploads/`（你传的图）、`_backup/`、`_preview/`。
- **删得很保守：** 只删「上一次是更新程序装的、这一版上游没有了」的文件。第一次更新一个都不删。
- **动过就告诉你：** 清单里标「本地改过，会覆盖」的文件，是你在服务器上手改过的，更新会冲掉。
- **覆盖前先备份：** 进 `_backup/update-<时间>/`，只留最近 3 份。
- **能回滚：** 备份里记着「这次动了哪些文件」，历史里点「回滚」就按记录反着来——
  这次新增的删掉、改过的从备份还原、版本号退回上一版。
- **路径穿越挡掉：** 包里的 `../x`、`data/x`、`img/uploads/x` 一律跳过（测试里专门验了）。

第一次用的顺序：

1. 站点推到仓库，等 GitHub 上有 `index.html`（仓库里光有 README 时，检查更新会明确告诉你「没有 index.html」）；
2. 后台点「检查更新」——如果内容和仓库一致，它会说「内容和仓库一致，已把当前版本记为 xxxxxxx」，
   这一步同时**建立文件台账**（以后才知道哪些文件是你自己改的）；
3. 以后本地改完 → `git push` → 后台「检查更新」看清单 → 「立即更新」。

补充三件事：

- **私有仓库：** 在 `data/update.json` 的 `"token"` 里填一个只有读权限的 PAT。公开仓库留空即可
  （填了也能少受 GitHub 匿名限流的影响）。
- **公司代理 / 自签证书：** 把代理的根证书（PEM）放到 `data/cacert.local.pem`，会拼在官方证书包后面一起用；
  没这个文件就只用 `lib/cacert.pem`。
- **服务器没外网：** 把源码包（GitHub zipball 或自己 `zip -r` 打的包）放上去，路径填进
  `"localZip"`，更新时只读本地包，不联网（比缓存和下载都优先）。

两件省心的小事：

- **一次只跑一个。** 下载要几十秒，这期间再点一次会提示「上一次还在跑」，不会两个请求互相踩。
- **同一个版本只下一次。** 包存在 `data/update-cache/`，再查同一个 commit 直接用，不重复拉 10MB。

**如果报 504 / 页面刷出来是空的：** nginx 的 `fastcgi_read_timeout` 默认只有 60 秒，慢网络下
下载会超过它（PHP 那边其实还在跑完）。给这个站点加一行就好：

```nginx
location ~ \.php$ {
    fastcgi_read_timeout 180s;   # 系统更新要下载 + 解压 + 逐文件比对
}
```

## 三、内容是怎么流到前台的

```
后台表单 → lib/repo.php → data/site.db
                              ↓
                          api.php（JSON）
                              ↓
        index.html 里 js/site.js 的 hydrate()
                              ↓
                    renderIdentity / renderWorks / … 注入 DOM
```

`hydrate()` 拿不到接口（PHP 没跑、断网、`file://` 打开）就退回 `js/site.js` 顶部的 `SITE` 常量，
所以**后台挂了、或者接口报错，页面都不会白屏**（接口未安装/库丢失时返回 503，前台照样能看）。

判断当前页面用的是哪份数据：

```js
document.documentElement.dataset.content   // 'api' 或 'fallback'
```

### 六个页面怎么共用一套外壳

首页的「博客」版块**只放最新 3 篇**（`site_payload()` 里 `array_slice(…, 0, 3)`），
所以文章发到几百篇，首页高度也不会跟着涨。完整列表和搜索在独立页：

- `blog.html` → 请求 `api.php?posts=1&q=关键词&page=2&per=8`，返回 `{ total, page, pages, items[] }`。
  搜索是对 `title / excerpt / content` 做 `LIKE`（`%` `_` `\` 都走了 `ESCAPE`，搜 `100%` 不会变成通配符），
  换页和搜索都会同步进地址栏，可以直接分享 `blog.html?q=CSS&page=2` 这种链接；
- `post.html?id=N` → 请求 `api.php?post=N`，拿到正文、上下篇、分类。正文交给 `js/markdown.js`
  渲染成 HTML（**先整体转义再套语法**，所以正文里写 `<script>` 只会显示成文字），
  同时把标题、摘要、封面写进 `description` / `og:*` / `canonical`，再加一段 `BlogPosting` 的 JSON-LD——
  这就是分享到 QQ / 微信 / Twitter 时那张名片，也是搜索引擎判断"这是一篇文章"的依据；
- 分类：`api.php?cats=1` 返回分类和每类篇数，列表接口里也顺带一份，博客页顶部的 chips 就是它
  （`blog.html?cat=2` 可以直接分享，搜索和分类可以叠加）。
  正文按**纯文本**渲染（分空行成段），后台正文里写 `<style>` 这类字样不会被当成标签；
- 每页 8 篇，改 `js/blog.js` 顶部的 `PER` 即可。

五个子页面（`works` / `blog` / `gallery` / `links` / `post`）的侧栏和 5 个弹窗**不重复写**，
而是由 `js/shell.js` 注入，页面里只留一行：

```html
<aside class="sidebar" id="sidebar" data-active="works" data-sub="全部作品"></aside>
```

`data-active` 决定哪一项高亮（`home/works/blog/gallery/links`），`data-sub` 是名字下面那行小字。
**加菜单项只改 `js/shell.js` 里的 `NAV` 数组，一处生效**；首页 `index.html` 的侧栏是手写的静态版
（首屏不依赖 JS），改菜单时那边也要同步一下。

代价：子页面没有 JS 就没有导航。前台内容本来就是接口渲染的，所以这个前提一直存在。

### 换页时侧栏不闪

多页面站点最容易犯的毛病：每跳一页，左侧菜单「重画」一次，看着抖一下。这里做了四件事：

| 措施 | 为什么 |
|---|---|
| `js/shell.js` 紧跟 `</aside>` 同步执行 | 侧栏在第一次绘制前就有内容，不会出现「空侧栏 → 突然填满」 |
| 图标字体 `<link rel="preload">`（`fa-solid` / `fa-regular`） | 不预载时首帧只有文字、图标晚几帧才出现；实测有预载第 1 帧就绪，没有要到第 3~5 帧 |
| `js/dark.js` 放在 `<body>` 之后立刻执行 | 深色模式首帧就生效，不会先浅色再变深（换页时的整体闪白） |
| 名字 / 头像用 `localStorage` 记住（`zhs-name` / `zhs-avatar`） | `site.js` 要等接口回来才填，不缓存就是「默认名 → 真名」跳一下 |

另外，侧栏菜单、**从列表点进文章**、文章页的「全部文章」和「上一篇 / 下一篇」
都不整页重载，由 `js/router.js` 只替换 `main.main`，侧栏、弹窗、播放器原地不动
（实测换页前后 `#sidebar` 是同一个 DOM 节点）。该整页跳的（带 `?page=` 的分页、外链）照旧整页加载。

## 四、安全这块做了什么

| 风险 | 处理 |
|---|---|
| 数据库被直接下载 | 三层拦截：`nginx.htaccess`（nginx）、`data/.htaccess`（Apache）、`router.php`（`php -S`）。**换服务器类型要补对应那一层** |
| 密码明文 | `password_hash()` 存 bcrypt，登录用 `password_verify()` |
| 登录态伪造 | 无状态 HMAC Cookie：`id.expire.hmac(id\|expire, 密码哈希)`，改密码即让所有旧 Cookie 失效 |
| CSRF | 双提交 Cookie（随机 32 字节 + `hash_equals` 比对），所有写操作强制 POST |
| 暴力破解 | 同 IP 15 分钟内错 8 次即锁定，计数器带文件锁 |
| 上传木马 | `finfo` 读真实 MIME，白名单反查扩展名（改名重命名），大小上限 5MB |
| SQL 注入 | 一律 PDO 预处理绑定；写库字段走白名单 `table_columns()` |
| XSS | 输出统一过 `h()`（`htmlspecialchars` + `ENT_QUOTES`） |
| 表单伪造字段 | `row_save()` 按白名单裁剪，多传的字段直接丢弃 |
| 安装页被反复利用 | `data/install.lock` 存在即 403；装完没有任何"重装"入口 |
| 库被误删后静默重建 | 锁在而库丢失时，`/admin/` 明确报错、`api.php` 返回 503，**不会**重建成带默认密码的空站 |

## 五、想换成 MySQL 怎么办

存储层被刻意收敛到两个文件，改动量很小：

1. `lib/db.php` 里 `new PDO('sqlite:' . db_file())` 换成
   `new PDO('mysql:host=127.0.0.1;dbname=zhanshiye;charset=utf8mb4', 'root', '密码')`；
2. 建表语句里 `INTEGER PRIMARY KEY AUTOINCREMENT` 改 `INT AUTO_INCREMENT PRIMARY KEY`；
3. `INSERT ... ON CONFLICT(name) DO UPDATE` 改 `ON DUPLICATE KEY UPDATE`（只有 `setting_set` 一处）。

`repo.php`、`api.php`、`admin/index.php` 一行都不用动。

## 六、这套写法是从哪学的

后台的鉴权、CSRF、助手函数风格参考了 `WWW/bbs1.com`（bbs1org，一个单文件 PHP 论坛）：

**抄了**：无状态 HMAC Cookie 鉴权、双提交 CSRF、键值对设置表、`q/one/val` 这种极薄 SQL 助手、
`post($key, $maxLen)` 式的统一输入闸门、登录失败限流。

**没抄**：三引擎数据库抽象层（300+ 行，只为跨库可移植）、插件/Hook 系统（82KB，个人站不需要）、
3263 行单文件（改为 5 个文件分层）、论坛的数据模型。

## 七、怎么验证

```powershell
# 安装流程（13 项：未装跳转、自检、弱密码/不一致被拒、装完锁定、旧密码失效、示例内容）
# 注意：会清空 data/ 里的库，先把 data/ 备份出去
node _preview\testinstall.mjs

# 后台端到端（27 项：登录、CSRF、篡改签名、增删改、显隐、上传、非法文件、限流链路、八个面板可打开）
$env:SITE_BASE='http://zhanshiye:779'; node _preview\testadmin.mjs

# 前台渲染审计（布局/溢出/计数/弹窗/深色/控制台报错）
$env:SITE_URL='http://zhanshiye:779/'; node _preview\audit.mjs 1440
node _preview\audit.mjs 375

# 截图
$env:SITE_URL='http://zhanshiye:779/'; node _preview\shots.mjs 1440
$env:ADMIN_URL='http://zhanshiye:779/admin/index.php'; node _preview\shots-admin.mjs

# 博客（31 项：列表、搜索命中正文、清空、进文章页、上下篇、404 兜底、首页只放 3 篇、侧栏一致性、弹窗、移动端）
$env:SITE_BASE='http://zhanshiye:779'; node _preview\test-blog.mjs

# 多页面结构（14 项）：每个菜单页的数据渲染、侧栏 8 项与高亮、深浅色记忆、移动端抽屉
$env:SITE_BASE='http://zhanshiye:779'; node _preview\test-pages.mjs

# 菜单跳转（8 项）：五个菜单各自进自己的页面、移动端抽屉点完自动收起
$env:SITE_BASE='http://zhanshiye:779'; node _preview\check-nav.mjs

# 分页（8 项）：会临时插 6 篇测试文章，跑完自动删掉
$env:SITE_BASE='http://zhanshiye:779'; node _preview\test-blog-pager.mjs

# 后台列表布局诊断（卡片/表格宽度、列宽、表头是否被裁；参数是页面名与视口宽）
node _preview\diag-admin.mjs posts 1440

# 分类 + Markdown + 图片上传 + 分享名片（83 项）：编辑器工具栏/实时预览、按钮选图/粘贴/拖拽三条路、「文字+图片」「文件+文字」混合粘贴、
# 超大图拦截、假图片被服务端拒、远程图导入与 SSRF 防护、chips 筛选、文章页渲染、分类增删改、
# 正文 XSS 转义、老纯文本文章仍能显示、375px 不横向溢出、跑完数据回到原样
node _preview\check-cats-md.mjs

# 系统更新（30 项）：未登录拦截、页面渲染、真连 GitHub 拿提交、本地包模拟一次真更新、
# 保护目录与路径穿越、备份能还原、data/site.db 没被动、版本与台账落盘、
# data/update.json 不可下载、回滚把改动退回去
node _preview/test-update.mjs

# 跑完测试会在 img/uploads 留几张测试图（删记录不删文件），顺手清一下
php _preview\clean-uploads.php apply --force

# 图片压缩（11 项）：普通大图、竖拍 EXIF 转正、透明 PNG、无透明 PNG 转 JPEG、小图跳过

php _preview\test-imgopt.php
```

## 八、已知限制

- 单管理员，没有多用户/权限组（个人站用不上）；
- 安装器只支持 SQLite（MySQL 那条路我没实测过，所以没放进安装页）；
- 首页现在经过 `index.php` 守卫，**不再支持"纯静态托管"**（前台渲染本身仍是静态的）；
- 博客正文是 Markdown，但只做了够用的子集：**没有表格、脚注、嵌套列表、任务列表**；
  单个换行会渲染成 `<br>`（标准 Markdown 是当空格），这样以前那种纯文本正文不会挤成一团；
- Markdown 渲染在浏览器里做（`js/markdown.js`），所以正则量级 O(行数)，几万字没问题，但不做代码高亮；
- 分享名片（`og:*` / JSON-LD）是 JS 拉完接口才写进 `<head>` 的，**爬虫抓不到**——
  要让搜索引擎看到全文和元数据，得把 `post.html` 换成 `post.php?id=` 直出（见上一节最后一条限制）；
- 博客搜索是 SQL `LIKE` 扫 `title/excerpt/content`，几百篇没感觉，上万篇就该换 SQLite FTS5 索引；
- 所有子页面（`works` / `blog` / `post` / `gallery` / `links`）的内容都是 JS 拉接口后渲染的，搜索引擎抓到的是空壳（要收录得改成 PHP 直出或加预渲染）；
- 首页和子页面的侧栏是两份：子页面走 `js/shell.js`，首页是手写静态版，加菜单项时两处都要改；
- 删文章不会顺带删它用过的图片（怕误删被别的文章引用的图），要回收空间就跑 `php _preview\clean-uploads.php apply`；
- 没有版本回滚：改错内容只能手动改回来（`data/site.db` 建议定期拷一份）；
- 相册没有分页，几百张图会有点重；
- 带透明通道的照片类 PNG 压不动（PNG 是无损格式，2,200 KB 只降到 1,860 KB）；想更小得转 WebP，没做；
- GIF 上传后原样保留（可能是动图，GD 重编码会丢帧）；
- `css/style.css`、`js/player.js`、`js/dark.js` 仍是模板原文件，没有改动。

## 九、下一步可以做的

1. 文章标签（分类已经有了，标签是"一篇多个"的另一个维度）＋ `rss.xml` 订阅源；
2. 相册懒加载 + 分页；
3. 后台加「导出 JSON / 导入 JSON」，方便迁移和备份；
4. 安装页增加"从备份恢复"选项（直接上传一个 site.db）；
5. 前台深浅色跟随系统（现在只记本地选择）；
6. 把 `_preview/`、`_backup/`、几个压缩包在上线前删掉。
