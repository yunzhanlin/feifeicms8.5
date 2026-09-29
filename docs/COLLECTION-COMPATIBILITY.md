# 采集协议、字段映射与视频合并

## 支持协议

| 协议 | 请求合同 | 视频数据 | 分集剧情 |
|---|---|---|---|
| FeiFeiCMS 7.3 JSON | `g=plus&m=api&a=json&p=...` | `status/page/list/data` | 自动请求 `a=scenario`，解析 `vod_scenario.info` |
| MacCMS JSON | `ac=detail&pg/t/h/wd/ids` | `code/pagecount/class/list` | 支持详情行中携带的 `vod_scenario` |

新增采集源默认“自动识别”；标准 `/api.php/provide/vod/` 路径识别为 MacCMS，其余按 FeiFeiCMS 请求。后台可手动指定协议，避免非标准接口误判。

## 主要字段映射

- 标题：`vod_name` → `ffx_media.title`
- 副标题：`vod_sub` / `vod_title` → `subtitle`
- 正文：`vod_content` / `vod_blurb` / `vod_plot` → `content`
- 图片：`vod_pic`、`vod_pic_bg` → `poster_url`、`backdrop_url`
- 地区、语言、年份、上映日期、总集数、连载状态、完结状态映射到对应正式列
- `vod_douban_id`、`vod_imdb_id` 映射到正式外部 ID 列
- 演员、导演、编剧、制片、摄影、剪辑、音乐、美术、类型、关键词、版本、电视台、来源标识等保存在结构化 metadata
- MacCMS `vod_play_*` 与 `vod_down_*`、FeiFeiCMS `vod_play/vod_url` 均转换为线路表与分集表
- FeiFeiCMS 使用回车分集和 MacCMS 使用 `#` 分集均受支持

## 标准化

- `大陆`、`内地` → `中国大陆`
- `香港` → `中国香港`
- `台湾` → `中国台湾`
- 已以 `中国` 开头的地区值不重复添加
- `汉语`、`普通话`、`汉语普通话` → `国语`
- 多值字段统一英文逗号并去重

## 合并规则

1. 同一采集源优先按远程 ID 更新，不因上游改名重新匹配。
2. 跨来源优先使用 IMDb ID 或豆瓣 ID，并要求规范化片名一致。
3. 没有外部 ID 时，按分类和片名查找，再由年份、导演、演员、地区计算身份匹配；证据不足不合并。
4. 合并只补全本地空字段，已有正文和人工整理资料不被另一来源覆盖。
5. 每个采集源只替换自己拥有的播放线路；其他来源线路继续保留。
6. 外部引用分别保留，因此后续两个采集源都能更新同一个视频。
7. 显式选择“不绑定”的远程分类直接跳过，不落入默认分类。

## 来源标识

- 上游提供 `vod_reurl` 时原样保留。
- MacCMS 等上游未提供 `vod_reurl` 时，根据资源库名和远程 ID 生成 FeiFeiCMS 格式，例如 `[bfzy]=[157252]`。
- 同一视频合并多个来源时，后台“来源标识”同时显示所有标识，不覆盖原有来源。
- `ffx_external_refs` 仍是来源关系的权威数据，视频 metadata 中的 `source_ref` 用于兼容编辑和 FeiFeiCMS 字段。

## 剧情关联

采集剧情先按采集源和远程视频 ID 查找 `ffx_external_refs`，再写入该引用对应的 `ffx_media.id`。剧情落入 `ffx_scenarios`，受 `(media_id, episode_no)` 唯一约束与视频外键级联约束保护；人工剧情不会被采集剧情覆盖。
