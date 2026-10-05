(() => {
    const path = window.location.pathname.replace(/\/$/, '') || '/';
    const query = new URLSearchParams(window.location.search);
    let section = path === '/admin' ? 'system' : 'home';

    if (path.startsWith('/admin/tools') || path.startsWith('/admin/crontab')) section = 'tools';
    else if (path.startsWith('/admin/settings')) section = 'system';
    else if (path.startsWith('/admin/categories')) section = 'categories';
    else if (path.startsWith('/admin/collections')) section = 'collections';
    else if (path.startsWith('/admin/vod-tools/scenario-collect')) section = 'collections';
    else if (path.startsWith('/admin/scenarios')) section = 'scenarios';
    else if (path.startsWith('/admin/vod')) section = 'vod';
    else if (path.startsWith('/admin/content/articles')) section = 'articles';
    else if (path.startsWith('/admin/content/people')) section = 'people';
    else if (path.startsWith('/admin/content/topics')) section = 'topics';
    else if (path.startsWith('/admin/comments')) section = 'comments';
    else if (path.startsWith('/admin/tags')) section = 'tags';
    else if (path.startsWith('/admin/database')) section = 'database';
    else if (path.startsWith('/admin/administrators')) section = 'users';
    else if (path.startsWith('/admin/users')) section = 'users';
    else if (path.startsWith('/admin/operations/navigation')) section = 'navigation';
    else if (path.startsWith('/admin/operations/players')) section = 'system';
    else if (path.startsWith('/admin/operations') || path.startsWith('/admin/billing')) section = 'operations';
    else if (path.startsWith('/admin/system')) section = query.get('section') === 'tools' ? 'tools' : 'system';

    if (path !== '/admin') document.querySelector(`[data-admin-tab="${section}"]`)?.classList.add('active');
    document.querySelector(`[data-admin-menu="${section}"]`)?.classList.add('active');
    if (section === 'home') document.querySelector('[data-admin-menu="home"]')?.classList.add('active');

    const menu = document.querySelector(`[data-admin-menu="${section}"]`);
    const menuLinks = menu ? Array.from(menu.querySelectorAll('a')) : [];
    const exact = path === '/admin' ? null : menuLinks.find((link) => {
        const target = new URL(link.href, window.location.origin);
        return target.pathname.replace(/\/$/, '') === path && target.search === window.location.search;
    });
    const replacementLink = section === 'database' && path.startsWith('/admin/database/replace')
        ? menuLinks.find((link) => new URL(link.href, window.location.origin).pathname === '/admin/database/replace') : null;
    (replacementLink || exact || menuLinks[0])?.classList.add('active');

    const statusLabels = {
        published: '已发布', draft: '草稿', enabled: '已启用', disabled: '已禁用',
        active: '正常', banned: '已封禁', pending: '待审核', approved: '已通过', rejected: '已拒绝',
        queued: '排队中', running: '执行中', completed: '已完成', completed_with_errors: '部分失败', failed: '失败',
        paid: '已支付', confirmed: '已确认', unused: '未使用'
    };
    document.querySelectorAll('.status').forEach((node) => {
        const value = (node.textContent || '').trim();
        if (statusLabels[value]) node.textContent = statusLabels[value];
    });
    document.querySelectorAll('.data-table tbody').forEach((tbody) => {
        if (tbody.querySelector('tr')) return;
        const columnCount = tbody.closest('table')?.querySelectorAll('thead th').length || 1;
        const row = document.createElement('tr');
        row.className = 'empty-row';
        const cell = document.createElement('td');
        cell.colSpan = columnCount;
        cell.textContent = '暂无数据';
        row.appendChild(cell);
        tbody.appendChild(row);
    });

    document.querySelectorAll('[data-check-all]').forEach((control) => {
        control.addEventListener('click', () => {
            const form = control.closest('form');
            if (!form) return;
            const boxes = Array.from(form.querySelectorAll('input[type="checkbox"][name="ids[]"]'));
            const shouldCheck = boxes.some((box) => !box.checked);
            boxes.forEach((box) => { box.checked = shouldCheck; });
        });
    });

    document.querySelectorAll('[data-invert-checks]').forEach((control) => {
        control.addEventListener('click', () => {
            const form = control.closest('form');
            if (!form) return;
            const group = control.dataset.invertChecks || '';
            form.querySelectorAll('input[type="checkbox"][name="ids[]"]').forEach((box) => {
                if (group !== '' && box.dataset.checkGroup !== group) return;
                box.checked = !box.checked;
            });
        });
    });

    document.querySelectorAll('[data-batch-action]').forEach((control) => {
        control.addEventListener('click', () => {
            const form = control.closest('form');
            const action = form?.querySelector('input[name="action"]');
            if (!form || !(action instanceof HTMLInputElement)) return;
            action.value = control.dataset.batchAction || '';
            if (control.dataset.confirm && !window.confirm(control.dataset.confirm)) return;
            form.requestSubmit();
        });
    });

    document.querySelectorAll('[data-editor-tabs]').forEach((tabs) => {
        const links = Array.from(tabs.querySelectorAll('a[href^="#"]'));
        const panels = links.map((link) => document.querySelector(link.getAttribute('href'))).filter(Boolean);
        const activate = (link) => {
            const target = document.querySelector(link.getAttribute('href'));
            links.forEach((item) => item.classList.toggle('active', item === link));
            links.forEach((item) => item.setAttribute('aria-selected', item === link ? 'true' : 'false'));
            panels.forEach((panel) => { panel.hidden = panel !== target; });
        };
        links.forEach((link) => link.addEventListener('click', (event) => {
            event.preventDefault();
            activate(link);
        }));
        links.forEach((link, index) => link.addEventListener('keydown', (event) => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            let next = index;
            if (event.key === 'ArrowLeft') next = (index - 1 + links.length) % links.length;
            if (event.key === 'ArrowRight') next = (index + 1) % links.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = links.length - 1;
            activate(links[next]);
            links[next].focus();
        }));
        if (links[0]) activate(links.find((link) => link.classList.contains('active')) || links[0]);
    });

    document.querySelectorAll('[data-settings-form]').forEach((form) => {
        const syncDependencies = () => {
            form.querySelectorAll('[data-setting-visible-when]').forEach((row) => {
                const rule = row.getAttribute('data-setting-visible-when') || '';
                const separator = rule.indexOf(':');
                if (separator < 1) return;
                const name = rule.slice(0, separator);
                const expected = rule.slice(separator + 1);
                const source = form.querySelector(`[name="${CSS.escape(name)}"]`);
                const visible = source instanceof HTMLInputElement || source instanceof HTMLSelectElement || source instanceof HTMLTextAreaElement
                    ? source.value === expected
                    : true;
                row.hidden = !visible;
            });
        };
        form.querySelectorAll('input, select, textarea').forEach((control) => control.addEventListener('change', syncDependencies));
        form.addEventListener('reset', () => window.setTimeout(syncDependencies, 0));
        syncDependencies();
    });

    document.querySelectorAll('[data-cron-schedule]').forEach((form) => {
        const type = form.querySelector('select[name="schedule_type"]');
        const syncScheduleFields = () => {
            const selected = type instanceof HTMLSelectElement ? type.value : 'daily';
            form.querySelectorAll('[data-cron-field]').forEach((field) => {
                const active = field.getAttribute('data-cron-field') === selected;
                field.hidden = !active;
                field.querySelectorAll('input, select').forEach((control) => { control.disabled = !active; });
            });
        };
        type?.addEventListener('change', syncScheduleFields);
        syncScheduleFields();
    });

    document.querySelectorAll('[data-legacy-vod-form]').forEach((form) => {
        const list = form.querySelector('[data-play-source-list]');
        const token = form.querySelector('input[name="_token"]')?.value || '';
        const status = form.querySelector('[data-vod-status]');
        const setStatus = (message, isError = false) => {
            if (!status) return;
            status.textContent = message;
            status.classList.toggle('error', isError);
        };
        const field = (name) => form.querySelector(`[name="${CSS.escape(name)}"]`);
        const writeValue = (name, value, mode = 'replace') => {
            const control = field(name);
            if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)) return;
            const next = String(value ?? '').trim();
            if (!next) return;
            if (mode === 'append') {
                const values = control.value.split(/[,，]/).map((item) => item.trim()).filter(Boolean);
                next.split(/[,，]/).map((item) => item.trim()).filter(Boolean).forEach((item) => {
                    if (!values.includes(item)) values.push(item);
                });
                control.value = values.join(',');
            } else {
                control.value = next;
            }
            control.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const requestJson = async (url, body) => {
            const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({ ok: false, message: `请求失败（HTTP ${response.status}）` }));
            if (!response.ok || payload.ok === false) throw new Error(payload.message || `请求失败（HTTP ${response.status}）`);
            return payload;
        };
        const renumber = () => {
            Array.from(list?.querySelectorAll('.legacy-play-source-row') || []).forEach((row, index) => {
                const label = row.querySelector('.tl');
                if (label) label.innerHTML = `播放地址<strong>${index + 1}</strong>：`;
                const suffix = row.querySelector('[data-play-group-no]');
                if (suffix) suffix.textContent = `第${index + 1}组`;
            });
        };
        form.querySelector('[data-add-play-source]')?.addEventListener('click', (event) => {
            event.preventDefault();
            if (!list) return;
            const number = list.querySelectorAll('.legacy-play-source-row').length + 1;
            const configuredOptions = list.querySelector('.legacy-player-select')?.innerHTML || '';
            const row = document.createElement('tr');
            row.className = 'legacy-play-source-row';
            row.innerHTML = `<td class="tl">播放地址<strong>${number}</strong>：</td><td class="tr"><input type="hidden" name="play_source_id[]" value="0"><p class="play_list"><select name="play_source_key[]" class="legacy-player-select"><option value="line-${number}" selected>${number}.line-${number}.自定义线路</option>${configuredOptions}</select> <input type="text" name="play_source_name[]" value="自定义线路" class="w150" aria-label="线路名称"> <input type="text" name="play_parser_key[]" class="w150" placeholder="解析器标识"> <a href="#" data-play-repair>检正格式</a> <span data-play-group-no>第${number}组</span></p><p class="play_url"><textarea name="play_urls[]"></textarea></p></td>`;
            list.appendChild(row);
            row.querySelector('textarea')?.focus();
            renumber();
        });
        form.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const choice = target?.closest('[data-vod-choice]');
            if (choice) {
                event.preventDefault();
                writeValue(choice.getAttribute('data-target') || '', choice.textContent || '', choice.getAttribute('data-mode') || 'replace');
                return;
            }
            const filler = target?.closest('[data-fill-field]');
            if (filler) {
                event.preventDefault();
                writeValue(filler.getAttribute('data-fill-field') || '', filler.getAttribute('data-fill-value') || '');
                if (filler.getAttribute('data-set-completed') === '1') {
                    const completed = field('is_completed');
                    if (completed instanceof HTMLInputElement) completed.checked = true;
                }
                return;
            }
            const repair = event.target instanceof Element ? event.target.closest('[data-play-repair]') : null;
            if (!repair) return;
            event.preventDefault();
            const textarea = repair.closest('.tr')?.querySelector('textarea[name="play_urls[]"]');
            if (!(textarea instanceof HTMLTextAreaElement)) return;
            textarea.value = textarea.value.split(/\r?\n/).map((line, index) => {
                const value = line.trim();
                if (!value) return '';
                return value.includes('$') ? value : `第${index + 1}集$${value}`;
            }).filter(Boolean).join('\n');
        });

        form.querySelector('[data-douban-open]')?.addEventListener('click', () => {
            const id = String(form.querySelector('[data-douban-id]')?.value || '').trim();
            if (!/^\d{5,12}$/.test(id)) { setStatus('请先填写正确的豆瓣 ID', true); return; }
            window.open(`https://movie.douban.com/subject/${encodeURIComponent(id)}/`, '_blank', 'noopener');
        });

        form.querySelector('[data-douban-search]')?.addEventListener('click', () => {
            const title = String(field('title')?.value || '').trim();
            if (!title) { setStatus('请先填写视频名称', true); return; }
            window.open(`https://search.douban.com/movie/subject_search?search_text=${encodeURIComponent(title)}`, '_blank', 'noopener');
        });

        form.querySelector('[data-douban-fetch]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const id = String(form.querySelector('[data-douban-id]')?.value || '').trim();
            if (!/^\d{5,12}$/.test(id)) { setStatus('请先填写正确的豆瓣 ID', true); return; }
            const body = new FormData();
            body.append('_token', token);
            body.append('douban_id', id);
            if (button instanceof HTMLButtonElement) button.disabled = true;
            setStatus('正在获取豆瓣资料…');
            try {
                const payload = await requestJson('/admin/vod/metadata/douban', body);
                Object.entries(payload.data || {}).forEach(([name, value]) => {
                    if (name === 'source_ref') writeValue(name, value, 'append');
                    else writeValue(name, value);
                });
                setStatus(payload.message || '豆瓣资料已填入表单，请检查后提交保存。');
            } catch (error) {
                setStatus(error instanceof Error ? error.message : '豆瓣资料获取失败', true);
            } finally {
                if (button instanceof HTMLButtonElement) button.disabled = false;
            }
        });

        form.querySelector('[data-platform-import]')?.addEventListener('click', (event) => {
            event.preventDefault();
            const raw = String(form.querySelector('[data-platform-url]')?.value || '').trim();
            let url;
            try { url = new URL(raw); } catch { setStatus('请输入有效的平台播放链接', true); return; }
            if (!['http:', 'https:'].includes(url.protocol)) { setStatus('平台链接仅支持 HTTP 或 HTTPS', true); return; }
            const platforms = [
                [/qq\.com$/i, 'qq', '腾讯'], [/iqiyi\.com$/i, 'iqiyi', '爱奇艺'],
                [/mgtv\.com$/i, 'mgtv', '芒果'], [/youku\.com$/i, 'youku', '优酷'],
                [/(?:bilibili\.com|b23\.tv)$/i, 'bilibili', 'B站'],
            ];
            const matched = platforms.find(([pattern]) => pattern.test(url.hostname));
            const sourceKey = matched?.[1] || 'platform';
            const sourceName = matched?.[2] || '平台线路';
            let row = Array.from(list?.querySelectorAll('.legacy-play-source-row') || []).find((item) => !String(item.querySelector('textarea[name="play_urls[]"]')?.value || '').trim());
            if (!row) {
                form.querySelector('[data-add-play-source]')?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
                row = list?.querySelector('.legacy-play-source-row:last-child');
            }
            const select = row?.querySelector('select[name="play_source_key[]"]');
            if (select instanceof HTMLSelectElement) {
                let option = Array.from(select.options).find((item) => item.value === sourceKey);
                if (!option) { option = new Option(`${select.options.length + 1}.${sourceKey}.${sourceName}`, sourceKey); select.add(option); }
                select.value = sourceKey;
            }
            const name = row?.querySelector('input[name="play_source_name[]"]');
            if (name instanceof HTMLInputElement) name.value = sourceName;
            const urls = row?.querySelector('textarea[name="play_urls[]"]');
            if (urls instanceof HTMLTextAreaElement) urls.value = `正片$${url.toString()}`;
            setStatus(`已生成${sourceName}播放线路，请检查后提交保存。`);
        });

        form.querySelectorAll('[data-vod-upload]').forEach((button) => {
            button.addEventListener('click', async () => {
                const targetName = button.getAttribute('data-vod-upload') || '';
                const picker = form.querySelector(`[data-vod-upload-file="${CSS.escape(targetName)}"]`);
                if (!(picker instanceof HTMLInputElement) || !picker.files?.[0]) { setStatus('请先选择图片文件', true); return; }
                const body = new FormData();
                body.append('_token', token);
                body.append('file', picker.files[0]);
                if (button instanceof HTMLButtonElement) button.disabled = true;
                setStatus('正在上传图片…');
                try {
                    const payload = await requestJson('/admin/vod/upload', body);
                    writeValue(targetName, payload.url || '');
                    picker.value = '';
                    setStatus('图片上传完成，请提交保存。');
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '图片上传失败', true);
                } finally {
                    if (button instanceof HTMLButtonElement) button.disabled = false;
                }
            });
        });
    });

    document.querySelectorAll('[data-database-replace]').forEach((form) => {
        const table = form.querySelector('[data-replace-table]');
        const fields = form.querySelector('[data-replace-fields]');
        const fieldInput = form.querySelector('[name="field"]');
        const preview = document.querySelector('[data-replace-preview]');
        const submit = form.querySelector('button[type="submit"]');
        if (!(table instanceof HTMLSelectElement) || !(fieldInput instanceof HTMLInputElement) || !fields) return;
        let pending;
        const invalidate = () => {
            if (preview) preview.hidden = true;
        };
        const selectField = (name) => {
            fieldInput.value = name;
            invalidate();
            fields.querySelectorAll('[data-replace-field]').forEach((item) => {
                item.classList.toggle('active', item.dataset.replaceField === name);
            });
            form.querySelector('[name="search"]')?.focus();
        };
        fields.addEventListener('click', (event) => {
            const button = event.target instanceof Element ? event.target.closest('[data-replace-field]') : null;
            if (button instanceof HTMLButtonElement && !button.disabled) selectField(button.dataset.replaceField || '');
        });
        const loadFields = async () => {
            pending?.abort();
            const request = new AbortController();
            pending = request;
            fieldInput.value = '';
            fields.textContent = '正在读取字段……';
            if (submit instanceof HTMLButtonElement) submit.disabled = true;
            try {
                const response = await fetch(`/admin/database/replace/fields?table=${encodeURIComponent(table.value)}`, {
                    headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: request.signal
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || '字段读取失败');
                if (pending !== request) return;
                fields.replaceChildren();
                payload.fields.forEach((field) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'link-button';
                    button.dataset.replaceField = field.name;
                    button.textContent = field.name;
                    button.title = `${field.definition}${field.reason ? `；${field.reason}` : ''}`;
                    button.disabled = !field.editable;
                    fields.appendChild(button);
                });
                if (!payload.fields.some((field) => field.editable)) {
                    const note = document.createElement('p');
                    note.textContent = '该表没有可替换字段，请使用对应业务管理功能。';
                    fields.appendChild(note);
                }
            } catch (error) {
                if (pending === request && !request.signal.aborted) fields.textContent = error instanceof Error ? error.message : '字段读取失败，请刷新后重试。';
            } finally {
                if (pending === request && submit instanceof HTMLButtonElement) submit.disabled = false;
            }
        };
        form.addEventListener('input', invalidate);
        form.addEventListener('change', invalidate);
        table.addEventListener('change', loadFields);
        form.addEventListener('reset', () => {
            invalidate();
            queueMicrotask(loadFields);
        });
    });

    document.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const confirmedButton = target?.closest('button[data-confirm]');
        if (confirmedButton && !window.confirm(confirmedButton.dataset.confirm || '确定继续？')) {
            event.preventDefault();
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (form?.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            event.preventDefault();
        }
        if (form?.hasAttribute('data-replace-confirm') && !event.defaultPrevented) {
            form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
        }
    });
})();

(() => {
    const panel = document.querySelector('[data-update-panel]');
    const notice = document.querySelector('[data-update-notice]');
    if (!panel && !notice) return;
    const token = (panel || notice).dataset.updateToken || '';
    let release = null;
    let dialog = null;
    const message = panel?.querySelector('[data-update-message]');
    const releaseText = panel?.querySelector('[data-update-release]');
    const startButton = panel?.querySelector('[data-update-start]');
    const say = (value) => { if (message) message.textContent = value; if (dialog) dialog.querySelector('[data-update-dialog-message]').textContent = value; };

    const check = async () => {
        try {
            const response = await fetch('/admin/tools/version/status', { credentials: 'same-origin' });
            const data = await response.json();
            if (data.job && ['queued', 'running'].includes(data.job.status)) { say(data.job.message); watch(); return; }
            if (data.job?.status === 'failed') say('上次更新失败：' + data.job.message);
            if (!data.ok) { say('检查失败：' + (data.error || 'GitHub 暂时不可用')); return; }
            release = data.release;
            if (releaseText) releaseText.textContent = 'GitHub 最新发布：' + release.title + '（' + release.tag + '）';
            if (!release.available) {
                if (release.asset_ready === false) say('新版本已发布，正在等待 GitHub 安装包生成');
                else if (!data.job || data.job.status !== 'failed') say('当前已是最新版本');
                return;
            }
            say('发现新版本：' + release.title);
            if (startButton) startButton.hidden = false;
            if (notice) showDialog();
        } catch (error) { say('版本检查暂时不可用：' + error.message); }
    };

    const showDialog = () => {
        dialog = document.createElement('dialog');
        dialog.className = 'update-dialog';
        dialog.innerHTML = '<h2>发现 FeiFeiCMS 新版本</h2><p data-update-dialog-message></p><p>确认后将下载 GitHub 安装包，先备份程序与数据库，再安装更新。更新期间请勿关闭服务器。</p><div class="update-actions"><button type="button" data-update-cancel>稍后更新</button><button type="button" data-update-confirm>确认更新</button></div>';
        document.body.appendChild(dialog);
        dialog.querySelector('[data-update-dialog-message]').textContent = release.title;
        dialog.querySelector('[data-update-cancel]').addEventListener('click', () => dialog.close());
        dialog.querySelector('[data-update-confirm]').addEventListener('click', start);
        dialog.showModal();
    };

    const start = async () => {
        if (!release || !release.available) return;
        startButton && (startButton.disabled = true);
        if (dialog) dialog.querySelector('[data-update-confirm]').disabled = true;
        say('正在启动后台更新…');
        try {
            const body = new URLSearchParams({ _token: token, tag: release.tag });
            const response = await fetch('/admin/tools/version/start', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
            const data = await response.json();
            if (!data.ok) throw new Error(data.error || '无法启动更新');
            say(data.job.message);
            watch();
        } catch (error) { say('更新未启动：' + error.message); if (startButton) startButton.disabled = false; }
    };

    let timer = null;
    const watch = () => {
        if (timer) return;
        timer = setInterval(async () => {
            try {
                const response = await fetch('/admin/tools/version/status', { credentials: 'same-origin', cache: 'no-store' });
                const data = await response.json();
                if (data.job) say(data.job.message);
                if (data.job && ['succeeded', 'failed'].includes(data.job.status)) {
                    clearInterval(timer); timer = null;
                    if (data.job.status === 'succeeded') {
                        if (dialog) dialog.querySelector('[data-update-confirm]').textContent = '更新完成';
                        if (startButton) startButton.hidden = true;
                        window.setTimeout(() => window.location.assign('/admin.php'), 2500);
                    } else if (startButton) startButton.disabled = false;
                }
            } catch (_) { /* next poll retries */ }
        }, 2500);
    };
    startButton?.addEventListener('click', start);
    check();
})();

(() => {
    const postForm = async (url, body) => {
        const response = await fetch(url, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({ ok: false, message: `请求失败（HTTP ${response.status}）` }));
        if (!response.ok || payload.ok === false) throw new Error(payload.message || `请求失败（HTTP ${response.status}）`);
        return payload;
    };
    const addLog = (form, message, failed = false) => {
        const log = form.parentElement?.querySelector('[data-tool-log]') || document.querySelector('[data-tool-log]');
        if (!log) return;
        const row = document.createElement('p');
        row.className = failed ? 'failed' : '';
        row.textContent = `${new Date().toLocaleTimeString()} ${message}`;
        log.prepend(row);
        while (log.children.length > 100) log.lastElementChild?.remove();
    };

    document.querySelectorAll('[data-vod-list-form]').forEach((form) => {
        const token = form.querySelector('input[name="_token"]')?.value || '';
        const actionInput = form.querySelector('input[name="action"]');
        const status = form.querySelector('[data-vod-batch-status]');
        const checked = () => Array.from(form.querySelectorAll('input[name="ids[]"]:checked'));
        const setStatus = (message, failed = false) => {
            if (!status) return;
            status.textContent = message;
            status.classList.toggle('failed', failed);
        };
        const requireSelection = () => {
            const boxes = checked();
            if (boxes.length === 0) setStatus('请先选择视频', true);
            return boxes;
        };

        form.querySelectorAll('[data-vod-submit]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (requireSelection().length === 0 || !(actionInput instanceof HTMLInputElement)) return;
                if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
                actionInput.value = button.dataset.vodSubmit || '';
                form.requestSubmit();
            });
        });

        form.querySelectorAll('[data-vod-panel-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const name = button.dataset.vodPanelToggle || '';
                form.querySelectorAll('[data-vod-panel]').forEach((panel) => {
                    panel.hidden = panel.dataset.vodPanel !== name || !panel.hidden;
                });
            });
        });

        form.querySelectorAll('[data-vod-open]').forEach((button) => {
            button.addEventListener('click', () => {
                const boxes = requireSelection();
                if (boxes.length === 0) return;
                const mode = button.dataset.vodOpen || 'edit';
                boxes.forEach((box) => {
                    const id = box.dataset.vodId || box.value;
                    const title = box.dataset.vodTitle || '';
                    const url = mode === 'query' ? `/admin/vod?wd=${encodeURIComponent(title.slice(0, 40))}` : `/admin/vod/${encodeURIComponent(id)}/edit`;
                    window.open(url, '_blank', 'noopener');
                });
                setStatus(`已打开 ${boxes.length} 个窗口`);
            });
        });

        form.querySelector('[data-vod-page-jump]')?.addEventListener('click', () => {
            const input = form.querySelector('[data-vod-page-input]');
            if (!(input instanceof HTMLInputElement)) return;
            const max = Math.max(1, Number(input.max) || Number(form.querySelector('[data-vod-page-jump]')?.dataset.pageMax) || 1);
            const page = Math.max(1, Math.min(max, Number(input.value) || 1));
            const url = new URL(window.location.href);
            url.searchParams.set('page', String(page));
            window.location.assign(url.toString());
        });

        form.querySelectorAll('[data-vod-quick]').forEach((button) => {
            button.addEventListener('click', async (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
                const id = button.dataset.vodId || '';
                const body = new FormData();
                body.set('_token', token);
                body.set('action', button.dataset.vodQuick || '');
                if (button instanceof HTMLButtonElement) button.disabled = true;
                try {
                    const payload = await postForm(`/admin/vod/${encodeURIComponent(id)}/quick`, body);
                    setStatus(payload.message || '操作完成');
                    window.location.reload();
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '操作失败', true);
                    if (button instanceof HTMLButtonElement) button.disabled = false;
                }
            });
        });

        form.querySelectorAll('[data-vod-stars]').forEach((stars) => {
            stars.querySelectorAll('[data-vod-weight]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const body = new FormData();
                    body.set('_token', token);
                    body.set('weight', button.dataset.vodWeight || '0');
                    try {
                        const payload = await postForm(`/admin/vod/${encodeURIComponent(stars.dataset.vodStars || '')}/weight`, body);
                        const weight = Number(payload.data?.weight) || 0;
                        stars.dataset.current = String(weight);
                        stars.querySelectorAll('[data-vod-weight]').forEach((image) => {
                            const imageWeight = Number(image.dataset.vodWeight) || 0;
                            if (imageWeight === 0) return;
                            image.src = `/static/admin-legacy/${imageWeight <= weight ? 'star1.gif' : 'star0.gif'}`;
                        });
                        setStatus(payload.message || '权重已更新');
                    } catch (error) {
                        setStatus(error instanceof Error ? error.message : '权重更新失败', true);
                    }
                });
            });
        });

        const collectScenario = async (id) => {
            const body = new FormData();
            body.set('_token', token);
            return postForm(`/admin/vod/${encodeURIComponent(id)}/scenarios/collect`, body);
        };
        form.querySelectorAll('[data-vod-scenario]').forEach((button) => {
            button.addEventListener('click', async () => {
                if (button instanceof HTMLButtonElement) button.disabled = true;
                try {
                    const payload = await collectScenario(button.dataset.vodScenario || '');
                    button.textContent = '剧情刷新';
                    setStatus(payload.message || '剧情处理完成');
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '剧情处理失败', true);
                } finally {
                    if (button instanceof HTMLButtonElement) button.disabled = false;
                }
            });
        });
        form.querySelector('[data-vod-scenario-batch]')?.addEventListener('click', async (event) => {
            const button = event.currentTarget;
            const boxes = requireSelection();
            if (boxes.length === 0 || !(button instanceof HTMLButtonElement)) return;
            button.disabled = true;
            let success = 0;
            let failed = 0;
            for (const box of boxes) {
                try { await collectScenario(box.dataset.vodId || box.value); success++; }
                catch { failed++; }
                setStatus(`剧情采集进度 ${success + failed}/${boxes.length}，成功 ${success}，失败 ${failed}`, failed > 0);
            }
            button.disabled = false;
        });

        form.querySelectorAll('[data-vod-comment]').forEach((button) => {
            button.addEventListener('click', async () => {
                const body = new FormData();
                body.set('_token', token);
                body.set('media_id', button.dataset.vodComment || '0');
                if (button instanceof HTMLButtonElement) button.disabled = true;
                try {
                    const payload = await postForm('/admin/vod-tools/douban-comments/next', body);
                    setStatus(payload.message || '评论采集完成', Boolean(payload.data?.failed));
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '评论采集失败', true);
                } finally {
                    if (button instanceof HTMLButtonElement) button.disabled = false;
                }
            });
        });
    });

    document.querySelectorAll('[data-vod-tool-batch]').forEach((form) => {
        const endpoint = form.dataset.endpoint || '';
        const storageKey = form.dataset.storageKey || endpoint;
        const start = form.querySelector('[data-tool-start]');
        const pause = form.querySelector('[data-tool-pause]');
        const reset = form.querySelector('[data-tool-reset]');
        const state = form.querySelector('[data-tool-state]');
        let running = false;
        const setRunning = (value) => {
            running = value;
            if (start instanceof HTMLButtonElement) start.disabled = value;
            if (pause instanceof HTMLButtonElement) pause.disabled = !value;
        };
        const run = async () => {
            if (!running) return;
            const body = new FormData(form);
            body.set('cursor', localStorage.getItem(storageKey) || '0');
            try {
                const payload = await postForm(endpoint, body);
                const data = payload.data || {};
                if (data.cursor !== undefined) localStorage.setItem(storageKey, String(data.cursor));
                if (state) state.textContent = payload.message || '已处理';
                addLog(form, payload.message || '已处理', Boolean(data.failed));
                if (data.done) { setRunning(false); return; }
                const delay = Math.max(500, Number(new FormData(form).get('delay')) || 2000);
                window.setTimeout(run, delay);
            } catch (error) {
                const message = error instanceof Error ? error.message : '执行失败';
                if (state) state.textContent = message;
                addLog(form, message, true);
                setRunning(false);
            }
        };
        start?.addEventListener('click', () => { setRunning(true); if (state) state.textContent = '正在执行…'; run(); });
        pause?.addEventListener('click', () => { setRunning(false); if (state) state.textContent = '已暂停，可继续执行'; });
        reset?.addEventListener('click', () => { setRunning(false); localStorage.removeItem(storageKey); if (state) state.textContent = '进度已重置'; });
        const saved = localStorage.getItem(storageKey);
        if (saved && state) state.textContent = `上次处理到视频 #${saved}`;
    });

    document.querySelectorAll('[data-tool-retry]').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!(button instanceof HTMLButtonElement)) return;
            const body = new FormData();
            body.set('_token', button.dataset.token || '');
            body.set('media_id', button.dataset.mediaId || '0');
            body.set('scope', 'missing');
            button.disabled = true;
            try {
                const payload = await postForm(button.dataset.endpoint || '', body);
                button.textContent = payload.data?.failed ? '仍失败' : '已完成';
            } catch (error) {
                button.textContent = error instanceof Error ? error.message : '失败';
            }
        });
    });

    document.querySelectorAll('[data-scenario-tool]').forEach((form) => {
        const start = form.querySelector('[data-tool-start]');
        const pause = form.querySelector('[data-tool-pause]');
        const reset = form.querySelector('[data-tool-reset]');
        const state = form.querySelector('[data-tool-state]');
        const page = form.querySelector('input[name="page"]');
        let running = false;
        const setRunning = (value) => {
            running = value;
            if (start instanceof HTMLButtonElement) start.disabled = value;
            if (pause instanceof HTMLButtonElement) pause.disabled = !value;
        };
        const run = async () => {
            if (!running) return;
            try {
                const payload = await postForm(form.dataset.endpoint || '', new FormData(form));
                const data = payload.data || {};
                if (state) state.textContent = payload.message || '已处理';
                addLog(form, payload.message || '已处理');
                if (!data.next_page || new FormData(form).get('ids')) { setRunning(false); return; }
                if (page instanceof HTMLInputElement) page.value = String(data.next_page);
                const delay = Math.max(500, Number(new FormData(form).get('delay')) || 1500);
                window.setTimeout(run, delay);
            } catch (error) {
                const message = error instanceof Error ? error.message : '执行失败';
                if (state) state.textContent = message;
                addLog(form, message, true);
                setRunning(false);
            }
        };
        start?.addEventListener('click', () => { setRunning(true); if (state) state.textContent = '正在采集…'; run(); });
        pause?.addEventListener('click', () => { setRunning(false); if (state) state.textContent = '已暂停'; });
        reset?.addEventListener('click', () => { setRunning(false); if (page instanceof HTMLInputElement) page.value = '1'; if (state) state.textContent = '页码已重置'; });
    });

    document.querySelectorAll('[data-scenario-batch-form]').forEach((form) => {
        const body = form.querySelector('[data-scenario-rows]');
        const payload = form.querySelector('[data-scenario-rows-json]');
        const status = form.querySelector('[data-scenario-batch-status]');
        let newRowSequence = 0;
        const rows = () => Array.from(form.querySelectorAll('[data-scenario-row]'));
        const field = (row, name) => row.querySelector(`[data-scenario-field="${name}"]`);
        const nextEpisode = () => Math.max(0, ...rows().map((row) => Number(field(row, 'episode_no')?.value || 0))) + 1;
        const setStatus = (message) => { if (status) status.textContent = message; };
        const syncDeletedState = (row) => {
            const deleted = field(row, 'delete');
            row.classList.toggle('scenario-row-deleted', deleted instanceof HTMLInputElement && deleted.checked);
        };
        const addRow = () => {
            if (!body) return;
            const episode = nextEpisode();
            const key = `new-${Date.now()}-${newRowSequence++}`;
            const row = document.createElement('tr');
            row.setAttribute('data-scenario-row', '');
            row.innerHTML = `<td><span>新</span><input type="hidden" name="rows[${key}][id]" value="0" data-scenario-field="id"></td><td><input class="scenario-episode-input" type="number" min="1" max="100000" name="rows[${key}][episode_no]" value="${episode}" data-scenario-field="episode_no" required></td><td><input class="scenario-title-input" type="text" maxlength="255" name="rows[${key}][title]" value="第${episode}集" data-scenario-field="title" placeholder="留空自动生成"></td><td><textarea class="scenario-inline-content" name="rows[${key}][content]" data-scenario-field="content" required></textarea></td><td><input class="scenario-source-input" type="text" maxlength="500" name="rows[${key}][source_ref]" value="" data-scenario-field="source_ref"></td><td><input class="scenario-sort-input" type="number" name="rows[${key}][sort_order]" value="0" data-scenario-field="sort_order"></td><td><select name="rows[${key}][status]" data-scenario-field="status"><option value="published">显示</option><option value="draft">隐藏</option></select></td><td><label class="scenario-delete-choice"><input type="checkbox" name="rows[${key}][delete]" value="1" data-scenario-field="delete"><span>删除</span></label></td>`;
            body.appendChild(row);
            field(row, 'content')?.focus();
            setStatus(`已添加第${episode}集，保存后入库`);
        };
        form.querySelector('[data-scenario-add-row]')?.addEventListener('click', addRow);
        form.querySelector('[data-scenario-publish-all]')?.addEventListener('click', () => {
            rows().forEach((row) => { const control = field(row, 'status'); if (control instanceof HTMLSelectElement) control.value = 'published'; });
            setStatus('已将全部剧情设为显示，请保存');
        });
        form.querySelector('[data-scenario-draft-all]')?.addEventListener('click', () => {
            rows().forEach((row) => { const control = field(row, 'status'); if (control instanceof HTMLSelectElement) control.value = 'draft'; });
            setStatus('已将全部剧情设为隐藏，请保存');
        });
        form.addEventListener('change', (event) => {
            const target = event.target instanceof Element ? event.target.closest('[data-scenario-field="delete"]') : null;
            if (target) syncDeletedState(target.closest('[data-scenario-row]'));
        });
        form.addEventListener('submit', (event) => {
            const serialized = rows().map((row) => ({
                id: Number(field(row, 'id')?.value || 0),
                episode_no: Number(field(row, 'episode_no')?.value || 1),
                title: field(row, 'title')?.value || '',
                content: field(row, 'content')?.value || '',
                source_ref: field(row, 'source_ref')?.value || '',
                sort_order: Number(field(row, 'sort_order')?.value || 0),
                status: field(row, 'status')?.value || 'draft',
                delete: Boolean(field(row, 'delete')?.checked),
            }));
            if (serialized.some((row) => row.delete) && !window.confirm('已勾选的分集将被删除，确定保存？')) {
                event.preventDefault();
                return;
            }
            if (payload instanceof HTMLInputElement) payload.value = JSON.stringify(serialized);
            setStatus('正在保存全部剧情…');
        });
        rows().forEach(syncDeletedState);
    });

    document.querySelectorAll('[data-collection-resource-type]').forEach((group) => {
        const form = group.closest('form');
        if (!(form instanceof HTMLFormElement)) return;
        const sync = () => {
            const selected = form.querySelector('input[name="resource_type"]:checked');
            const scenario = selected instanceof HTMLInputElement && selected.value === 'scenario';
            form.querySelectorAll('[data-scenario-source-row]').forEach((row) => { row.hidden = !scenario; });
            form.querySelectorAll('[data-video-source-row]').forEach((row) => { row.hidden = scenario; });
        };
        group.querySelectorAll('input[name="resource_type"]').forEach((radio) => radio.addEventListener('change', sync));
        sync();
    });
})();
