{include file="admin/layout/header" /}
{if $section == 'rewrite'}
<div class="title legacy-rewrite-title"><div class="left">URL优化、伪静态配置</div></div>
{if $saved}<div class="notice success legacy-settings-saved" role="status">配置保存成功，URL 规则已经生效。</div>{/if}
{if $error != ''}<div class="notice danger legacy-settings-saved" role="alert">{$error|htmlspecialchars|raw}</div>{/if}
<form class="editor-form legacy-rewrite-form" method="post" action="/admin/settings/rewrite"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
    <div class="legacy-rewrite-row">
        <div class="legacy-rewrite-label">网站路径后缀：</div>
        <div class="legacy-rewrite-field"><select class="w128" name="url_html_suffix"><option value=".html" {if $values.url_html_suffix == '.html'}selected{/if}>.html</option><option value=".htm" {if $values.url_html_suffix == '.htm'}selected{/if}>.htm</option><option value=".shtml" {if $values.url_html_suffix == '.shtml'}selected{/if}>.shtml</option><option value=".shtm" {if $values.url_html_suffix == '.shtm'}selected{/if}>.shtm</option><option value="" {if $values.url_html_suffix == ''}selected{/if}>不需要后缀</option></select></div>
    </div>
    <div class="legacy-rewrite-row">
        <div class="legacy-rewrite-label">伪静态重写功能：</div>
        <div class="legacy-rewrite-field"><select class="w128" name="url_rewrite"><option value="0" {if $values.url_rewrite == '0'}selected{/if}>关闭</option><option value="1" {if $values.url_rewrite == '1'}selected{/if}>开启</option></select></div>
    </div>
    <div class="legacy-rewrite-row">
        <div class="legacy-rewrite-label">URL自定义开关：</div>
        <div class="legacy-rewrite-field"><select class="w128" name="url_router_on"><option value="0" {if $values.url_router_on == '0'}selected{/if}>关闭</option><option value="1" {if $values.url_router_on == '1'}selected{/if}>开启</option></select></div>
    </div>
    <div class="legacy-rewrite-row legacy-rewrite-rule-row">
        <div class="legacy-rewrite-label">URL自定义规则：</div>
        <div class="legacy-rewrite-field legacy-rewrite-rules">
            <textarea name="rewrite_route" id="rewrite_route">{$values.rewrite_route|htmlspecialchars|raw}</textarea>
            <p>请填写简略正则表达式，每行一条规则，中间使用 === 隔开，左边为站点默认 URL 模式，右边为替换后的 URL 模式。</p>
            <p>(:num)代表数字，(:letter)代表字母，(:letternum)代表字母加数字，(:any)代表任意 URL 字符。</p>
            <p>如替换视频详情页规则：vod-read-id-(:num)===video/detail/(:num)</p>
            <p>可选第三段为固定参数：规则===地址===参数名=参数值；注释行可使用 # 或 //。</p>
            <p class="rewrite-warning">(!) 警告：使用此功能之前请确定你对替换有所把握，错误的规则将导致页面无法访问。</p>
            <p>模板生成对应链接请使用 ff_url 函数；当前宝塔 Nginx 入口已开启 ThinkPHP 统一重写。</p>
        </div>
    </div>
    <div class="legacy-rewrite-row legacy-rewrite-test-row">
        <div class="legacy-rewrite-label">规则验证：</div>
        <div class="legacy-rewrite-field legacy-rewrite-tester">
            <label>默认 URL <input name="test_action" value="{$rewriteTest.action|default=''|htmlspecialchars|raw}" placeholder="vod-read-dir-movies-id-12"></label>
            <label>自定义路径 <input name="test_path" value="{$rewriteTest.path|default=''|htmlspecialchars|raw}" placeholder="movies/12（不含后缀）"></label>
            <button type="submit" formaction="/admin/settings/rewrite/preview">仅验证，不保存</button>
            {if !empty($rewriteTest.rewrite)}<span class="rewrite-test-result">生成：{$rewriteTest.rewrite|htmlspecialchars|raw}</span>{/if}
            {if !empty($rewriteTest.resolve)}<span class="rewrite-test-result">解析：{$rewriteTest.resolve|htmlspecialchars|raw}</span>{/if}
        </div>
    </div>
    {if !empty($rewritePreview)}<div class="legacy-rewrite-preview"><div class="title"><div class="left">规则编译预览（共 {$rewritePreview|count} 条）</div></div><table><thead><tr><th>行</th><th>默认 URL 样例</th><th>自定义 URL 样例</th></tr></thead><tbody>{volist name="rewritePreview" id="rule"}<tr><td>{$rule.line}</td><td>{$rule.sample_source|htmlspecialchars|raw}</td><td>{$rule.sample_target|htmlspecialchars|raw}</td></tr>{/volist}</tbody></table></div>{/if}
    <div class="form-actions legacy-rewrite-actions"><button type="submit">提交并生效</button><button type="reset">重置</button></div>
</form>
{else /}
{if $saved}<div class="notice success legacy-settings-saved" role="status">配置保存成功，当前设置已经生效。</div>{/if}
{if $tested}<div class="notice success legacy-settings-saved" role="status">SMTP 测试邮件已发送，请检查收件箱。</div>{/if}
{if $error != ''}<div class="notice danger legacy-settings-saved" role="alert">{$error|htmlspecialchars|raw}</div>{/if}
<form class="editor-form legacy-settings-form" method="post" action="/admin/settings/{$section}" data-settings-form><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
<div class="title legacy-settings-group-tabs" data-editor-tabs role="tablist" aria-label="{$definition.label|htmlspecialchars|raw}">
    <div class="tabs">{volist name="settingGroups" id="group" key="groupNo"}<a id="{$group.id}-tab" href="#{$group.id}" role="tab" aria-controls="{$group.id}" {if $groupNo==1}class="active" aria-selected="true"{else /}aria-selected="false"{/if}>{$group.label|htmlspecialchars|raw}</a>{/volist}</div>
</div>
{volist name="settingGroups" id="group" key="groupNo"}<section class="editor-section legacy-settings-add legacy-settings-panel" id="{$group.id}" role="tabpanel" aria-labelledby="{$group.id}-tab" {if $groupNo!=1}hidden{/if}>
{foreach $group.fields as $key=>$field}<label class="legacy-settings-row" {if !empty($field.visibleWhen)}data-setting-visible-when="{$field.visibleWhen|htmlspecialchars|raw}"{/if}><span class="legacy-setting-label">{$field.label|htmlspecialchars|raw}：</span><span class="legacy-setting-field">
{if $field.type == 'textarea'}<textarea class="setting-control setting-textarea" name="{$key}" rows="5">{$values[$key]|htmlspecialchars|raw}</textarea>
{elseif $field.type == 'select'}<select class="setting-control setting-select" name="{$key}">{foreach $field.options as $optionValue=>$optionLabel}<option value="{$optionValue|htmlspecialchars|raw}" {if $values[$key] == $optionValue}selected{/if}>{$optionLabel|htmlspecialchars|raw}</option>{/foreach}</select>
{elseif $field.type == 'radio'}<span class="setting-radio-group">{foreach $field.options as $optionValue=>$optionLabel}<span><input class="setting-radio" name="{$key}" type="radio" value="{$optionValue|htmlspecialchars|raw}" {if $values[$key] == $optionValue}checked{/if}> {$optionLabel|htmlspecialchars|raw}</span>{/foreach}</span>
{elseif $field.type == 'boolean'}<select class="setting-control setting-boolean" name="{$key}"><option value="1" {if $values[$key] == '1'}selected{/if}>开启</option><option value="0" {if $values[$key] == '0'}selected{/if}>关闭</option></select>
{elseif $field.type == 'readonly'}<input class="setting-control setting-readonly" name="{$key}" type="text" value="{$values[$key]|htmlspecialchars|raw}" readonly aria-readonly="true">
{else /}<input class="setting-control setting-{$field.type}" name="{$key}" type="{$field.type}" value="{$values[$key]|htmlspecialchars|raw}" {if !empty($field.secret)}autocomplete="new-password"{/if}>{/if}
{if !empty($field.required)}<b class="setting-required" aria-label="必填">*</b>{/if}
{if !empty($field.help)}<small class="setting-help {if $field.type == 'textarea'}setting-help-block{/if}">{$field.help|htmlspecialchars|raw}</small>{/if}
</span></label>{/foreach}</section>{/volist}
<div class="form-actions legacy-settings-actions"><button type="submit">提交</button><button type="reset">重置</button></div></form>
{if $section == 'email'}<section class="editor-section"><div class="section-heading"><h2>发送测试邮件</h2><small>请先保存上方 SMTP 配置；测试会真实连接服务器并发送邮件。</small></div><form class="legacy-settings-form" method="post" action="/admin/settings/email/test"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><label class="legacy-settings-row"><span class="legacy-setting-label">测试收件邮箱：</span><span class="legacy-setting-field"><input class="setting-control setting-email" type="email" name="recipient" value="{$values.email_usertest|htmlspecialchars|raw}" required></span></label><div class="form-actions legacy-settings-actions"><button type="submit">发送测试邮件</button></div></form></section>{/if}
{/if}
{include file="admin/layout/footer" /}
