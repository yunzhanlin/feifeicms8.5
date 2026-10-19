{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/tools/duplicates">视频标题同名检测</a></div></div>
<form class="legacy-filter" method="get" action="/admin/tools/duplicates">
<div class="legacy-filter-row"><label>分类与长度：</label><div><select name="category_id"><option value="0">全部分类</option>{volist name="categories" id="category"}<option value="{$category.id}" {if $categoryId==$category.id}selected{/if}>{$category.name|htmlspecialchars|raw}</option>{/volist}</select> <input class="short-input" type="number" name="length" min="0" max="30" value="{$length}" aria-label="标题前缀长度"> <button type="submit">按长度检测</button> <a class="button secondary" href="/admin/tools/duplicates?category_id={$categoryId}&length=0">标题完全重复检测</a></div></div>
</form>
<div class="title"><div class="tabs"><span>检测结果</span></div></div>
<div class="table-wrap"><table class="data-table"><thead><tr><th>重复标题/前缀</th><th width="90">数量</th><th>对应视频</th></tr></thead><tbody>{volist name="groups" id="group"}<tr><td>{$group.duplicate_key|htmlspecialchars|raw}</td><td class="text-center">{$group.duplicate_count}</td><td>{volist name="group.items" id="item"}<a href="/admin/vod/{$item.id}/edit">#{$item.id} {$item.title|htmlspecialchars|raw}{if !empty($item.release_year)} ({$item.release_year}){/if}</a> &nbsp;{/volist}</td></tr>{/volist}</tbody></table></div>
<div class="title"><div class="tabs"><span>重名检测工具说明</span></div></div>
<div class="tool-help"><p>1：长度大于 0 时，按标题前若干字分组；长度为 0 时，检查完全相同的标题。</p><p>2：结果只做检测，点击视频进入编辑后再审核或归档，避免自动删错。</p></div>
{include file="admin/layout/footer" /}
