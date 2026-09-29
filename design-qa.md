# FeiFeiCMS 视频编辑页 Design QA

## Comparison target

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_cnKjn0/截屏2026-09-29 上午9.49.58.png`.
- Live structural reference: `http://127.0.0.1:8090/index.php?s=Admin-Vod-Add-cid-1-id-73382` (FeiFeiCMS 7.4, logged-in basic editor state).
- Implementation: `http://feifeicms-modern.localhost:19101/admin/vod/1007/edit?qa=final`.
- Implementation capture: Chrome/CUA final viewport capture embedded in the task; this browser surface does not expose a filesystem screenshot path.
- Source image dimensions: 3212 × 1514 px. The supplied view is a HiDPI capture and was normalized visually to approximately 1606 × 757 CSS px.
- Live reference and implementation comparison: captured together in one comparison call at the same Chrome viewport, 1710 × 863 CSS px; implementation screenshot output is 1710 × 863 px at effective density 1.
- State: logged-in desktop, 编辑视频/基本资料 panel, existing real records, no test changes submitted. After interaction tests the implementation was navigated afresh and verified to show the original media title, source marker, and two original playback lines.

## Full-view comparison evidence

- Information order now matches the reference: tabs, 豆瓣资料工具条, name/category/access/status, subtitle/payment/trial/inline submit, alias/episode shortcuts, schedule/date, TV/length/copyright, source marker/Douban score/IMDb, extended type, tags, three image rows, year/area/language quick choices, playback sources, introduction and footer actions.
- The TP8 page intentionally retains the global FeiFeiCMS top/side navigation and the normalized multi-line playback fields. These consume width compared with the inner-frame-only 7.4 live capture but preserve the site-wide 4.3 administration structure shown in the earlier full-page references.
- The source and implementation use the same compact blue table grammar, square native controls, light-blue separators, blue link tokens, original GIF navigation assets, and dense Arial/Sans-serif typography. No rounded cards, replacement SVGs, generated art, gradients, or placeholder imagery were introduced.

## Focused-region comparison evidence

- The combined same-viewport capture made the top form region readable without a separate crop. Field labels, row ordering, button groups, widths, checkbox state, and quick-choice wrapping were checked directly.
- Focused DOM checks confirmed `免费观看`, an unchecked “使用系统当前时间” checkbox, `中国大陆` as the first normalized area choice, visible `source_ref`, a first-class IMDb input, and `/admin/scenarios?media_id=1007` as the independent scenario link.

## Required fidelity surfaces

- Fonts and typography: legacy Arial/system-sans scale, normal weights, compact line height, and blue label/link hierarchy match. Long real titles stay on one line inside the reference-width inputs.
- Spacing and layout rhythm: row order, 39 px table rhythm, fixed 200 px label column, dense inline controls, dividers, tab strip, and footer action group match the FeiFei pattern. The global sidebar remains an intentional product constraint.
- Colors and tokens: original dark-blue header, pale-blue active/navigation surfaces, `#135294` labels, blue quick links, green success text, and red error text retain semantic contrast.
- Image quality and assets: original FeiFeiCMS raster navigation assets remain in use. The reference contains no content illustration that needs replacement; poster URLs are data, not design assets.
- Copy and content: reference labels are retained, including `豆瓣资料`, `来源标识`, `IMDb编号`, `数据提交`, and episode shortcuts. `免费观看` is aligned with 7.4; the area choice intentionally says `中国大陆` to honor the requested collector normalization contract.
- States and interactions: tested tab switching, quick episode fill, append-style area choice, platform URL to a new Tencent playback line, live Douban 1292052 form fill, upload-without-file error state, fresh navigation restore, and browser console errors. No data was submitted during these browser tests; console error count was zero.
- Accessibility and responsiveness: native inputs/selects/buttons keep keyboard semantics and focus behavior, the update checkbox has an accessible label, and the legacy mobile policy retains a horizontally scrollable desktop table instead of collapsing field relationships.

## Comparison history

1. Initial implementation comparison found P1 missing reference controls and P2 field-order drift: no working Douban toolbar, platform-line import, quick episode/type/tag/year/area/language choices, or inline image uploads; status/IMDb were separated from their reference rows.
   - Fix: rebuilt the basic panel in reference order, added real controller endpoints and browser interactions, kept scenarios independent, and retained normalized playback storage.
   - Post-fix evidence: first rendered TP8 capture showed all reference groups in the correct order and the interaction run produced `HD国语`, appended an area, generated a Tencent line, and filled the form from live Douban data.
2. Second comparison found P2 copy/state drift: `免费点播`, a checked update-time control, and `中国内地` differed from the intended behavior and normalization rule.
   - Fix: changed the label to `免费观看`, made update-time opt-in, and changed the quick choice to `中国大陆`.
   - Post-fix evidence: final DOM check returned `accessText=免费观看`, `refreshChecked=false`, `firstAreaChoice=中国大陆`, with zero console errors.
3. Final pass: no actionable P0/P1/P2 visual or interaction findings remain.

## Residual test notes

- Actual binary upload was not performed in browser QA because that would upload a user file; the route, CSRF path, MIME allowlist, 20 MB limit, generated filename, audit data, JavaScript empty/error flow, and field write-back code are covered by source checks and the passing suite.
- Obsolete CKEditor 4.7.3 from the legacy sample was not reintroduced. The content textarea remains safe native HTML input while preserving its original table position; a maintained rich-text editor can be added as a separate security-reviewed enhancement.

## Implementation checklist

- [x] 7.4 field order and compact visual structure.
- [x] Douban open/search/fetch workflow and visible status.
- [x] Platform link to playback-line workflow.
- [x] Source marker, Douban score, IMDb, episode shortcuts and update-time behavior.
- [x] Type/tag/year/area/language/version/state quick choices.
- [x] Poster/slide/backdrop upload controls and hardened endpoint.
- [x] Independent scenario relationship retained.
- [x] PHP 8.5 lint, complete automated test suite and browser/console verification.

## 视频管理左侧栏补充验收（2026-09-29）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_bzfDp6/截屏2026-09-29 上午9.57.52.png`，756 × 1412 px，FeiFeiCMS 视频管理左栏与列表局部截图。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/vod`，Chrome 登录态；浏览器渲染证据已嵌入本任务，视口 1710 × 863 CSS px，`devicePixelRatio=2`，左栏渲染尺寸 140 × 664 CSS px。
- State: 视频管理列表默认状态，真实数据库内容，无测试数据写入；逐项导航后回到默认列表。
- Full-view comparison evidence: 最终浏览器截图确认保留 FeiFeiCMS 深蓝标题、浅蓝表格、方形按钮、紧凑字号和无圆角卡片的后台语法；左侧功能按参考图由上至下排列，且没有挤压右侧筛选与列表区域。
- Focused-region comparison evidence: 参考图和实现的左栏逐项核对，18 项文案与顺序一致；实现保留旧版分隔节奏，并将“需支付影币”而非旧临时文案“需支付积分”作为最终标签。

### Required fidelity surfaces

- Fonts and typography: 使用原后台 Arial/系统无衬线字体、正常字重、紧凑行高和蓝色链接层级；没有放大控件或引入现代卡片字体。
- Spacing and layout rhythm: 140 px 固定左栏、紧凑的按钮间距、方形边框和列表表格对齐；1710 px 视口无横向溢出。
- Colors and visual tokens: 继续使用原后台深蓝渐变标题、浅蓝分隔线、白色菜单底和蓝色文字；活动项对比明确。
- Image quality and assets: 此局部目标没有新增内容图片；顶部仍使用既有 FeiFeiCMS 导航资产，没有用 SVG、Emoji 或 CSS 图形替代。
- Copy and content: `待复查`、三项豆瓣工具、`分集剧情补全`、锁定/播放/剧情/台词/豆瓣编号/VIP/影币/试看/系列/封面/重名检测均按参考图保留。
- States and interactions: 实际打开并验收全部 18 个入口；所有页面均正常渲染，无 SQLSTATE、异常、模板错误或不存在页面；`豆瓣评论采集`正确进入采集控制台。浏览器控制台 error 数量为 0。
- Accessibility and responsiveness: 菜单仍为语义化链接并保留键盘导航；当前桌面视口无横向溢出，左栏滚动高度与可视高度相容。

### Comparison history

1. 初次部署后浏览器仍显示旧缓存菜单（仅 10 项），与参考图存在 P1 功能缺失。
   - Fix: 清除 ThinkPHP 视图缓存并重新加载已部署模板；同时补全对应的控制器筛选条件和查询参数保留逻辑。
   - Post-fix evidence: DOM 返回 18 项完整列表，浏览器截图显示新菜单，18 个目标路由逐项通过，控制台无错误。
2. 最终复核：没有剩余可执行的 P0/P1/P2 视觉或交互问题。

### Sidebar implementation checklist

- [x] 18 项功能文案和顺序与参考图一致。
- [x] 豆瓣资料补全、豆瓣 ID 查重和分集剧情补全具备真实筛选逻辑。
- [x] 锁定、无播放、有剧情/台词/豆瓣编号、VIP、影币、试看、系列、无封面和重名检测具备真实筛选逻辑。
- [x] 搜索支持片名、副标题、主演和导演，并保留当前左栏筛选条件。
- [x] PHP 自动化测试、线上逐路由检查、浏览器视觉检查和控制台检查通过。

## 系统首页与配置模块补充验收（2026-09-29）

- Source visual truth: `docs/qa/system-user-reference-2026-09-29.png`，1776 × 1408 px（用户原图已从临时目录留存）；同时以同一 Chrome 窗口中的 `http://127.0.0.1:8090/index.php?s=Admin-Index` 作为可交互的 FeiFeiCMS 7.4 参考页。
- Implementation: `http://feifeicms-modern.localhost:19101/admin`，真实 PHP 8.5/MySQL 8.4 环境数据、真实登录态，无伪造卡片或测试数据。
- Comparison capture: `docs/qa/system-reference-7.4-1710x863.png` 与 `docs/qa/system-implementation-tp8-1710x863.png`，两者均为同一个 Chrome 内容视口 1710 × 863 px；最终还在同一比较调用中一起复核。

### Full-view comparison evidence

- 顶栏的高度、GIF 纹理、Logo、15 个主模块、欢迎条及右侧四个操作与 7.4 对齐；系统首页保持参考页默认的深蓝主导航状态，不错误显示白色选中态。
- 左栏固定 140 px，14 个系统入口的文案、顺序、80% 按钮宽度、5 px 间距和默认“全局配置”选中状态与参考一致。
- 内容区左边界均为 148 px；系统表格从 y=85 开始，205 px 标签列、14 个信息行、最终下边界和页脚位置与同视口参考对齐。
- 移除了现代统计卡片、快捷操作与最近任务，恢复 7.4 的单一“系统基本信息”表和右上角“详细>>”。

### Focused-region comparison evidence

- 逐项核对技术支持、IP/端口、安装目录、服务器、PHP、SAPI、MySQL、四项函数/扩展、上传限制、GD 和版本检测，字段顺序与 7.4 一致；值来自当前 PHP 8.5/MySQL 8.4 运行环境。
- 实际依次打开运行环境、全局配置、URL 优化、付费点播、播放来源、独立播放器、内容、缓存、采集、附件、邮件、注册、评论和微信共 14 个入口；13 个配置/管理页均正常渲染，配置页保留可提交表单，播放器管理正常显示空状态。

### Required fidelity surfaces

- Fonts and typography: 保留 13 px 旧版后台字体、正常字重、蓝色链接和右对齐字段标签。
- Spacing and layout rhythm: 30 px 表头、35/36 px 交替落像素的数据行、205 px 标签列、140 px 左栏，与参考同视口位置一致。
- Colors and visual tokens: 使用原 GIF 顶栏/表头纹理、`#135294` 文字、浅蓝边框、绿色成功标记和红色版本号。
- Copy and content: 保留 7.4 的系统菜单与运行环境文案；仅运行值和版本号反映升级后的 ThinkPHP 8/PHP 8.x 程序。
- States and interactions: 顶栏系统入口、左栏默认态、全部 14 个路由、配置表单控件和运行环境详细链接均实际检查。
- Accessibility and responsiveness: 仍使用语义化导航、表格、原生输入/选择/按钮，键盘焦点样式和窄屏侧栏策略保持有效。

### Comparison history

1. 初次对比发现 P1 结构偏差：现代统计卡片和任务面板取代了原运行环境表，系统首页还错误落在“后台首页”子菜单。
   - Fix: 删除仪表盘附加区，重建 14 行运行环境表，把 `/admin` 恢复为系统模块并显示完整 14 项左栏。
   - Post-fix evidence: DOM 与浏览器截图均显示 14 行环境字段和完整系统菜单。
2. 第二次同视口对比发现 P2 几何偏差：内容区双重 99% 缩进、每行多约 4 px、页脚累计下移，左栏按钮过窄，表头缺少“详细>>”。
   - Fix: 去掉内层重复缩进，将内容起点校准至 148 px，行高改为 35.5 px，菜单宽度改为 80%，恢复详细链接并校准页脚。
   - Post-fix evidence: 实现表格起点、标签分隔线、底边与参考同视口截图重合到 0–1 px 范围。
3. 最终复核：没有剩余可执行的 P0/P1/P2 视觉或交互问题。

## 全局配置 Tab 与模板选择器补充验收（2026-09-29）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_JxQKKY/截屏2026-09-29 下午3.23.52.png`，用户提供的 FeiFeiCMS 7.4 全局配置基本页。
- Source code truth: `legacy/Public/system/config_base.html` 与 `legacy/Public/css/admin-style.css`，用于核对 Tab DOM、字段顺序、200 px 标签列、控件宽度、红色必填标记和底部操作区。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/settings/base?saved=1`，Chrome 真实登录态，1710 × 863 CSS px。
- Implementation capture: Chrome/CUA 的基本配置、扩展配置、SEO 优化和保存成功四个浏览器截图已嵌入本任务；该 Chrome 控制面不提供可持久化的本地截图路径。

### Full-view comparison evidence

- 去掉了原实现中额外的“全局配置”内容标题，页面恢复为参考图的单层 `基本配置 / 扩展配置 / SEO优化` Tab 结构。
- 左侧仍只保留模块导航，不再把同一组功能同时竖排和横排展示。
- 基本配置的字段顺序、浅蓝分割线、深蓝标签、方形原生控件、提示文字、红色必填号和长文本区与参考页保持同一几何节奏。

### Focused-region comparison evidence

- `网站主模板` 和 `移动端模板` 均为真实 `<select>`，选项由 `ThemeRegistry` 扫描完整 ThinkPHP 8 模板目录生成；当前唯一完整可用模板为 `MXOne`。
- 前台所有页面控制器均通过 `ff_theme_view()` 解析当前桌面/移动模板，选择器不是纯视觉伪控件；首页已以所选 MXOne 实际渲染。
- 实际点击三个 Tab，DOM 始终只有一个可见 `tabpanel`；扩展页的后台排序恢复为 7.4 的单选按钮，SEO 三个输入框均为 400 px。
- 将网站运行方案切换为静态时，5 个路径字段全部出现；切回动态后全部隐藏，不丢失已保存值。

### Interaction and runtime evidence

- 实际点击“提交”，页面回到 `/admin/settings/base?saved=1` 并显示“配置保存成功，当前设置已经生效”。
- 保存后主模板仍为 `MXOne`，首页标题为“飞飞影视”，且前台/后台浏览器控制台均无 error 或 warning。
- PHP 8.5.10 语法检查通过；PHPUnit `49/49` 测试、`372` 断言全部通过；ThinkPHP 运行时缓存已清理。

### Comparison history

1. 初始版存在 P1 结构偏差：“基本信息”和“页面与界面”为纵向长表单，模板是可随意填写的文本框，与 7.4 参考图不同。
   - Fix: 按原版源文件重建三组 Tab，恢复原字段顺序与尺寸，新增真实模板发现与解析链路。
   - Post-fix evidence: 三个 Tab、主/移动模板下拉、静态字段联动和保存链路均已在宝塔实际页面通过。
2. 首次扩展页对比发现 P2 控件偏差：后台排序使用了下拉框，布尔下拉宽度与原版不同。
   - Fix: 恢复“时间 / ID值”单选结构，并校准布尔选择器至 120 px。
   - Post-fix evidence: 浏览器读值返回 `addtime=false`、`id=true`、`booleanWidth=120px`。
3. 最终复核：没有剩余可执行的 P0/P1/P2 视觉或交互问题。

final result: passed

## 视频编辑上映日期控件恢复（2026-09-29 17:48）

- Source visual truth: 用户问题截图 `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_3r0lhx/截屏2026-09-29 下午5.48.15.png`，并以本地 FeiFeiCMS 7.4 实机 `http://127.0.0.1:8090/index.php?s=Admin-Vod-Add-cid-2-id-73380` 的 `vod_filmtime` 控件为最终结构依据。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/vod/1000/edit`，Chrome 1710 × 863 CSS px。
- Implementation capture: 修改后的浏览器实页截图已嵌入本任务；当前 Chrome/CUA 控制面不提供可持久化的本地截图路径。
- State: 视频基本资料页；7.4 参考值为 `2026-07-20`，实现页当前记录日期为空。

### Focused comparison and fix

1. [P1] 新版使用原生 `type=date`，Chrome 显示“年/月/日”和日历图标；7.4 使用普通文本框。
   - Fix: 恢复 `type=text`，保留 `name=release_date`、120 px 级别宽度、居中内容和 `YYYY-MM-DD` 的 10 字符输入上限。
   - Post-fix evidence: Chrome 无障碍树从 `date field` 变为普通 `text field`；最终浏览器截图中日期图标和“年/月/日”均已消失，位置、边框和同行布局与 7.4 一致。

### Required fidelity surfaces

- Fonts and typography: 沿用视频编辑页现有 13px 微软雅黑体系，日期值居中显示。
- Spacing and layout rhythm: 保持 `w120` 宽度及节目周期、上映日期、更新日期同一行结构。
- Colors and visual tokens: 沿用原版灰色输入框边框、白色背景和深蓝标签。
- Image quality: 此控件不含图片资产；已移除浏览器原生日历图标。
- Copy and content: 字段名、提交键和 `YYYY-MM-DD` 数据格式不变。

### Automated and runtime evidence

- PHP 8.5.10 PHPUnit: `56 tests, 565 assertions`，全部通过。
- 浏览器实页确认控件为普通文本框；后端仍按 `release_date` 接收和保存。

final result: passed

## 视频列表数据列与底部批量功能补充验收（2026-09-29）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_92a6Le/截屏2026-09-29 下午5.16.41.png`，用户提供的 FeiFeiCMS 7.4 视频列表下半区。
- Live source truth: `http://127.0.0.1:8090/index.php?s=Admin-Index` 的 FeiFeiCMS 7.4 视频管理页，以及 `Public/system/vod_show.html`、`VodAction.class.php` 中的列表与合并实现。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/vod`，本地宝塔 PHP 8.5/MySQL 环境，真实 1004 条视频数据。
- Implementation capture: Chrome/CUA 最终视口截图已嵌入本任务；画面停留在列表底部，可直接核对分页和全部批量按钮。

### Visual and data parity

- 列表恢复 7.4 的七列结构：`视频名称 服务器组 / 权重 / 人气 / 年代 / 时间 / 豆瓣 / 相关操作`。
- 视频名称列显示真实 ID、主分类、扩展类型、地区、片名、完结或连载状态、重名查询和服务器组；权重使用原版星级 GIF，可逐级点击。
- 行内操作逐项恢复剧情获取/刷新、豆瓣评论获取、编辑、显示/隐藏、锁定/解锁与删除，按钮保持原版方括号链接形态。
- 分页区显示总数、当前页/总页、首页/上下页/尾页与页码输入跳转；底部按钮顺序与参考一致。

### Functional evidence

- 浏览器实际验证 30 条记录全选后为 30、反选后为 0；批量移动和设置系列面板互斥展开，页码输入 `2` 后进入 `?page=2`。
- 通过临时影片实际调用权重、显示/隐藏、锁定/解锁、批量设置系列接口；页面状态、按钮文案和成功提示均同步更新，随后已删除测试影片和测试审计记录。
- 合并影片在数据库事务中验证：仅允许一条已审核主影片；同名线路由子影片覆盖，分集剧情迁移，来源标识合并为两组，子影片归档。事务最终回滚，未遗留测试数据。
- 批量移动、审核/取消审核、归档、系列、合并和锁定均由后端控制器执行；批量剧情逐条调用影片关联采集源，不是前端占位按钮。
- 最终数据量恢复为 1004；浏览器页面无横向溢出，底部工具栏、页脚和最后一行均完整可见。

### Automated evidence

- PHP 8.5.10 syntax check: controller, routes, collection runner, merge service and tests all passed.
- JavaScript syntax check passed.
- PHPUnit: `56 tests, 563 assertions`，全部通过。

### Comparison history

1. 初始列表只有现代化简表，缺少服务器组、星级、豆瓣列和 7.4 底部批处理区，属于 P1 功能与结构缺失。
   - Fix: 按 7.4 源模板重建列表、分页、行内操作和底部工具栏，并将每个动作接到真实后端。
2. 首次浏览器复核发现没有剧情数据的影片不显示“剧情获取”，与 7.4 行内操作不一致。
   - Fix: 所有影片固定显示剧情获取；已有剧情时显示剧情刷新，缺少采集来源时返回明确错误而不伪造成功。
3. 最终复核：没有剩余可执行的 P0/P1/P2 视觉、数据或交互问题。

final result: passed

## 采集资源库 7.4 结构与功能补充验收（2026-09-29）

- Source visual truth: `http://127.0.0.1:8090/index.php?s=Admin-Cj-Show-type-1` 的本地 FeiFeiCMS 7.4 实机页，以及用户提供的 `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_iiEmeh/截屏2026-09-29 下午4.48.50.png`。
- Source code truth: `legacy/Public/system/cj_list_vod.html`、`legacy/Public/system/cj_show_vod.html` 与 `legacy/Public/system/left.html`。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/collections`、`/admin/collections/23/resource`、`/admin/collections/jobs`，Chrome 真实登录态，1710 × 863 CSS px。
- Implementation capture: 列表页、资源浏览页、完整长页和独立采集记录页截图已嵌入本任务；当前 Chrome 控制面不提供可持久化的本地截图路径。

### Full-view comparison evidence

- 资源站首页恢复为 7.4 的单表七列：序号、资源名称与地址、分类转换、采集当天、采集本周、采集所有、修改/删除；移除了混在同页的现代化 Tab、ID/类型/状态表头和采集记录表。
- 进入资源库后恢复为 7.4 顺序：分类转换、搜索与操作、四列资源表、全选/反选/采集选中、个性采集；完整长页底部按钮和页脚均可到达，没有内容裁切。
- 左侧采集菜单补齐视频、文章、用户、评论、剧情、明星、角色、定时自动采集，并把现代采集记录放在独立页，不干扰 7.4 主页面。

### Focused-region and interaction evidence

- 分类绑定使用原版红色“绑定”/蓝色“解绑”语义；单项保存采用局部映射合并，显式“不绑定=0”会保留，不会覆盖其他分类或被自动建议反向改回绑定。
- 搜索、采集当天、采集所有、采集本类和选中采集分别提交独立过滤条件；个性采集的分类、小时、播放器和分页数量会进入真实任务游标，播放器筛选同时兼容 FeiFei 与 MacCMS 参数。
- 浏览器实际验证 20 条资源的“全选”结果为 20 条、“反选”后为 0 条；列表、资源页和采集记录页控制台均无 error 或 warning。
- 采集记录独立页保留任务状态、处理/新增/更新/错误统计、起止时间及重试操作，不再改变 7.4 资源站列表的页面结构。

### Comparison history

1. 初始版把资源库管理和最近任务合成现代化控制台，属于 P1 结构偏差。
   - Fix: 按 `cj_list_vod.html` 重建七列资源库表，并把任务记录迁移到独立路由。
2. 初始资源浏览页使用分类下拉网格和现代七列表格，属于 P1 结构及交互偏差。
   - Fix: 按 `cj_show_vod.html` 重建分类转换、搜索、四列资源表和个性采集，并保留现有安全校验与同步服务。
3. 分类“不绑定”曾可能被默认建议覆盖，属于 P1 数据状态问题。
   - Fix: 保存显式零值并局部合并现有映射；导入解析继续把零值视为跳过该远端分类。
4. 最终复核：没有剩余可执行的 P0/P1/P2 视觉或交互问题。

### Automated and runtime evidence

- PHP 8.5.10 语法检查通过。
- PHPUnit: `53 tests, 492 assertions`，全部通过。
- 实机浏览器完成同视口对照、完整长页和交互验证。

final result: passed

## 后台长表单完整显示补充验收（2026-09-29）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_G1X5Ap/截屏2026-09-29 下午4.32.48.png`，用户提供的分类编辑页底部被截断状态。
- Affected surface: 所有复用 `.editor-form` 的后台编辑页，而不是只对分类页做局部补丁。
- Root cause: 公共表单容器使用 `overflow: hidden`，动态内容或缓存中的旧版样式会裁掉底部；同时样式地址长期固定为 `v=31`，浏览器可能继续使用旧 CSS。
- Fix: 表单改为 `display: flow-root; height: auto; max-height: none; overflow: visible`，内容区恢复自然高度，根节点保留稳定纵向滚动条，并把样式版本提升到 `admin.css?v=32` 强制失效旧缓存。

### Browser evidence

1. 分类编辑 `/admin/categories/1/edit`：JSON 文本区、保存按钮、归档区和页脚均可通过页面滚动完整到达；1024 × 720 小窗口下滚动到底仍全部可见。
2. 用户新增 `/admin/users/create`：全部字段、提交/重置按钮与页脚同屏完整显示。
3. 采集源新增 `/admin/collections/create`：表单、协议选项、启用状态、提交/重置和页脚完整显示，无多余滚动。
4. 全局配置 `/admin/settings/base`：长文本区、底部提交/重置和页脚完整渲染，页面高度 1157 px、视口 863 px，纵向滚动正常。
5. 上述页面均加载 `admin.css?v=32`，公共表单计算样式为 `flow-root / overflow: visible`；最终控制台无 error 或 warning。

### Automated evidence

- PHP 8.5.10 PHPUnit: `52 tests, 461 assertions`。
- 自动化覆盖稳定滚动条、长表单不裁剪和 CSS 缓存版本号。

final result: passed

## 模板管理与工具菜单补充验收（2026-09-29）

- Source visual truth: `/Users/guobinlin/Documents/ChatGPT/feifeicms4.3升级php8.x/public/qa/feifeicms74-template-manager.png`，原图 3386 × 1128 px，按 2× HiDPI 归一为 1693 × 564 CSS px。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/tools/templates`，真实模板目录、真实文件大小与修改时间。
- Comparison page: `http://feifeicms-modern.localhost:19101/qa/template-manager-comparison.html`，参考图和实现均以 1693 × 564 CSS px 展示。
- Implementation capture: Chrome/CUA 已完成整页截图和同尺寸对比；该浏览器控制面不提供可持久化的本地截图路径。

### Full-view comparison evidence

- 顶部主导航、欢迎条、右上角操作、左侧工具菜单、五列模板表格、文件夹图标、浅蓝边框、行高和页脚与 7.4 参考保持同一结构和视觉节奏。
- 首页通过 `ThemeRegistry` 只列出完整模板套装，当前只有 `mxone`；`view/vod`、`view/news`、`view/layout` 等公共兜底目录不再被误认成独立模板。
- 工具左栏已按最终需求移除访客统计整组入口，保留缓存、版本、附件、模板、同名检测、批量维护、静态生成和计划任务。

### Focused-region and interaction evidence

- 已实际进入 `mxone` 和 `mxone/vod` 下级目录，文件列表正确显示类型图标、文件大小、修改时间、编辑和删除入口。
- 已实际打开 `mxone/vod/play.html` 独立编辑页；源码只编码一次，不再出现 `&quot;` 乱码。
- 目录穿越、非白名单扩展名和 PHP 文件均被拒绝；模板操作保留 CSRF 校验。
- 版本升级保留真实版本/运行环境检查；访客统计整组功能、记录中间件和数据表已删除。

### Comparison history

1. 初始版将模板列表和源码编辑挤在同一页，属于 P1 结构偏差。
   - Fix: 重建为 7.4 的目录浏览器，再由文件行进入独立编辑页。
2. 访客统计曾按参考页补齐，随后用户明确要求删除。
   - Fix: 删除四个菜单和路由、后台控制器/视图、前台记录中间件及 `ffx_visit_logs` 表。
3. 首次截图发现内联 data URI 文件夹图标未显示，属于 P2 资产偏差。
   - Fix: 恢复原 FeiFeiCMS GIF 文件类型资产，并在最终 Chrome 整页截图中确认黄色目录图标正常。
4. 首页原始扫描 `view/` 导致公共页面目录被误列为模板，属于 P1 数据语义偏差。
   - Fix: 顶层改用前台同一个 `ThemeRegistry` 完整性判定，只显示 `mxone`；进入 `mxone` 后再显示其 11 个真实子目录。
5. 最终复核：没有剩余可执行的 P0/P1/P2 视觉、安全或交互问题。

### Automated and runtime evidence

- PHP 8.5.10 PHPUnit: `52 tests, 457 assertions`。
- MySQL 8.4.11: schema version 10，`45` 张 `ffx_` 表，访客统计表已删除。
- Redis 缓存和 Meilisearch 搜索状态正常。
- Chrome 最终模板管理页无 console error 或 warning。

final result: passed

## 视频新增数据策略补充验收（2026-09-29）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_bjhZKk/截屏2026-09-29 下午5.08.49.png`，1724 × 750 px，FeiFeiCMS 7.4 采集源编辑页的“视频新增数据”展开状态。
- Implementation: `http://feifeicms-modern.localhost:19101/admin/collections/23/edit`，Chrome 真实登录态，1710 × 863 CSS px。
- Implementation capture: 编辑页默认状态和保存恢复后的截图已嵌入本任务；当前 Chrome 控制面不提供可持久化的本地截图路径。
- State: 参考图为下拉菜单展开，最终实现截图为控件收起；选项内容、顺序、字段位置和说明文字通过聚焦区域逐项核对。

### Full-view and focused-region comparison evidence

- 在资源类型与启用状态之间增加“视频新增数据”行，使用原版方形下拉控件和 7.4 的三项顺序：新增并显示、新增但隐藏（待审核）、只更新，不新增。
- 标签列、300 px 控件宽度、浅蓝行边框、深蓝标签文字、右侧灰色说明与当前 7.4 后台样式系统一致；没有新增图片资产或图标替代问题。
- 复制内容明确保留“仅对视频库生效”和“只更新后不能新增影片”的业务含义。

### Functional evidence

- 数据库新增 `ffx_collection_sources.new_data_policy`，默认 `published`；schema baseline 与迁移版本均提升至 11，迁移重复执行安全。
- 浏览器真实保存 `draft` 后重新加载仍为 `draft`，随后恢复原 `published` 设置并再次确认持久化；控制台无 error 或 warning。
- `update_only` 使用未匹配临时影片调用真实导入链路，结果为跳过且新增数 0。
- `draft` 与 `published` 分别通过真实数据库事务验证新片状态，结果对应为 `draft` 与 `published`；事务回滚后测试影片残留数 0。
- 已匹配旧影片的审核状态由合并逻辑保持，不会因资源库的新片策略被自动发布或降级。

### Comparison history

1. 初始实现缺少整行“视频新增数据”，属于 P1 功能和结构缺失。
   - Fix: 增加三档采集源持久化字段、7.4 表单控件以及导入创建策略。
   - Post-fix evidence: 浏览器保存/恢复成功，三档后端行为全部通过事务或跳过链路验证。
2. 首次数据库迁移使用了当前实例不接受的 `ADD COLUMN IF NOT EXISTS`，属于 P1 部署兼容问题。
   - Fix: 改为 `information_schema` 检测与动态 `PREPARE/EXECUTE`，并重复执行迁移确认幂等。
   - Post-fix evidence: 数据库列默认值为 `published`，schema version 为 `11`。
3. 最终复核：没有剩余可执行的 P0/P1/P2 视觉或功能问题。

### Automated evidence

- PHP 8.5.10 语法检查通过。
- PHPUnit: `55 tests, 506 assertions`，全部通过。

final result: passed

## 视频列表紧凑样式二次对齐（2026-09-29 17:38）

- Source visual truth: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_92a6Le/截屏2026-09-29 下午5.16.41.png`，以及本地 FeiFeiCMS 7.4 实机 `http://127.0.0.1:8090/index.php?s=Admin-Vod-Show`。
- Reported implementation state: `/var/folders/ds/50m1v1y56gb0y1klrzf765br0000gn/T/TemporaryItems/NSIRD_screencaptureui_dpoIdU/截屏2026-09-29 下午5.38.53.png`，普通数据行约 67 CSS px，首列误居中且操作区换行。
- Revised implementation: `http://feifeicms-modern.localhost:19101/admin/vod`，Chrome 1710 × 863 CSS px，`devicePixelRatio=2`。
- Implementation capture: 7.4 原页和修正版已在同一个 Chrome/CUA 比较调用中按相同视口先后捕获；最终列表顶部与底部截图已嵌入本任务。

### Findings and fixes

1. [P1] 普通列表行高度几乎是原版两倍。
   - Evidence: 7.4 原页普通行 `35.5px`，问题页为 `67px`。
   - Cause: 操作项被额外生成方括号并换成两行，列表单元格又使用 22px 固定行高。
   - Fix: 恢复原版普通行 `36px`、`line-height: normal`、上下 8px 内边距；操作项改为单行普通链接按钮。
   - Post-fix evidence: 前 30 行全部为 `36px`，与原版误差 0.5px。
2. [P1] 视频信息在首列中被整体居中，导致每行起点不一致和大片空白。
   - Evidence: 问题页计算样式为 `text-align:center`，首行复选框位于首列 x=312.8；原版信息从单元格左侧排列。
   - Fix: 使用更明确的 `td.vod-name-cell` 选择器覆盖全局首列居中规则，恢复左对齐；服务器组固定在该列右侧。
   - Post-fix evidence: 计算样式为 `text-align:left`，普通片名、分类、状态和来源在一行内稳定对齐。
3. [P2] 列宽与原版不一致。
   - Fix: 表格恢复 `table-layout:auto` 和 `border-collapse:separate`；权重/人气/年代/时间/豆瓣/操作列校准为 `90/70/70/90/130/300px`。
   - Post-fix evidence: 同视口下各竖向分隔线、星级、数字、日期、豆瓣和操作区与 7.4 原页采用相同节奏；页面横向溢出为 0。

### Required fidelity surfaces

- Fonts and typography: 与原版同为 13px Microsoft YaHei/宋体回退、normal 行高、普通字重；操作链接不再有额外字距。
- Spacing and layout rhythm: 普通行 36px，表头 30px；长片名仍可自然换行，仅需要时增加行高。
- Colors and visual tokens: 保留原浅蓝边框、表头纹理、蓝色链接、红色更新状态及原版星级 GIF。
- Image quality: 星级、排序箭头和表头背景继续使用项目内原版 GIF，没有使用 CSS 或文本近似替代。
- Copy and content: 数据字段及剧情、评论、编辑、显示/隐藏、锁定、删除操作未删减。

### Automated and runtime evidence

- CSS 缓存版本更新为 `admin.css?v=37`，浏览器已加载新版本。
- PHPUnit: `56 tests, 563 assertions`，全部通过。
- Chrome 控制台无 error/warning，30 行均正常渲染，横向溢出为 0。

final result: passed
