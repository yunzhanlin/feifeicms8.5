{include file="admin/layout/header" /}
<form class="legacy-scenario-editor" method="post" action="{if $isNew}/admin/scenarios{else /}/admin/scenarios/{$scenario.id}{/if}">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
    <div class="title"><div class="tabs"><a class="active" href="#">{if $isNew}添加剧情{else /}编辑剧情{/if}</a></div><div class="right"><a href="/admin/scenarios">返回剧情管理</a></div></div>
    <div class="add legacy-vod-add"><table border="0" cellpadding="0" cellspacing="0" class="table legacy-vod-table">
        <tr><td class="tl">所属影片：</td><td class="tr"><select name="media_id" class="w400" required><option value="0">请选择影片</option>{volist name="media" id="vod"}<option value="{$vod.id}" {if $vod.id == $scenario.media_id}selected{/if}>[{$vod.id}] {$vod.title|htmlspecialchars|raw}</option>{/volist}</select> <span class="legacy-field-help">剧情已从视频资料中独立</span></td></tr>
        <tr><td class="tl">分集集数：</td><td class="tr"><input type="number" name="episode_no" min="1" value="{$scenario.episode_no}" class="w100 ct" required> 排序权重：<input type="number" name="sort_order" value="{$scenario.sort_order|default=0}" class="w100 ct"></td></tr>
        <tr><td class="tl">分集标题：</td><td class="tr"><input type="text" name="title" value="{$scenario.title|default=''|htmlspecialchars|raw}" maxlength="255" class="w400" placeholder="留空自动生成第N集"></td></tr>
        <tr><td class="tl">来源标识：</td><td class="tr"><input type="text" name="source_ref" value="{$scenario.source_ref|default=''|htmlspecialchars|raw}" maxlength="500" class="w400"></td></tr>
        <tr><td class="tl">剧情状态：</td><td class="tr"><select name="status" class="w150"><option value="published" {if $scenario.status == 'published'}selected{/if}>显示</option><option value="draft" {if $scenario.status != 'published'}selected{/if}>隐藏</option></select></td></tr>
        <tr><td class="tl">剧情内容：</td><td class="tr padding-5050"><textarea name="content" class="legacy-richtext scenario-content" required>{$scenario.content|default=''|htmlspecialchars|raw}</textarea></td></tr>
    </table><ul class="footer legacy-vod-footer"><li><button type="submit">提交</button> <button type="reset">重置</button></li></ul></div>
</form>
{if !$isNew}<form class="legacy-scenario-delete" method="post" action="/admin/scenarios/{$scenario.id}/delete" data-confirm="确定删除这条分集剧情？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button class="button danger" type="submit">删除剧情</button></form>{/if}
{include file="admin/layout/footer" /}
