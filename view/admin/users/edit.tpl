{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="#">{if $isNew}添加用户{else /}编辑用户{/if}</a></div><div class="actions"><a href="/admin/users">返回用户管理</a>{if !$isNew}<a href="/admin/users/{$item.id}/logs">用户记录</a>{/if}</div></div>
<form class="editor-form" method="post" action="{if $isNew}/admin/users{else /}/admin/users/{$item.id}{/if}">
  <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
  <section class="editor-section">
    <h2>用户基本信息</h2>
    <div class="legacy-form-row"><div class="legacy-form-label">用户名：</div><div class="legacy-form-field"><input class="w300" name="username" value="{$item.username|htmlspecialchars|raw}" maxlength="80" required><span class="field-note">用于登录，不可与现有用户重复。</span></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">邮箱地址：</div><div class="legacy-form-field"><input class="w300" name="email" type="email" value="{$item.email|default=''|htmlspecialchars|raw}"></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">{if $isNew}登录密码{else /}更换密码{/if}：</div><div class="legacy-form-field"><input class="w300" name="password" type="password" minlength="8" {if $isNew}required{/if}><span class="field-note">{if !$isNew}留空则保持原密码不变。{else /}至少 8 位。{/if}</span></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">用户组：</div><div class="legacy-form-field"><select name="group_key" class="w200"><option value="member" {if $item.group_key=='member'}selected{/if}>普通会员</option><option value="vip" {if $item.group_key=='vip'}selected{/if}>VIP 会员</option><option value="editor" {if $item.group_key=='editor'}selected{/if}>编辑用户</option>{if $item.group_key!='member' && $item.group_key!='vip' && $item.group_key!='editor'}<option value="{$item.group_key|htmlspecialchars|raw}" selected>{$item.group_key|htmlspecialchars|raw}</option>{/if}</select></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">用户积分：</div><div class="legacy-form-field"><input class="w150" name="points" type="number" value="{$item.points|default=0}"><span class="field-note">可用于积分点播。</span></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">VIP 到期时间：</div><div class="legacy-form-field"><input class="w300" name="expires_at" type="datetime-local" value="{$item.expires_at|default=''|htmlspecialchars|raw}"><span class="field-note">留空表示没有有效 VIP 时间。</span></div></div>
    <div class="legacy-form-row"><div class="legacy-form-label">用户状态：</div><div class="legacy-form-field"><select name="status" class="w150"><option value="active" {if $item.status == 'active'}selected{/if}>正常</option><option value="disabled" {if $item.status == 'disabled'}selected{/if}>禁用</option><option value="banned" {if $item.status == 'banned'}selected{/if}>封禁</option></select></div></div>
    <div class="form-actions"><button type="submit">提交保存</button><button type="reset">重置</button></div>
  </section>
</form>
{if !$isNew}<form class="editor-form editor-section danger-zone" method="post" action="/admin/users/{$item.id}/delete" data-confirm="确定归档这个用户？"><input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}"><h2>危险操作</h2><p class="muted">归档后用户无法登录，数据保留便于审计。</p><div class="form-actions"><button class="button danger" type="submit">归档用户</button></div></form>{/if}
{include file="admin/layout/footer" /}
