# FeiFeiCMS 后台视觉审查与重构记录

审查日期：2026-09-28

## 视觉基线

- 用户原始截图：`reference/user-vod-create-before.png`，3420 × 1480，Retina 2x；按 1710 × 740 CSS 视口归一化后比较。
- 原版结构源码：`legacy/Public/system/*.html`。
- 原版视觉样式：`legacy/Public/css/admin-top.css`、`legacy/Public/css/admin-left.css`、`legacy/Public/css/admin-style.css`。
- 原版图像资产：`legacy/Public/images/admin/*`，现代后台继续直接使用同一批顶部、标题和按钮纹理，没有用新图标或近似素材替代。

## 修改前发现

1. P1：视频编辑器使用四列响应式栅格，宽屏上字段被拆散，标签与输入框之间出现不必要的大段空白。
2. P1：兼属分类使用横跨页面的多选框，形成巨大空白区，和原版 FeiFeiCMS 的单行编辑结构不符。
3. P2：分类、用户、内容、运营等编辑器共享同一四列规则，因此在不同窗口宽度下存在同类错位风险。
4. P2：采集源说明文字脱离字段行，字段层级和原版 `tl / tr` 表格结构不一致。
5. P2：后台首页先展示现代统计卡片，原版最醒目的系统环境表被移到页面下方。
6. P2：分类管理页存在内联 `style`，被当前 CSP 拦截并产生浏览器控制台错误。

## 重构结果

- 保留 82px 顶部框架、140px 左侧菜单、99% 主内容宽度、13px 微软雅黑/宋体字体栈、蓝色纹理、方角按钮和原版边框色。
- 所有 `.form-grid` 统一回归原版 200px 标签列加内容列，不再按两列、三列、四列横向拉伸。
- 视频基本资料按原版 `vod_add.html` 的同一行组合方式重排；兼属分类改为行内复选项。
- 采集源编辑器改为同一套 `legacy-form-row / legacy-form-label / legacy-form-field` 结构。
- 后台首页重新把系统基本信息表放在首屏，保留现代统计和快捷入口作为原版风格的扩展区。
- 移除后台模板内联样式，CSS/JS 资源版本更新到 v11。
- 保留标签页切换、批量选择、批量操作、状态翻译、空数据行、确认弹窗和移动端侧栏等全部交互。
- 线路/分集编辑器用一条临时线路和两集数据完成有数据状态复查；播放地址列自适应伸展，其他紧凑列保留原版密度。

## 截图目录

- 修改前完整桌面路由：`screenshots/before/`
- 第一轮实现：`screenshots/after-pass-1/`
- 第二轮实现：`screenshots/after-pass-2/`
- 最终第三轮实现（含有数据的线路/分集编辑器）：`screenshots/after-pass-3/`
- 2026-09-29 全后台最终复验（33 张，含桌面/宽屏/移动端）：`screenshots/full-final-20260929/`
- 单页宽屏前后对比：`comparisons/vod-create-before-after.png`
- 全部桌面路由前后总览：`comparisons/desktop-all-pages-before-after.png`
- 修改前总览：`comparisons/before-desktop-contact-sheet.png`
- 修改后总览：`comparisons/after-pass-3-desktop-contact-sheet.png`

桌面路由覆盖后台首页、视频列表/新增、分类列表/新增、导航、采集列表/新增、文章、人物、专题、评论、标签、用户列表/新增、管理员、运营、系统设置、系统运维、数据库和有数据的线路/分集编辑器。移动端覆盖后台首页、视频列表和新增视频。

## 验收

- 26 张最终截图均成功生成，其中包含有线路与两条分集的播放编辑器。
- 1440 × 900、1710 × 740、390 × 844 三种视口均已检查。
- 最终截图采集时浏览器控制台错误：0。
- 27 个后台页面与静态资源 URL：HTTP 200。
- PHP 8.5.10 PHPUnit：17 个测试、119 个断言通过。
- PHP 语法检查：通过。

最终结果：通过。
