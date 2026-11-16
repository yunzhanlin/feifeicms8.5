{include file="admin/layout/header" /}
<div class="title">
    <div class="left">采集进度 · #{$job.id}</div>
    <div class="actions">
        {if $job.source_name}<a href="/admin/collections/{$job.source_id}/resource?t={$status.category}">返回资源库</a>{/if}
        <a href="/admin/collections/jobs">采集记录</a>
    </div>
</div>
<div class="table-wrap">
    <table class="data-table">
        <thead><tr><th colspan="6" class="legacy-cj-heading">{$job.source_name|default='未知源'|htmlspecialchars|raw} · {$status.scope}{if $status.category > 0} · 分类 ID {$status.category}{/if}{if $status.hours > 0} · 最近 {$status.hours} 小时{/if}</th></tr></thead>
        <tbody>
            <tr><td colspan="6">
                <strong id="collection-status" role="status">
                    {switch name="status.state"}
                    {case value="queued"}等待采集第 {$status.page} 页{/case}
                    {case value="running"}正在采集第 {$status.page} 页{/case}
                    {case value="completed"}采集完成{/case}
                    {case value="completed_with_errors"}采集完成，有错误{/case}
                    {case value="failed"}第 {$status.page} 页失败{/case}
                    {default /}任务已停止
                    {/switch}
                </strong>
                <span> · 已完成 <span id="collection-pages">{$status.donePages}{if $status.pageTotal > 0} / {$status.pageTotal}{/if}</span> 页</span>
                <progress id="collection-bar" aria-label="采集页数" {if $status.pageTotal > 0}max="{$status.pageTotal}" value="{$status.donePages}"{else /}hidden{/if}></progress>
            </td></tr>
            <tr><th>处理</th><th>新增</th><th id="collection-updated-label">{if $job.resource_type == 'scenario'}写入剧情{else /}更新{/if}</th><th>跳过</th><th>错误</th><th>操作</th></tr>
            <tr>
                <td id="collection-processed">{$status.processed}</td>
                <td id="collection-created">{$status.created}</td>
                <td id="collection-updated">{$status.updated}</td>
                <td id="collection-skipped">{$status.skipped}</td>
                <td id="collection-errors">{$status.errors}</td>
                <td class="actions">
                    <form id="collection-step" method="post" action="/admin/collections/jobs/{$job.id}/execute" data-state="{$status.state|htmlspecialchars|raw}" data-auto="{if $autoRun}1{else /}0{/if}" data-delay="{$delay}" data-last="{$status.lastPage}" {if $status.state != 'queued' && $status.state != 'running'}hidden{/if}>
                        <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
                        <input type="hidden" name="progress" value="1">
                        <input type="hidden" name="page" value="{$status.page}">
                        <span><button type="submit" id="collection-start">继续采集</button></span>
                        <span hidden><button type="button" id="collection-pause">暂停</button></span>
                    </form>
                    <form id="collection-retry" method="post" action="/admin/collections/jobs/{$job.id}/retry" {if $status.state != 'failed'}hidden{/if}>
                        <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
                        <input type="hidden" name="progress" value="1">
                        <button type="submit">重试当前页</button>
                    </form>
                </td>
            </tr>
            <tr><td colspan="6">
                <pre id="collection-message" class="code-block" {if $status.message == ''}hidden{/if}>{$status.message|htmlspecialchars|raw}</pre>
                <ol id="collection-log" aria-label="逐页采集结果"></ol>
            </td></tr>
        </tbody>
    </table>
</div>
<script src="{:ff_asset_url('admin', 'js/collection-progress.js')}" defer></script>
{include file="admin/layout/footer" /}
