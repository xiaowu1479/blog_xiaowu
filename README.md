# 小悟大人的个人主页

个人站源码：前台是纯静态页面，后台用 PHP 8 + SQLite 管理内容。
无框架、无构建步骤、无 npm / Composer 依赖。

## 功能

**前台**：首页、作品集、博客（分类 / 搜索 / 分页）、文章页、相册、友链。
带深浅色模式、樱花飘落、音乐卡片、关于与旗下站点弹窗，支持友链在线申请。

**后台**：站点信息、作品、博客（Markdown 编辑器）、分类、相册、友链与申请审核、
媒体库（上传一次多处复用）、社交图标、修改密码、系统更新与版本回退。

内容统一存在 SQLite，前台通过 `api.php` 取 JSON 渲染；接口不可用时回退到
`js/site.js` 内置的默认内容，页面不会白屏。

## 技术架构

```
浏览器
  ├─ 静态页面 index / works / blog / post / gallery / links.html
  │    ├─ js/site.js     内容渲染
  │    ├─ js/shell.js    子页面共用的侧栏与弹窗
  │    └─ js/router.js   站内跳转只替换正文，不整页刷新
  └─ api.php → lib/repo.php → data/site.db（SQLite）
        ↑
     admin/ 后台：登录后编辑内容、上传图片、审核友链
```

- **前端**：原生 HTML / CSS / JavaScript，无构建
- **后端**：PHP 8 + PDO SQLite，无框架和第三方依赖
- **安装**：`index.php` 做安装守卫，`install.php` 建库并初始化管理员，装完自动锁定

## 目录结构

```
index.html / works.html / blog.html / post.html / gallery.html / links.html
api.php             前台数据接口
index.php           安装守卫
install.php         安装页
admin/              后台（内容管理、媒体库、系统更新）
lib/                基础设施：启动、数据库、数据访问、媒体库
css/ js/ img/       样式、脚本、图片资源
data/               SQLite 数据库与安装标记（不对外访问，不进仓库）
```

## 快速部署

1. 上传到支持 PHP 8 的空间，站点根目录指向项目根；
2. 给 `data/`、`img/` 写权限；
3. 浏览器打开站点根目录，按安装页提示填写站点名、管理员账号和密码；
4. 装完进入 `/admin/` 登录，先改密码，再替换成自己的内容。

数据、上传的图片都落在 `data/` 和 `img/uploads/`，迁移或备份时整目录拷走即可；
仓库已忽略这两处，不会把站点内容推到 GitHub。

## 说明

- 单管理员，仅支持 SQLite；
- 前台内容由 JS 拉接口后渲染，搜索引擎收录效果有限；
- 后台「系统更新」可直接从 GitHub 拉取最新源码，并支持回退到历史版本。

## 致谢

界面由网络上的单页模板二开而来；后台鉴权与 CSRF 的写法参考了 bbs1org。
