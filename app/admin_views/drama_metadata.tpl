{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/vod-tools/drama-metadata">分集剧情、演员与角色采集</a><a href="/admin/vod-tools/scenario-collect/resource">剧情资源库采集</a></div></div>
{if $message != ''}<div class="notice success">{$message|htmlspecialchars|raw}</div>{/if}
{if $error != ''}<div class="notice danger">{$error|htmlspecialchars|raw}</div>{/if}
{if !$media}
<form class="legacy-tool-control" method="get" action="/admin/vod-tools/drama-metadata">
    <label>本地影片名称 <input name="wd" value="{$keyword|htmlspecialchars|raw}" required maxlength="100"></label>
    <button type="submit">搜索</button>
</form>
{if $keyword != '' && !$local}<p>未找到本地影片。</p>{/if}
{notempty name="local"}
<div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>影片</th><th>年份</th><th>操作</th></tr></thead><tbody>
{volist name="local" id="item"}<tr><td>{$item.id}</td><td>{$item.title|htmlspecialchars|raw}</td><td>{$item.release_year}</td><td><a href="/admin/vod-tools/drama-metadata?media_id={$item.id}">选择</a></td></tr>{/volist}
</tbody></table></div>
{/notempty}
{else /}
<div class="scenario-media-summary"><strong>[{$media.id}] {$media.title|htmlspecialchars|raw}</strong>
<a href="/admin/vod-tools/drama-metadata">切换本地影片</a> <a href="/admin/scenarios/media/{$media.id}">管理本片剧情</a> <a href="/admin/vod/{$media.id}/edit">编辑影片</a></div>
<form class="legacy-tool-control" method="post" action="/admin/vod-tools/drama-metadata/lookup">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="media_id" value="{$media.id}">
    <label>来源 <select name="provider"><option value="tvzn" {if $provider == 'tvzn'}selected{/if}>TVZN 电视指南</option><option value="tmdb" {if $provider == 'tmdb'}selected{/if}>TMDB</option></select></label>
    <label>类型 <select name="type"><option value="tv" {if $type == 'tv'}selected{/if}>电视剧</option><option value="movie" {if $type == 'movie'}selected{/if}>电影（TMDB）</option></select></label>
    <label>来源剧名 <input name="title" value="{$keyword|htmlspecialchars|raw}" maxlength="100"></label>
    <label>来源 ID <input name="external_id" value="{$id|htmlspecialchars|raw}" inputmode="numeric" maxlength="10" placeholder="留空按剧名搜索"></label>
    <label>TMDB 季数 <input class="short-input" name="season" type="number" min="0" max="999" value="{$season}"></label>
    <label>本地集号加值 <input class="short-input" name="offset" type="number" min="0" max="99000" value="{$offset}"></label>
    <label><input type="checkbox" name="plots" value="1" {if $plots}checked{/if}> 分集剧情</label>
    <label><input type="checkbox" name="cast" value="1" {if $cast}checked{/if}> 演员与角色</label>
    <label>新增剧情 <select name="plot_status"><option value="draft" {if $status == 'draft'}selected{/if}>隐藏</option><option value="published" {if $status == 'published'}selected{/if}>显示</option></select></label>
    <button type="submit">搜索或预览</button>
</form>
<p>留空来源 ID 可搜索剧目。连续编号的多季视频可用集号加值，例如第二季第 1 集设加值 10 后写入本地第 11 集。TMDB 的分集剧情优先使用简体中文，中文缺失时尝试英文。<a href="/admin/settings/collection">TMDB 密钥设置</a></p>
{if $searched && !$results}<p>来源没有返回匹配剧目，请尝试原名或直接填写 ID。</p>{/if}
{notempty name="results"}
<div class="table-wrap"><table class="data-table"><thead><tr><th>来源 ID</th><th>剧目</th><th>资料</th><th>操作</th></tr></thead><tbody>
{volist name="results" id="item"}
<tr><td>{$item.id|htmlspecialchars|raw}</td><td><a href="{$item.url|htmlspecialchars|raw}" target="_blank" rel="noopener noreferrer">{$item.title|htmlspecialchars|raw}</a></td><td>{$item.detail|htmlspecialchars|raw}</td>
<td><form method="post" action="/admin/vod-tools/drama-metadata/lookup">
<input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="media_id" value="{$media.id}">
<input type="hidden" name="provider" value="{$provider}"><input type="hidden" name="type" value="{$type}">
<input type="hidden" name="external_id" value="{$item.id}"><input type="hidden" name="season" value="{$season}"><input type="hidden" name="offset" value="{$offset}">
{if $plots}<input type="hidden" name="plots" value="1">{/if}{if $cast}<input type="hidden" name="cast" value="1">{/if}
<input type="hidden" name="plot_status" value="{$status}"><button type="submit">预览</button></form></td></tr>
{/volist}
</tbody></table></div>
{/notempty}
{notempty name="preview"}
<div class="title"><div class="tabs"><a href="{$preview.url|htmlspecialchars|raw}" target="_blank" rel="noopener noreferrer">{$preview.title|htmlspecialchars|raw}</a></div></div>
{notempty name="preview.seasons"}<p>来源季数 {volist name="preview.seasons" id="item"}<span>第 {$item.number} 季 · {$item.name|htmlspecialchars|raw}（{$item.count} 集）　</span>{/volist}</p>{/notempty}
{if $previewToken == ''}<p>此剧目或季数没有可导入的资料。</p>{else /}
<p>找到 {$preview.episodes|count} 集剧情与 {$preview.cast|count} 条演员资料。导入会跳过已有的本地集号，并保留现有演员及角色资料。</p>
<details><summary>分集剧情预览</summary><div class="table-wrap"><table class="data-table">
<thead><tr><th>来源集号</th><th>标题</th><th>剧情</th></tr></thead><tbody>
{volist name="preview.episodes" id="item"}<tr><td>{$item.episode_no}</td><td><a href="{$item.url|htmlspecialchars|raw}" target="_blank" rel="noopener noreferrer">{$item.title|htmlspecialchars|raw}</a></td><td style="white-space:pre-line">{$item.content|htmlspecialchars|raw}</td></tr>{/volist}
</tbody></table></div></details>
<details><summary>演员与角色预览</summary><div class="table-wrap"><table class="data-table">
<thead><tr><th>演员</th><th>角色</th><th>角色简介</th></tr></thead><tbody>
{volist name="preview.cast" id="item"}<tr><td><a href="{$item.url|htmlspecialchars|raw}" target="_blank" rel="noopener noreferrer">{$item.name|htmlspecialchars|raw}</a></td><td>{$item.character|htmlspecialchars|raw}</td><td>{$item.character_bio|htmlspecialchars|raw}</td></tr>{/volist}
</tbody></table></div></details>
<form class="legacy-tool-control" method="post" action="/admin/vod-tools/drama-metadata/apply">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><input type="hidden" name="media_id" value="{$media.id}">
    <input type="hidden" name="preview_token" value="{$previewToken|htmlspecialchars|raw}"><button type="submit">确认导入</button>
</form>
{/if}
{/notempty}
{/if}
{include file="admin/layout/footer" /}
