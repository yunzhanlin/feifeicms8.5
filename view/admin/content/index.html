{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/content/{$type}">{$label|htmlspecialchars|raw}管理</a></div><div class="actions"><a href="/admin/content/{$type}/create">新增{$label|htmlspecialchars|raw}</a></div></div>
<form class="legacy-filter" method="get" action="/admin/content/{$type}">
  <label>内容搜索：</label><input name="wd" value="{$keyword|htmlspecialchars|raw}" placeholder="名称、标题">
  <select name="status"><option value="">全部状态</option><option value="draft" {if $status == 'draft'}selected{/if}>未审核</option><option value="published" {if $status == 'published'}selected{/if}>已审核</option></select>
  {if $type == 'people'}<select name="kind"><option value="">明星与角色</option><option value="person" {if $kind == 'person'}selected{/if}>明星</option><option value="role" {if $kind == 'role' || $kind == 'character'}selected{/if}>角色</option></select>{/if}
  <button type="submit">搜索</button><a href="/admin/content/{$type}">重置条件</a>
</form>
<form method="post" action="/admin/content/{$type}/batch" data-confirm="确定执行批量操作？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
<div class="table-wrap"><table class="data-table"><thead><tr><th class="w40"><input type="checkbox" data-check-all></th><th class="w70">ID</th><th>名称</th>{if $type == 'people'}<th>类型/地区</th>{else /}<th>分类</th>{/if}<th class="w100">状态</th><th class="w160">更新时间</th><th class="w120">操作</th></tr></thead><tbody>
{volist name="items" id="item"}<tr><td><input type="checkbox" name="ids[]" value="{$item.id}"></td><td>{$item.id}</td><td class="text-left"><strong>{if $type == 'people'}{$item.name|htmlspecialchars|raw}{else /}{$item.title|htmlspecialchars|raw}{/if}</strong>{notempty name="item.slug"}<br><small class="muted">{$item.slug|htmlspecialchars|raw}</small>{/notempty}</td><td>{if $type == 'people'}{if $item.kind == 'person'}明星{else /}角色{/if} / {$item.nationality|default='-'}{else /}#{$item.category_id|default='未分类'}{/if}</td><td>{if $item.status == 'published'}<span class="text-success">已审核</span>{else /}<span class="text-danger">未审核</span>{/if}</td><td>{$item.updated_at|htmlspecialchars|raw}</td><td><a href="/admin/content/{$type}/{$item.id}/edit">编辑</a>{if $type == 'people'} · <a href="/star/{$item.id}" target="_blank">查看</a>{elseif $type == 'articles'} · <a href="/news/{$item.id}" target="_blank">查看</a>{else /} · <a href="/special/{$item.id}" target="_blank">查看</a>{/if}</td></tr>{/volist}
{empty name="items"}<tr><td colspan="7">暂无数据</td></tr>{/empty}
</tbody></table></div>
<div class="legacy-toolbar"><span>批量操作：</span><button name="action" value="publish" type="submit">审核通过</button><button name="action" value="draft" type="submit">取消审核</button><button class="danger" name="action" value="archive" type="submit">归档</button></div>
</form><div class="pagination">{$items|raw}</div>
{include file="admin/layout/footer" /}
