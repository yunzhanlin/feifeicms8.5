# MXOne 示例模板与标签调用

`view/mxone` 是 FeiFeiCMS 8.5 Beta 自带的完整示例模板。它既是可直接使用的前台，也是新模板开发的字段、URL 和交互参考。浏览器打开 `/features` 可查看真实数据演示。

## 页面与路由

| 功能 | URL | 模板 |
| --- | --- | --- |
| 首页 | `/` | `index/index.html` |
| 分类筛选 | `/list/{id}` | `vod/type.html` |
| 搜索 | `/search?wd=关键词` | `search/index.html` |
| 视频详情 | `/vod/{id}` | `vod/detail.html` |
| 播放 | `/play/{id}/{line}/{episode}.html` | `vod/play.html` |
| 分集剧情 | `/vod/{id}/scenarios` | `vod/scenarios.html` |
| 剧情详情 | `/scenario/{id}` | `vod/scenario.html` |
| 资讯 / 专题 / 人物 | `/news` / `/special` / `/person` | 对应目录 |
| 留言 / 会员 / VIP | `/guestbook` / `/user/center` / `/vip` | 对应目录 |
| 站点地图 / RSS | `/map` / `/rss` | `page` 目录 |
| 功能示例 | `/features` | `page/features.html` |

播放线路和分集在公开 URL 中从 `1` 开始；模板函数 `ff_play_url()` 接受的内部下标从 `0` 开始，函数会自动转为公开 URL。

## 全局变量

- `siteName` / `seo` / `pageActive`：站点与 SEO 信息。
- `types` / `navCategories`：顶级视频分类。
- `siteNavigation` / `siteNavigationChildren`：自定义导航及子导航。
- `siteSlides`：当前有效的轮播图。
- `siteAds`：以广告位键为 key 的广告内容。
- `friendLinks`：启用的友情链接。
- `hotSearches`：后台配置的热门搜索。
- `frontendUi`：播放记录数、轮播间隔、选集折叠数等界面参数。

## FeiFeiCMS 兼容查询标签

```html
{volist name=":ff_mysql_list('limit:20')" id="type"}
  <a href="{:ff_url('type',$type.list_id)}">{$type.list_name}</a>
{/volist}

{volist name=":ff_mysql_vod('cid:1;limit:12;order:vod_hits;sort:desc')" id="vod"}
  <a href="{:ff_url('vod',$vod.vod_id)}">{$vod.vod_name}</a>
{/volist}
```

可用函数：`ff_mysql_list()`、`ff_mysql_vod()`、`ff_mysql_news()`、`ff_mysql_special()`、`ff_mysql_star()`、`ff_mysql_role()`、`ff_mysql_tags()`、`ff_mysql_scenario()`、`ff_mysql_forum()`、`ff_mysql_slide()`、`ff_mysql_nav()`、`ff_mysql_link()` 和 `ff_mysql_ads()`。

标签参数使用分号分隔，例如 `cid:1;limit:12;order:vod_hits;sort:desc`。返回项同时保留现代字段和 `vod_*` / `news_*` / `special_*` / `person_*` 兼容字段。

## URL 函数

```html
{:ff_url('home')}
{:ff_url('type',$type.list_id)}
{:ff_url('vod',$vod.vod_id)}
{:ff_play_url($vod.vod_id,0,0)}
{:ff_url('scenario',['id'=>$scenario.scenario_id])}
```

URL 规则由后台“系统 → URL 优化”配置，模板不应硬编码伪静态路径。

## 广告位与运营组件

示例模板直接支持 `header`、`home_top`、`home_middle`、`vod_detail`、`vod_play` 和 `footer` 广告位。自定义导航不会覆盖系统核心页面；友情链接支持文字和图片。

## 播放与交互

- 多线路切换、正序/倒序选集、长选集折叠。
- HLS / 直链 / 解析器播放，以及弹幕读取和发送。
- 评分、顶踩、收藏、播放记录、评论、积分解锁和 VIP 权限。
- 详情页展示豆瓣/IMDb ID、标签、演职人员、附件、分集剧情、经典台词、看点和结局。

## API 调用

```text
GET /api.php/provide/vod?ac=list
GET /api.php/provide/vod?ac=detail&ids=16
GET /api/provide/vod?ac=detail&t=1&pg=1
```

## 创建新模板

1. 复制 `view/mxone` 为 `view/新模板名`。
2. 保留对应页面目录和公共模板。
3. 在后台“全局配置 → 基本配置”选择新模板。
4. 使用 `ff_url()` 生成地址，使用兼容标签或控制器传入变量取数。
5. 在动态、Rewrite 和自定义 URL 模式下分别验证详情、播放和分页。
