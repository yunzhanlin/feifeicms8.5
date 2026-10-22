{include file="admin/layout/header" /}
{if !empty($resumeJob) && !$trash}
<h2 class="legacy-collection-resume"><a href="/admin/collections/jobs">上次有采集任务没有完成，是否接着采集?</a></h2>
{/if}
<div class="table-wrap legacy-collection-wrap">
    <table class="data-table legacy-collection-table">
        <colgroup><col class="collection-col-order"><col><col class="collection-col-action" span="4"><col class="collection-col-manage"></colgroup>
        <thead><tr><th colspan="7" class="collection-list-heading"><span>{if $trash}API资源站（{$resourceLabel}）回收站{else /}API资源站（{$resourceLabel}）列表{/if}</span><span>{if $trash}<a href="/admin/collections?type={$resourceType}">返回资源库列表</a>{else /}<a href="/admin/collections?type={$resourceType}&amp;deleted=1">资源库回收站</a> | <a href="/admin/collections/create?type={$resourceType}">添加{$resourceLabel}资源库</a>{/if}</span></th></tr></thead>
        <tbody>{volist name="sources" id="source" key="order"}<tr>
            <td class="collection-order">{$order}、</td>
            {if $trash}
            <td class="collection-source"><strong>{$source.name|htmlspecialchars|raw}</strong> {$source.endpoint|htmlspecialchars|raw}</td>
            <td colspan="4" class="collection-source">已删除；影片、线路和采集历史均保留。</td>
            <td class="collection-manage"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/restore"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button class="link-button" type="submit">恢复</button></form></td>
            {else /}
            <td class="collection-source"><a href="{if $resourceType == 'scenario'}/admin/vod-tools/scenario-collect?source_id={$source.id}{else /}/admin/collections/{$source.id}/resource{/if}"><strong>{$source.name|htmlspecialchars|raw}</strong> {$source.endpoint|htmlspecialchars|raw}</a> {if $resourceType == 'scenario'}{if $source.status == 'enabled'}<span class="collection-publish-on">[已启用]</span>{else /}<span class="collection-publish-off">[已停用]</span>{/if}{else /}{if $source.new_data_policy == 'published'}<span class="collection-publish-on">[新增显示]</span>{elseif $source.new_data_policy == 'draft' /}<span class="collection-publish-off">[新增隐藏]</span>{else /}<span>[只更新]</span>{/if}{/if}</td>
            <td class="collection-action">{if $resourceType == 'scenario'}<a href="/admin/vod-tools/scenario-collect?source_id={$source.id}">剧情采集</a>{else /}<a href="/admin/collections/{$source.id}/resource#category-bind">分类转换</a>{/if}</td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定立即采集当天更新？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="h" value="24"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集当天</button></form></td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定立即采集本周更新？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="h" value="168"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集本周</button></form></td>
            <td class="collection-action"><form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/queue" data-confirm="将从第 1 页开始分批采集全部资源，确定继续？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="execute_now" value="1"><button class="link-button" type="submit">采集所有</button></form></td>
            <td class="collection-manage"><a href="/admin/collections/{$source.id}/edit">修改</a> <form class="legacy-inline-action" method="post" action="/admin/collections/{$source.id}/delete" data-confirm="确定将这个资源库移入回收站？待执行任务会取消，计划任务会停用；已有影片和采集记录保留。"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><button class="link-button" type="submit">删除</button></form></td>
            {/if}
        </tr>{/volist}{empty name="sources"}<tr class="empty-row"><td colspan="7">{if $trash}回收站为空。{else /}暂无{$resourceLabel}资源库，请先添加{$resourceLabel}资源库。{/if}</td></tr>{/empty}</tbody>
    </table>
</div>
<div class="legacy-tool-help">删除后可在资源库回收站恢复，不删除已有影片、线路或采集历史。恢复后资源库默认停用，旧任务与计划不会自动重新启动，请核对配置后再启用。</div>
{include file="admin/layout/footer" /}
