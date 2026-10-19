{include file="admin/layout/header" /}
<div class="title"><div class="tabs"><a class="active" href="/admin/tools/legacy-upgrade">4.3 数据升级</a></div></div>

<div class="box legacy-upgrade-intro">
    <div class="box-title">{$manifest.name|htmlspecialchars|raw} <small>v{$manifest.version|htmlspecialchars|raw}</small></div>
    <div class="legacy-upgrade-note">
        <strong>用途：</strong>把旧站业务数据迁移到当前 8.5 新结构，不修改、删除旧库数据。插件按批执行并写入 <code>ffx_legacy_map</code>，中断后可重新开始；未变化的记录会自动跳过，旧记录变化后会更新已迁移数据。<br>
        <strong>迁移前：</strong>请先备份 4.3 与 8.5 数据库。当前 8.5 管理员不会被旧管理员覆盖；4.3 会员的 MD5 密码会在首次成功登录后自动升级为现代密码散列。
    </div>
</div>

<form id="legacy-upgrade-form" class="legacy-settings-form" autocomplete="off">
    <input type="hidden" name="_token" value="{$csrf|htmlspecialchars|raw}">
    <div class="box">
        <div class="box-title">旧版数据库连接</div>
        <div class="legacy-form-row"><label>同一数据库：</label><div class="legacy-form-control"><label><input id="same-database" type="checkbox" name="same_database" value="1"> 4.3 的 <code>ff_</code> 表就在当前 8.5 数据库内</label></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-host">数据库主机：</label><div class="legacy-form-control"><input id="legacy-host" name="host" value="{$defaults.host|htmlspecialchars|raw}" required></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-port">端口：</label><div class="legacy-form-control"><input id="legacy-port" name="port" type="number" min="1" max="65535" value="{$defaults.port|htmlspecialchars|raw}" required></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-database">旧数据库名：</label><div class="legacy-form-control"><input id="legacy-database" name="database" value="{$defaults.database|htmlspecialchars|raw}" required></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-username">旧库用户名：</label><div class="legacy-form-control"><input id="legacy-username" name="username" value="{$defaults.username|htmlspecialchars|raw}" required></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-password">旧库密码：</label><div class="legacy-form-control"><input id="legacy-password" name="password" type="password" value="" autocomplete="new-password"><span class="help">仅随当前请求传输，不写入数据库、日志或页面。</span></div></div>
        <div class="legacy-form-row"><label for="legacy-prefix">旧表前缀：</label><div class="legacy-form-control"><input id="legacy-prefix" name="prefix" value="{$defaults.prefix|htmlspecialchars|raw}" required><span class="help">4.3 默认是 ff_</span></div></div>
        <div class="legacy-form-row legacy-remote-field"><label for="legacy-charset">旧库字符集：</label><div class="legacy-form-control"><select id="legacy-charset" name="charset"><option value="utf8mb4">utf8mb4</option><option value="utf8">utf8</option></select></div></div>
        <div class="legacy-form-row"><label for="legacy-batch">每批条数：</label><div class="legacy-form-control"><input id="legacy-batch" name="batch_size" type="number" min="10" max="500" value="{$defaults.batch_size}"><span class="help">数据量大时建议 100；超时可降到 20～50。</span></div></div>
    </div>

    <div class="box">
        <div class="box-title">迁移范围与进度</div>
        <div class="table-wrap"><table class="data-table legacy-upgrade-table">
            <thead><tr><th><input id="legacy-select-all" type="checkbox" checked aria-label="全选"></th><th>模块</th><th>旧表</th><th>旧库数量</th><th>已映射</th><th>本次进度</th><th>状态</th></tr></thead>
            <tbody>
            {volist name="modules" id="module" key="moduleKey"}
            <tr data-module="{$moduleKey|htmlspecialchars|raw}">
                <td><input class="legacy-module" type="checkbox" name="modules[]" value="{$moduleKey|htmlspecialchars|raw}" checked></td>
                <td>{$module.label|htmlspecialchars|raw}</td><td><code>ff_{$module.table|htmlspecialchars|raw}</code></td>
                <td data-cell="count">-</td><td data-cell="migrated">-</td><td data-cell="progress">0</td><td data-cell="status">等待预检</td>
            </tr>
            {/volist}
            </tbody>
        </table></div>
        <div class="tool-action-panel legacy-upgrade-actions">
            <button type="button" id="legacy-preflight">连接并预检</button>
            <button type="button" id="legacy-dry-run">只验证，不写入</button>
            <button type="button" id="legacy-start" class="primary">开始/继续升级</button>
            <button type="button" id="legacy-stop" disabled>暂停</button>
            <span id="legacy-summary" aria-live="polite">尚未连接旧数据库。</span>
        </div>
        <pre id="legacy-log" class="legacy-upgrade-log" aria-live="polite"></pre>
    </div>
</form>

<script>
(() => {
    const form = document.getElementById('legacy-upgrade-form');
    const same = document.getElementById('same-database');
    const preflight = document.getElementById('legacy-preflight');
    const start = document.getElementById('legacy-start');
    const dry = document.getElementById('legacy-dry-run');
    const stop = document.getElementById('legacy-stop');
    const summary = document.getElementById('legacy-summary');
    const log = document.getElementById('legacy-log');
    let stopped = false;

    const setRemoteFields = () => {
        document.querySelectorAll('.legacy-remote-field input, .legacy-remote-field select').forEach((field) => {
            field.disabled = same.checked;
            if (field.hasAttribute('required')) field.required = !same.checked;
        });
    };
    // Avoid a bare CSS-brace sequence here: ThinkPHP treats css-braced text as
    // its built-in stylesheet tag while compiling this JavaScript block.
    const row = (module) => document.querySelector('[data-module="' + CSS.escape(module) + '"]');
    const cell = (module, name) => row(module)?.querySelector('[data-cell="' + name + '"]');
    const append = (message) => { log.textContent += `${new Date().toLocaleTimeString()} ${message}\n`; log.scrollTop = log.scrollHeight; };
    const payload = (extra = {}) => {
        const data = new FormData(form);
        if (same.checked) data.set('same_database', '1'); else data.set('same_database', '0');
        Object.entries(extra).forEach(([key, value]) => data.set(key, String(value)));
        return data;
    };
    const post = async (url, data) => {
        const response = await fetch(url, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const body = await response.json().catch(() => ({ok: false, message: `HTTP ${response.status}`}));
        if (!response.ok || !body.ok) throw new Error(body.message || `HTTP ${response.status}`);
        return body;
    };
    const doPreflight = async () => {
        summary.textContent = '正在连接旧数据库…';
        const body = await post('/admin/tools/legacy-upgrade/preflight', payload());
        Object.entries(body.data.modules).forEach(([module, data]) => {
            if (!row(module)) return;
            cell(module, 'count').textContent = data.count;
            cell(module, 'migrated').textContent = data.migrated;
            cell(module, 'status').textContent = data.exists ? '可迁移' : '旧表不存在';
            row(module).classList.toggle('is-missing', !data.exists);
        });
        if (!body.data.compatible) throw new Error('没有检测到完整的 ff_list 与 ff_vod 核心表，请确认库名和表前缀。');
        const warning = body.data.warnings.length ? `；${body.data.warnings.join('；')}` : '';
        summary.textContent = `识别为 ${body.data.version}，预检通过${warning}`;
        append(`预检通过，表前缀 ${body.data.prefix}`);
        return body.data;
    };
    const run = async (dryRun) => {
        stopped = false; start.disabled = true; dry.disabled = true; preflight.disabled = true; stop.disabled = false;
        let totals = {processed: 0, created: 0, updated: 0, skipped: 0, errors: 0};
        try {
            const info = await doPreflight();
            const selected = [...document.querySelectorAll('.legacy-module:checked')].map((input) => input.value).filter((module) => info.modules[module]?.exists);
            if (!selected.length) throw new Error('请至少选择一个存在的迁移模块。');
            for (const module of selected) {
                if (stopped) break;
                let cursor = 0;
                cell(module, 'status').textContent = dryRun ? '验证中' : '迁移中';
                while (!stopped) {
                    const body = await post('/admin/tools/legacy-upgrade/batch', payload({module, cursor, dry_run: dryRun ? 1 : 0}));
                    const result = body.data;
                    cursor = result.cursor;
                    ['processed', 'created', 'updated', 'skipped', 'errors'].forEach((key) => totals[key] += Number(result[key] || 0));
                    cell(module, 'progress').textContent = cursor;
                    cell(module, 'status').textContent = `新增 ${totals.created} / 更新 ${totals.updated} / 错误 ${totals.errors}`;
                    result.messages.forEach((message) => append(`${result.label} ${message}`));
                    summary.textContent = `已处理 ${totals.processed}，新增 ${totals.created}，更新 ${totals.updated}，跳过 ${totals.skipped}，错误 ${totals.errors}`;
                    if (result.done) { cell(module, 'status').textContent = result.errors ? `完成，有 ${result.errors} 条错误` : '完成'; append(`${result.label}完成，游标 ${cursor}`); break; }
                }
            }
            if (!stopped && !dryRun) {
                const finished = await post('/admin/tools/legacy-upgrade/finish', payload());
                append(finished.message);
                document.getElementById('legacy-password').value = '';
            }
            if (dryRun && !stopped) append('只读验证完成，没有写入 8.5 数据库。');
            if (stopped) append('已暂停。再次点击“开始/继续升级”会安全跳过已迁移且未变化的数据。');
        } catch (error) {
            summary.textContent = `失败：${error.message}`;
            append(`失败：${error.message}`);
        } finally {
            start.disabled = false; dry.disabled = false; preflight.disabled = false; stop.disabled = true;
        }
    };

    same.addEventListener('change', setRemoteFields);
    document.getElementById('legacy-select-all').addEventListener('change', (event) => document.querySelectorAll('.legacy-module').forEach((input) => input.checked = event.target.checked));
    preflight.addEventListener('click', () => doPreflight().catch((error) => { summary.textContent = `失败：${error.message}`; append(`失败：${error.message}`); }));
    start.addEventListener('click', () => run(false));
    dry.addEventListener('click', () => run(true));
    stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
    setRemoteFields();
})();
</script>
{include file="admin/layout/footer" /}
