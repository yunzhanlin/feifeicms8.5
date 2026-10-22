{include file="admin/layout/header" /}
{if !empty($message)}<div class="notice success">{$message|htmlspecialchars|raw}</div>{/if}
<div class="title"><div class="tabs"><span>批量生成标签 TAG</span></div></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/tags" data-confirm="将根据现有关键词、类型、地区和语言生成标签，确定继续？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button name="scope" value="media" type="submit">TAG自动生成（视频）</button> <button name="scope" value="article" type="submit">TAG自动生成（文章）</button></form><p>根据已入库的关键词和标准字段生成标签，不调用外部付费分词 API。</p></div>

<div class="title"><div class="tabs"><span>批量删除播放地址</span></div></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/playback" data-confirm="删除后将无法还原，确定删除该播放来源的全部地址？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><select name="source_key" required><option value="">请选择播放来源</option>{volist name="sources" id="source"}<option value="{$source.source_key|htmlspecialchars|raw}">{$source.display_name|htmlspecialchars|raw} [{$source.source_key|htmlspecialchars|raw}] · {$source.source_count}条</option>{/volist}</select> <button class="danger" type="submit">删除该播放器的所有播放地址</button></form><p>真实删除数据库中该来源的播放线路及其分集，与“禁用播放器”不同。</p></div>

<div class="title"><div class="tabs"><span>批量生成链接别名</span></div></div>
<form class="legacy-tool-control" data-vod-tool-batch data-tool-snapshot data-progress-owner="{$progressOwner}" data-progress-fields="scope" data-endpoint="/admin/tools/batch/slugs/next" data-storage-key="ff-media-slugs-v1">
<input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="delay" value="500">
<label>视频别名：<select name="scope"><option value="generated">只修复旧程序生成的别名（保留自定义）</option><option value="all">按当前片名重新生成全部视频别名</option></select></label>
<button type="button" data-tool-start>开始生成拼音</button><button type="button" data-tool-pause disabled>暂停</button><button type="button" data-tool-reset>重置进度</button><span data-tool-state>尚未开始</span>
</form>
<div class="legacy-tool-help"><p>中文按片名生成无声调拼音；同名冲突增加数字后缀。默认只修复 source-来源ID-影片ID 等旧自动别名，不改自定义别名；“全部”模式会重建自定义别名，请先备份。每批25部，可暂停续跑，不限于前5000部。旧视频别名保留兼容访问，影片和分集ID不变。</p></div>
<div class="legacy-tool-log" data-tool-log><p>别名生成日志将显示在这里。</p></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/slugs"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><select name="type"><option value="article">文章</option><option value="topic">专题</option><option value="person">人物/角色</option></select> <button type="submit">生成其他内容别名</button></form></div>
{include file="admin/layout/footer" /}
