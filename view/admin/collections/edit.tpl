{include file="admin/layout/header" /}
<div class="title">
    <div class="tabs"><a class="active" href="#source-form">{if $isNew}添加采集节点{else /}编辑采集节点{/if}</a></div>
    <div class="actions"><a class="button secondary" href="/admin/collections">返回资源库</a></div>
</div>
<form class="editor-form" id="source-form" method="post" action="{if $isNew}/admin/collections{else /}/admin/collections/{$item.id}{/if}">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
    <section class="editor-section">
        <div class="legacy-form-row">
            <div class="legacy-form-label">资源库名称：</div>
            <div class="legacy-form-field"><input class="w300" name="name" value="{$item.name|htmlspecialchars|raw}" required><span class="field-note">例如：暴风资源、海外资讯库</span></div>
        </div>
        <div class="legacy-form-row">
            <div class="legacy-form-label">资源库地址：</div>
            <div class="legacy-form-field"><input class="w660" name="endpoint" type="url" value="{$item.endpoint|htmlspecialchars|raw}" placeholder="https://example.com/api.php/provide/vod/" required><span class="field-note">填完整 API 地址，系统自动识别协议和分类。</span></div>
        </div>
        <div class="legacy-form-row">
            <div class="legacy-form-label">接口协议：</div>
            <div class="legacy-form-field legacy-choice-list"><label><input type="radio" name="source_type" value="auto_json" {if $item.source_type != 'feifei_json' && $item.source_type != 'maccms_json'}checked{/if}> 自动识别</label><label><input type="radio" name="source_type" value="feifei_json" {if $item.source_type == 'feifei_json'}checked{/if}> FeiFeiCMS 7.3 JSON</label><label><input type="radio" name="source_type" value="maccms_json" {if $item.source_type == 'maccms_json'}checked{/if}> MacCMS JSON</label><span class="field-note">一般保持自动识别；飞飞协议会同时采集独立分集剧情。</span></div>
        </div>
        <div class="legacy-form-row">
            <div class="legacy-form-label">AppKey：</div>
            <div class="legacy-form-field"><input class="w300" name="credential_ref" value="{$item.credential_ref|default=''|htmlspecialchars|raw}" autocomplete="off"><span class="field-note">公开资源库留空；需要授权时才填。</span></div>
        </div>
        <div class="legacy-form-row">
            <div class="legacy-form-label">资源类型：</div>
            <div class="legacy-form-field legacy-choice-list" data-collection-resource-type>
                <label><input type="radio" name="resource_type" value="video" {if $item.resource_type != 'scenario'}checked{/if}> 视频</label>
                <label><input type="radio" name="resource_type" value="scenario" {if $item.resource_type == 'scenario'}checked{/if}> 剧情</label>
                <label><input type="radio" disabled> 资讯</label>
                <label><input type="radio" disabled> 用户</label>
                <label><input type="radio" disabled> 评论</label>
                <label><input type="radio" disabled> 人物/角色</label>
                <span class="field-note">剧情源只采集独立分集剧情，不修改视频资料和播放线路。</span>
            </div>
        </div>
        <div class="legacy-form-row" data-scenario-source-row>
            <div class="legacy-form-label">关联视频资源库：</div>
            <div class="legacy-form-field"><select class="w300" name="media_source_id"><option value="0">自动按片名唯一匹配</option>{volist name="videoSources" id="videoSource"}<option value="{$videoSource.id}" {if $item.media_source_id == $videoSource.id}selected{/if}>{$videoSource.name|htmlspecialchars|raw}</option>{/volist}</select><span class="field-note">推荐选择对应的视频源，系统会优先按相同远程视频 ID 关联剧情与影片。</span></div>
        </div>
        <div class="legacy-form-row" data-video-source-row>
            <div class="legacy-form-label">视频新增数据：</div>
            <div class="legacy-form-field"><select class="w300" name="new_data_policy"><option value="published" {if $item.new_data_policy == 'published'}selected{/if}>新增并显示</option><option value="draft" {if $item.new_data_policy == 'draft'}selected{/if}>新增但隐藏（待审核）</option><option value="update_only" {if $item.new_data_policy == 'update_only'}selected{/if}>只更新，不新增</option></select><span class="field-note">仅对视频库生效；选择“只更新”后，该线路无法新增影片，只更新已匹配到的本地影片。</span></div>
        </div>
        <div class="legacy-form-row">
            <div class="legacy-form-label">启用状态：</div>
            <div class="legacy-form-field"><label><input type="checkbox" name="status" value="enabled" {if $item.status == 'enabled'}checked{/if}> 启用该资源库</label></div>
        </div>
        <div class="form-actions"><button type="submit">提交保存</button><button type="reset">重置</button></div>
    </section>
</form>
{if !$isNew}
<section class="editor-section legacy-notice">{if $item.resource_type == 'scenario'}<p>保存后进入剧情采集控制台，可按页、指定远程 ID 或连续分页采集。</p><p><a class="button" href="/admin/vod-tools/scenario-collect?source_id={$item.id}">进入剧情采集</a></p>{else /}<p>保存后点击“进入资源库”，直接看资源、绑定分类和采集，不需要手写 JSON。</p><p><a class="button" href="/admin/collections/{$item.id}/resource">进入资源库</a></p>{/if}</section>
<form class="editor-form editor-section danger-zone" method="post" action="/admin/collections/{$item.id}/delete" data-confirm="确定禁用这个资源库？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><div class="form-actions"><button class="button danger" type="submit">禁用资源库</button></div></form>
{/if}
{include file="admin/layout/footer" /}
