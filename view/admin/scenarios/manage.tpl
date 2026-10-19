{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/scenarios/media/{$media.id}">按视频管理剧情</a><a href="/admin/vod/{$media.id}/edit">编辑视频资料</a></div><div class="right"><a href="/admin/scenarios">返回剧情管理</a></div></div>
{if $message != ''}<div class="notice success scenario-manage-notice">{$message|htmlspecialchars|raw}</div>{/if}
<div class="scenario-media-summary"><strong>[{$media.id}] {$media.title|htmlspecialchars|raw}</strong><span>当前剧情 {$items|count} 集{if $media.episode_total}，视频设定共 {$media.episode_total} 集{/if}</span><span>在同一页修改后，点击底部“保存全部剧情”。</span></div>
<form method="post" action="/admin/scenarios/media/{$media.id}" class="scenario-batch-form" data-scenario-batch-form>
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="rows_json" value="" data-scenario-rows-json>
    <div class="table-wrap scenario-editor-wrap"><table class="data-table scenario-editor-table">
        <thead><tr><th width="62">ID</th><th width="72">集数</th><th width="190">分集标题</th><th>剧情内容</th><th width="170">来源标识</th><th width="78">排序</th><th width="88">状态</th><th width="66">删除</th></tr></thead>
        <tbody data-scenario-rows>{volist name="items" id="item" key="rowIndex"}<tr data-scenario-row>
            <td><span>{$item.id}</span><input type="hidden" name="rows[{$rowIndex}][id]" value="{$item.id}" data-scenario-field="id"></td>
            <td><input class="scenario-episode-input" type="number" min="1" max="100000" name="rows[{$rowIndex}][episode_no]" value="{$item.episode_no}" data-scenario-field="episode_no" required></td>
            <td><input class="scenario-title-input" type="text" maxlength="255" name="rows[{$rowIndex}][title]" value="{$item.title|htmlspecialchars|raw}" data-scenario-field="title" placeholder="留空自动生成"></td>
            <td><textarea class="scenario-inline-content" name="rows[{$rowIndex}][content]" data-scenario-field="content" required>{$item.content|htmlspecialchars|raw}</textarea></td>
            <td><input class="scenario-source-input" type="text" maxlength="500" name="rows[{$rowIndex}][source_ref]" value="{$item.source_ref|htmlspecialchars|raw}" data-scenario-field="source_ref"></td>
            <td><input class="scenario-sort-input" type="number" name="rows[{$rowIndex}][sort_order]" value="{$item.sort_order}" data-scenario-field="sort_order"></td>
            <td><select name="rows[{$rowIndex}][status]" data-scenario-field="status"><option value="published" {if $item.status == 'published'}selected{/if}>显示</option><option value="draft" {if $item.status != 'published'}selected{/if}>隐藏</option></select></td>
            <td><label class="scenario-delete-choice"><input type="checkbox" name="rows[{$rowIndex}][delete]" value="1" data-scenario-field="delete"><span>删除</span></label></td>
        </tr>{/volist}</tbody>
        <tfoot><tr><td colspan="8" class="scenario-editor-actions"><button type="button" data-scenario-add-row>添加一集</button><button type="button" data-scenario-publish-all>全部显示</button><button type="button" data-scenario-draft-all>全部隐藏</button><button type="submit" class="scenario-save-button">保存全部剧情</button><span data-scenario-batch-status aria-live="polite"></span></td></tr></tfoot>
    </table></div>
</form>
<noscript><div class="notice danger">该页使用 JavaScript 完整提交大量分集，请启用 JavaScript 后再保存。</div></noscript>
{include file="admin/layout/footer" /}
