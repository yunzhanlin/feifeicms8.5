{include file="admin/layout/header" /}
{if !empty($resumeJob)}
<h2 class="legacy-collection-resume"><a href="/admin/collections/jobs">上次有采集任务没有完成，是否接着采集?</a></h2>
{/if}
<div class="table-wrap legacy-collection-wrap">
    <table class="data-table legacy-collection-table">
        <thead><tr><th colspan="7" class="collection-list-heading"><span>API资源站（{$resourceLabel}）列表</span><span><a href="/admin/collections/create?type={$resourceType}">添加{$resourceLabel}资源库</a></span></th></tr></thead>
        <tbody>{volist name="sources" id="source" key="order"}<tr>
            <td class="collection-order">{$order}、</td>
            <td class="collection-source"><a href="{if $resourceType == 'scenario'}/admin/vod-tools/scenario-collect?source_id={$source.id}{else /}/admin/collections/{$source.id}/resource{/if}"><strong>{$source.name|htmlspecialchars|raw}</strong> {$source.endpoint|htmlspecialchars|raw}</a> {if $resourceType == 'scenario'}{if $source.status == 'enabled'}<span class="collection-publish-on">[已启用]</span>{else /}<span class="collection-publish-off">[已停用]</span>{/if}{else /}{if $source.new_data_policy == 'published'}<span class="collection-publish-on">[新增显示]</span>{elseif $source.new_data_policy == 'draft' /}<span class="collection-publish-off">[新增隐藏]</span>{else /}<span>[只更新]</span>{/if}{/if}</td>
            <td class="collection-action">{if $resourceType == 'scenario'}<a href="/admin/vod-tools/scenario-collect?source_id={$source.id}">剧情采集</a>{else /}<a href="/admin/collections/{$source.id}/resource#category-bind">分类转换</a>{/if}</td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定立即采集当天更新？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="h" value="24"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集当天</button></form></td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定立即采集本周更新？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="h" value="168"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集本周</button></form></td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="将从第 1 页开始分批采集全部资源，确定继续？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集所有</button></form></td>
            <td class="collection-manage"><a href="/admin/collections/{$source.id}/edit">修改</a> <form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/delete" data-confirm="确定删除这个资源库？已有采集记录将保留。"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button class="link-button" type="submit">删除</button></form></td>
        </tr>{/volist}{empty name="sources"}<tr class="empty-row"><td colspan="7">暂无{$resourceLabel}资源库，请先添加{$resourceLabel}资源库。</td></tr>{/empty}</tbody>
    </table>
</div>
{include file="admin/layout/footer" /}
