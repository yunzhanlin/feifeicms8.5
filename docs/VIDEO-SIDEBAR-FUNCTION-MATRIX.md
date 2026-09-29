# 视频管理左侧功能对齐矩阵

核对基线：FeiFeiCMS 4.3 的左侧筛选语义，以及 FeiFeiCMS 7.4 新增的豆瓣与剧情独立工具。

| 菜单项 | 原版语义 | 现代版实现 | 写入边界 |
| --- | --- | --- | --- |
| 视频管理 | 全部视频、搜索、分类筛选 | `/admin/vod` | 批量审核/归档/删除 |
| 添加视频 | 新建视频 | `/admin/vod/create` | 视频、扩展资料与播放线路 |
| 待复查 | 未审核视频 | `status=draft` | 只读筛选 |
| 豆瓣资料补全 | 7.4 独立批量工具 | `/admin/vod-tools/douban` | 只补资料，不改分类、状态、播放数据 |
| 豆瓣ID查重 | 7.4 重复/冲突分组 | `/admin/vod-tools/douban-duplicates` | 只读，不自动合并删除 |
| 豆瓣评论采集 | 7.4 按ID采集短评 | `/admin/vod-tools/douban-comments` | 只存评论文本，不采集用户身份信息 |
| 分集剧情补全 | 7.4 独立剧情采集 | `/admin/vod-tools/scenario-collect` | 只写独立 `ffx_scenarios` 表 |
| 已锁定视频 | `vod_inputer='feifeicms'` | `metadata.inputer='feifeicms'` | 只读筛选 |
| 无播放地址 | `vod_url=''` | 不存在已启用且 URL 非空的分集 | 只读筛选 |
| 有分集剧情 | `vod_scenario<>''` | 存在未删除的独立剧情记录 | 只读筛选 |
| 有经典台词 | `vod_lines<>''` | `metadata.lines` 非空 | 只读筛选 |
| 有豆瓣编号 | `vod_douban_id>0` | `douban_id` 为 5–12 位有效正整数 | 只读筛选 |
| 需VIP权限 | `vod_ispay>0` | `access_mode=member` | 只读筛选 |
| 需支付影币 | `vod_price>0` | `price_points>0` | 只读筛选 |
| 可试看影片 | `vod_trysee>0` | `metadata.trysee>0` | 只读筛选 |
| 有系列影片 | `vod_series<>''` | `metadata.series` 非空 | 只读筛选 |
| 无封面视频 | `vod_pic=''` | `poster_url` 去空格后为空 | 只读筛选 |
| 重复片名检测 | 独立同名检测功能 | `/admin/vod?duplicate=title` | 保留视频左侧导航，只读、人工处理 |

## 验收标准

- 每个左侧入口均返回后台页面，不跳到无关模块。
- 四个 7.4 工具均是独立控制台，具有进度、暂停、继续和日志。
- 豆瓣补全不改分类/播放，豆瓣查重不自动删除，剧情采集不改视频主表与播放表。
- 所有写操作校验 CSRF 并写入审计日志。
