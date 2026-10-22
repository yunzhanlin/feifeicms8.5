{include file="admin/layout/header" /}
{if $error != ''}<div class="legacy-alert error"><strong>资源库连接失败：</strong>{$error|htmlspecialchars|raw}<br><small>请检查地址、网络或上游是否限制访问。该次检测没有写入影片数据。</small></div>{/if}

{if $upstream.categories}
<div class="table-wrap legacy-cj-wrap" id="category-bind"><table class="data-table legacy-cj-table">
    <thead><tr><th class="legacy-cj-heading"><span>分类转换{if $filters.t > 0}　<a class="collection-publish-off" href="/admin/collections/{$source.id}/resource">全部资源</a>{/if}</span><span><a href="/admin/collections">返回资源库列表</a></span></th></tr></thead>
    <tbody><tr><td class="legacy-cj-bind-list"><ul>{volist name="upstream.categories" id="remote"}
        <li>
            <a href="?t={$remote.id|urlencode}&h={$filters.h}&wd={$filters.wd|urlencode}">{$remote.name|htmlspecialchars|raw}（{$remote.id|htmlspecialchars|raw}）</a>
            <details class="legacy-bind-control">
                <summary class="{if isset($mapping[$remote.id]) && $mapping[$remote.id] > 0}is-bound{else /}is-unbound{/if}" aria-label="修改{$remote.name|htmlspecialchars|raw}分类绑定">{if isset($mapping[$remote.id]) && $mapping[$remote.id] > 0}已绑定：{$categoryNames[$mapping[$remote.id]]|htmlspecialchars|raw}{else /}未绑定{/if}</summary>
                <form method="post" action="/admin/collections/{$source.id}/mapping" aria-label="{$remote.name|htmlspecialchars|raw}分类绑定">
                    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="partial" value="1">
                    <label><span>转换为：</span><select name="mapping[{$remote.id|htmlspecialchars|raw}]"><option value="0" {if !isset($mapping[$remote.id]) || $mapping[$remote.id] == 0}selected{/if}>不绑定（跳过采集）</option>{volist name="categories" id="local"}<option value="{$local.id}" {if isset($mapping[$remote.id]) && $mapping[$remote.id] == $local.id}selected{/if}>{$local.name|htmlspecialchars|raw}</option>{/volist}</select></label>
                    {if !isset($mapping[$remote.id]) && !empty($suggestions[$remote.id])}<p>建议：{$categoryNames[$suggestions[$remote.id]]|htmlspecialchars|raw}（选择并保存后生效）</p>{/if}
                    <div class="legacy-bind-actions"><button type="submit">保存</button><button type="button" data-bind-cancel>取消</button></div>
                </form>
            </details>
        </li>
    {/volist}</ul></td></tr></tbody>
</table></div>
<div class="legacy-tool-help">分类建议不会自动保存；未绑定、已解绑或本地分类已停用的资源将跳过采集，不再默认入库到电影。修改绑定后重新采集同来源影片可修正主分类，已锁定影片不变。</div>
{/if}

<div class="table-wrap legacy-cj-wrap"><table class="data-table legacy-cj-table">
    <thead><tr><th class="legacy-cj-heading">搜索与操作</th></tr></thead>
    <tbody><tr><td class="legacy-cj-search-row">
        <form method="get" action="/admin/collections/{$source.id}/resource"><input type="hidden" name="t" value="{$filters.t}"><input type="hidden" name="h" value="{$filters.h}"><input class="w400" type="text" name="wd" maxlength="100" value="{$filters.wd|htmlspecialchars|raw}"><button type="submit">搜索一下</button></form>
        <form method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定立即采集当天更新？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="h" value="24"><input type="hidden" name="execute_now" value="1"><button type="submit">采集当天</button></form>
        <form method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定采集全部资源？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="execute_now" value="1"><button type="submit">采集所有</button></form>
        {if $filters.t > 0}<form method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定采集当前分类全部资源？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="t" value="{$filters.t}"><input type="hidden" name="execute_now" value="1"><button type="submit">采集本类</button></form>{/if}
    </td></tr></tbody>
</table></div>

<form method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定开始采集选中资源？">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="page" value="{$upstream.page}"><input type="hidden" name="pagecount" value="{$upstream.pagecount}"><input type="hidden" name="limit" value="{$upstream.limit}"><input type="hidden" name="h" value="{$filters.h}"><input type="hidden" name="t" value="{$filters.t}"><input type="hidden" name="wd" value="{$filters.wd|htmlspecialchars|raw}"><input type="hidden" name="execute_now" value="1">
    <div class="table-wrap legacy-cj-wrap"><table class="data-table legacy-cj-resource-table">
        <thead><tr><th class="cell-left">分类、片名、状态</th><th class="cell-left" width="250">来源</th><th width="100">入库分类</th><th width="180">更新时间</th></tr></thead>
        <tbody>{volist name="upstream.items" id="vod"}<tr><td class="cell-left"><input name="ids[]" type="checkbox" value="{$vod.id|htmlspecialchars|raw}" data-check-group="resource-ids">『{$vod.type_name|htmlspecialchars|raw}』{$vod.name|htmlspecialchars|raw} {if $vod.remarks != ''}<sup>{$vod.remarks|htmlspecialchars|raw}</sup>{/if} <a class="legacy-cj-check" href="/admin/vod?search={$vod.name|urlencode}" target="_blank">查</a></td><td class="text-center">{$vod.play_from|htmlspecialchars|raw}</td><td class="text-center">{if isset($mapping[$vod.type_id]) && $mapping[$vod.type_id] > 0}<span class="is-bound">已转换</span>{else /}<span class="is-unbound">未转换</span>{/if}</td><td class="text-center">{$vod.time|htmlspecialchars|raw}</td></tr>{/volist}{empty name="upstream.items"}<tr class="empty-row"><td colspan="4">暂无资源</td></tr>{/empty}</tbody>
        <tfoot>
            <tr><td colspan="4" class="legacy-cj-pages">
                <nav class="legacy-pagination" aria-label="资源列表分页">
                    <span class="legacy-page-info">第 {$upstream.page} / {$upstream.pagecount} 页</span>
                    {if $upstream.page > 1}
                    <a href="?pg=1&t={$filters.t}&h={$filters.h}&wd={$filters.wd|urlencode}">首页</a>
                    <a href="?pg={$upstream.page-1}&t={$filters.t}&h={$filters.h}&wd={$filters.wd|urlencode}">上一页</a>
                    {else /}<span class="legacy-page-disabled" aria-disabled="true">首页</span><span class="legacy-page-disabled" aria-disabled="true">上一页</span>{/if}
                    {volist name="paginationPages" id="pageNumber"}
                        {if $pageNumber == $upstream.page}<span class="legacy-page-current" aria-current="page">{$pageNumber}</span>
                        {else /}<a href="?pg={$pageNumber}&t={$filters.t}&h={$filters.h}&wd={$filters.wd|urlencode}" aria-label="第{$pageNumber}页">{$pageNumber}</a>{/if}
                    {/volist}
                    {if $upstream.page < $upstream.pagecount}
                    <a href="?pg={$upstream.page+1}&t={$filters.t}&h={$filters.h}&wd={$filters.wd|urlencode}">下一页</a>
                    <a href="?pg={$upstream.pagecount}&t={$filters.t}&h={$filters.h}&wd={$filters.wd|urlencode}">尾页</a>
                    {else /}<span class="legacy-page-disabled" aria-disabled="true">下一页</span><span class="legacy-page-disabled" aria-disabled="true">尾页</span>{/if}
                </nav>
            </td></tr>
            <tr><td colspan="4" class="legacy-cj-table-actions"><button type="button" data-check-all="resource-ids">全选</button><button type="button" data-invert-checks="resource-ids">反选</button><button type="submit" name="scope" value="selected">采集选中</button></td></tr>
        </tfoot>
    </table></div>
</form>

<form method="post" action="/admin/collections/{$source.id}/queue" data-confirm="确定按当前条件开始采集？">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="scope" value="all"><input type="hidden" name="execute_now" value="1">
    <div class="table-wrap legacy-cj-wrap"><table class="data-table legacy-cj-personal"><thead><tr><th colspan="2" class="legacy-cj-heading">个性采集</th></tr></thead><tbody>
        <tr><td class="legacy-cj-label">分类ID：</td><td><input class="w100 text-center" name="t" value="{$filters.t}"> 不限制，请留空</td></tr>
        <tr><td class="legacy-cj-label">更新时间：</td><td><input class="w100 text-center" name="h" value="24"> 单位：小时，不限制时间留空</td></tr>
        <tr><td class="legacy-cj-label">指定播放器：</td><td><input class="w100 text-center" name="play"> 如：youku，不限制，请留空</td></tr>
        <tr><td class="legacy-cj-label">每页采集数量：</td><td><input class="w100 text-center" name="limit" value="{$upstream.limit}"> 最大100</td></tr>
        <tr><td class="legacy-cj-label">相关操作：</td><td><button type="submit">开始采集</button></td></tr>
    </tbody></table></div>
</form>
{include file="admin/layout/footer" /}
