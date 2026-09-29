# URL 优化 / 伪静态对齐验收（2026-09-29）

## 视觉基线

- 原版 FeiFeiCMS 7.4：`http://127.0.0.1:8090/index.php?s=Admin-Config-Rewrite`
- ThinkPHP 8 版：`http://feifeicms-modern.localhost:19101/admin/settings/rewrite`
- 对齐结果：保留原版标题条、4 行配置、128px 选择框、700x280 规则编辑器、规则说明、提交/重置按钮和左侧系统菜单。

## 功能验收

1. `.html` 正式配置：首页输出 `/vod/16.html`，详情、播放、文章、分类地址均返回 HTTP 200。
2. `.htm` 切换：首页改为 `/vod/16.htm`，详情和播放地址返回 HTTP 200；测试后已恢复 `.html`。
3. 关闭伪静态：首页生成 `/index.php?s=/Vod/read/id/16.html`，页面返回 HTTP 200；测试后已恢复开启。
4. 7.4 自定义格式：`vod-read-id-(:num)===video/detail/(:num)` 生成 `/video/detail/16.html`，入站解析返回正确影片页 HTTP 200；测试后已恢复默认 `/vod/(:num)`。
5. 异常规则：占位符数量不一致时显示“第 1 行两侧占位符数量不一致”，原有规则未被覆盖。
6. 未命中规则的地址 `/not-a-real-page` 返回 HTTP 404，不会被动态路由误接管。
7. PHP 8.5.10 语法检查通过；PHPUnit `45/45`，`343 assertions`通过。

## 当前正式状态

- 路径后缀：`.html`
- 伪静态重写：开启
- URL 自定义：开启
- 默认视频详情：`/vod/(:num).html`
- 默认播放地址：`/play/(:num)/(:num)/(:num).html`
