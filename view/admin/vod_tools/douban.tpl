{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/vod-tools/douban">豆瓣资料补全</a><a href="/admin/vod-tools/douban-comments">豆瓣评论采集</a><a href="/admin/vod?meta=douban">有豆瓣编号</a></div></div>
<form method="get" action="/admin/vod-tools/douban" class="legacy-tool-control">
<label>视频目录：<select name="category_id"><option value="0">全部视频（电影／电视剧／综艺／动漫）</option>{volist name="categories" id="category"}<option value="{$category.id}" {if $filters.category_id == $category.id}selected{/if}>{$category.name|htmlspecialchars|raw}{if $category.parent_id > 0}（子目录）{/if}</option>{/volist}</select></label>
<label>更新时间：<select name="period"><option value="all" {if $filters.period == 'all'}selected{/if}>不限时间</option><option value="day" {if $filters.period == 'day'}selected{/if}>最近一天（24小时）</option><option value="week" {if $filters.period == 'week'}selected{/if}>最近一周（7天）</option></select></label><button type="submit">查看当前范围</button>
</form>
<div class="table-wrap"><table class="data-table legacy-tool-table"><tbody>
<tr><th>已有豆瓣ID：</th><td><strong>{$total}</strong> 部</td></tr>
<tr><th>需补全资料：</th><td><strong class="text-danger">{$missing}</strong> 部（封面、简介、IMDb、上映日期、评分、导演或演员缺失）</td></tr>
<tr><th>没有豆瓣ID：</th><td>{$withoutId} 部（本批次跳过，请先为影片填写正确的豆瓣条目ID）</td></tr>
</tbody></table></div>
<div class="title"><div class="tabs"><span>批量补全</span></div></div>
<form class="legacy-tool-control" data-vod-tool-batch data-tool-snapshot data-progress-owner="{$progressOwner}" data-progress-fields="category_id,period,scope" data-endpoint="/admin/vod-tools/douban/next" data-storage-key="ff-douban-cursor-v2">
<input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
<input type="hidden" name="category_id" value="{$filters.category_id}"><input type="hidden" name="period" value="{$filters.period|htmlspecialchars|raw}">
<label>处理范围：<select name="scope"><option value="missing">仅补齐缺失字段</option><option value="all">刷新所有豆瓣资料</option></select></label>
<label>请求间隔：<input class="short-input" name="delay" type="number" min="800" max="30000" step="100" value="2500"> 毫秒</label>
<button type="button" data-tool-start>开始执行</button><button type="button" data-tool-pause disabled>暂停</button><button type="button" data-tool-reset>重置进度</button>
<span class="legacy-tool-state" data-tool-state>尚未开始</span>
</form>
<div class="legacy-tool-help"><p>1：目录范围包含全部子目录，电影、电视剧、综艺、动漫使用同一豆瓣条目获取方法；无豆瓣ID的影片不会按名称猜测匹配。</p><p>2：最近一天／一周按视频的更新时间计算，不按上映日期；续跑保持批次开始时的时间范围与影片ID上限。新采集的数据请重置进度后再跑。</p><p>3：各目录、时间及处理范围分别保存进度，可暂停后继续。补全只修改资料，不改分类、状态、播放线路和分集；更换站点或账号不会共用进度。</p></div>
<div class="legacy-tool-help">豆瓣条目未收录的主演、导演、IMDb 等字段不会凭空补造，也不会用空值覆盖已有资料；因此扫描完成后，“需补全资料”数量不一定归零。</div>
<div class="title"><div class="tabs"><span>最近失败记录</span></div></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th width="75">视频ID</th><th>片名</th><th width="110">豆瓣ID</th><th>失败原因</th><th width="70">次数</th><th width="145">时间</th><th width="80">操作</th></tr></thead><tbody>{volist name="failures" id="failure"}<tr><td>{$failure.entity_id}</td><td><a href="/admin/vod/{$failure.entity_id}/edit">{$failure.title|htmlspecialchars|raw}</a></td><td>{$failure.douban_id|htmlspecialchars|raw}</td><td>{$failure.detail.message|default=''|htmlspecialchars|raw}</td><td>{$failure.detail.attempts|default=1}</td><td>{$failure.updated_at|htmlspecialchars|raw}</td><td><button type="button" class="button small" data-tool-retry data-endpoint="/admin/vod-tools/douban/next" data-token="{$csrf|htmlspecialchars|raw}" data-media-id="{$failure.entity_id}">重试</button></td></tr>{/volist}</tbody></table></div>
<div class="legacy-tool-log" data-tool-log><p>执行日志将显示在这里。</p></div>
{include file="admin/layout/footer" /}
