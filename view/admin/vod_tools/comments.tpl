{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/vod-tools/douban-comments">豆瓣评论采集</a><a href="/admin/vod-tools/douban">豆瓣资料补全</a></div></div>
<div class="table-wrap"><table class="data-table legacy-tool-table"><tbody><tr><th>可采集视频：</th><td>{$total} 部（已填豆瓣ID）</td></tr><tr><th>已入库评论：</th><td>{$imported} 条</td></tr></tbody></table></div>
<div class="title"><div class="tabs"><span>批量采集</span></div></div>
<form class="legacy-tool-control" data-vod-tool-batch data-endpoint="/admin/vod-tools/douban-comments/next" data-storage-key="ff-douban-comment-cursor"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><label>请求间隔：<input class="short-input" name="delay" type="number" min="1500" max="60000" step="100" value="4000"> 毫秒</label><button type="button" data-tool-start>开始采集</button><button type="button" data-tool-pause disabled>暂停</button><button type="button" data-tool-reset>重置进度</button><span class="legacy-tool-state" data-tool-state>尚未开始</span></form>
<div class="legacy-tool-help"><p>1：按豆瓣ID采集短评文本，按内容指纹去重，不重复入库。</p><p>2：不采集用户名、头像、个人主页或点赞数；作者统一显示为“豆瓣网友”，不伪造站内用户。</p></div>
<div class="legacy-tool-log" data-tool-log><p>执行日志将显示在这里。</p></div>
{include file="admin/layout/footer" /}
