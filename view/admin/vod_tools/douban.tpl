{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/vod-tools/douban">豆瓣资料补全</a><a href="/admin/vod-tools/douban-comments">豆瓣评论采集</a><a href="/admin/vod?meta=douban">有豆瓣编号</a></div></div>
<div class="table-wrap"><table class="data-table legacy-tool-table"><tbody>
<tr><th>已有豆瓣ID：</th><td><strong>{$total}</strong> 部</td></tr>
<tr><th>需补全资料：</th><td><strong class="text-danger">{$missing}</strong> 部（封面、简介、IMDb、上映日期、评分、导演或演员缺失）</td></tr>
</tbody></table></div>
<div class="title"><div class="tabs"><span>批量补全</span></div></div>
<form class="legacy-tool-control" data-vod-tool-batch data-endpoint="/admin/vod-tools/douban/next" data-storage-key="ff-douban-cursor">
<input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
<label>处理范围：<select name="scope"><option value="missing">仅补齐缺失字段</option><option value="all">刷新所有豆瓣资料</option></select></label>
<label>请求间隔：<input class="short-input" name="delay" type="number" min="800" max="30000" step="100" value="2500"> 毫秒</label>
<button type="button" data-tool-start>开始执行</button><button type="button" data-tool-pause disabled>暂停</button><button type="button" data-tool-reset>重置进度</button>
<span class="legacy-tool-state" data-tool-state>尚未开始</span>
</form>
<div class="legacy-tool-help"><p>1：与 FeiFeiCMS 7.4 一样按豆瓣ID逐部执行，可暂停并从上次位置继续。</p><p>2：补全只修改影片资料字段，不改分类、状态、播放线路和分集。</p></div>
<div class="title"><div class="tabs"><span>最近失败记录</span></div></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th width="75">视频ID</th><th>片名</th><th width="110">豆瓣ID</th><th>失败原因</th><th width="70">次数</th><th width="145">时间</th><th width="80">操作</th></tr></thead><tbody>{volist name="failures" id="failure"}<tr><td>{$failure.entity_id}</td><td><a href="/admin/vod/{$failure.entity_id}/edit">{$failure.title|htmlspecialchars|raw}</a></td><td>{$failure.douban_id|htmlspecialchars|raw}</td><td>{$failure.detail.message|default=''|htmlspecialchars|raw}</td><td>{$failure.detail.attempts|default=1}</td><td>{$failure.updated_at|htmlspecialchars|raw}</td><td><button type="button" class="button small" data-tool-retry data-endpoint="/admin/vod-tools/douban/next" data-token="{$csrf|htmlspecialchars|raw}" data-media-id="{$failure.entity_id}">重试</button></td></tr>{/volist}</tbody></table></div>
<div class="legacy-tool-log" data-tool-log><p>执行日志将显示在这里。</p></div>
{include file="admin/layout/footer" /}
