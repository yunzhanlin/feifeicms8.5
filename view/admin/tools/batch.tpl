{include file="admin/layout/header" /}
{if !empty($message)}<div class="notice success">{$message|htmlspecialchars|raw}</div>{/if}
<div class="title"><div class="tabs"><span>批量生成标签 TAG</span></div></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/tags" data-confirm="将根据现有关键词、类型、地区和语言生成标签，确定继续？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button name="scope" value="media" type="submit">TAG自动生成（视频）</button> <button name="scope" value="article" type="submit">TAG自动生成（文章）</button></form><p>根据已入库的关键词和标准字段生成标签，不调用外部付费分词 API。</p></div>

<div class="title"><div class="tabs"><span>批量删除播放地址</span></div></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/playback" data-confirm="删除后将无法还原，确定删除该播放来源的全部地址？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><select name="source_key" required><option value="">请选择播放来源</option>{volist name="sources" id="source"}<option value="{$source.source_key|htmlspecialchars|raw}">{$source.display_name|htmlspecialchars|raw} [{$source.source_key|htmlspecialchars|raw}] · {$source.source_count}条</option>{/volist}</select> <button class="danger" type="submit">删除该播放器的所有播放地址</button></form><p>真实删除数据库中该来源的播放线路及其分集，与“禁用播放器”不同。</p></div>

<div class="title"><div class="tabs"><span>批量生成链接别名</span></div></div>
<div class="tool-action-panel"><form method="post" action="/admin/tools/batch/slugs"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><select name="type"><option value="media">视频</option><option value="article">文章</option><option value="topic">专题</option><option value="person">人物/角色</option></select> <button type="submit">开始生成</button></form><p>英文标题保留可读别名，中文标题使用类型和 ID 生成稳定、唯一的链接。</p></div>
{include file="admin/layout/footer" /}
