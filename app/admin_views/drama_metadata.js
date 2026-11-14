(() => {
    const form = document.querySelector('[data-drama-batch]');
    if (!form) return;

    const start = form.querySelector('[data-batch-start]');
    const pause = form.querySelector('[data-batch-pause]');
    const reset = form.querySelector('[data-batch-reset]');
    const state = form.querySelector('[data-batch-state]');
    const log = document.querySelector('[data-batch-results]');
    const pickAll = form.querySelector('[data-batch-check]');
    const items = [...form.querySelectorAll('input[name="selected[]"]')];
    const options = [...form.querySelectorAll('input:not([type="hidden"]), select')];
    const states = {processed: '已处理', review: '待核对', empty: '暂无资料', failed: '失败'};
    let running = false;
    let busy = false;

    const parameters = () => {
        const data = new FormData(form);
        if (data.get('mode') === 'selected') {
            const ids = data.getAll('selected[]').sort((a, b) => a.localeCompare(b));
            data.set('selected_ids', ids.join(','));
        }
        data.delete('selected[]');
        data.delete('_token');
        return data;
    };
    const keyFor = (data) => 'feifei:drama-collection:' + form.dataset.owner + ':' + new URLSearchParams(data).toString();
    const blank = () => ({source: 0, cursor: 0, upper: 0, done: false, tally: {}});
    const load = (key) => {
        try {
            const saved = JSON.parse(localStorage.getItem(key) || 'null');
            return saved && typeof saved.tally === 'object' && saved.tally ? saved : blank();
        } catch {
            return blank();
        }
    };
    const save = (key, progress) => {
        try { localStorage.setItem(key, JSON.stringify(progress)); } catch {}
    };
    const summary = (progress) => Object.entries(states)
        .map(([key, label]) => label + ' ' + (progress.tally[key] || 0)).join(' · ');
    const syncButtons = () => {
        start.disabled = running || busy;
        pause.disabled = !running;
        reset.disabled = running || busy;
        options.forEach((input) => { input.disabled = running || busy; });
    };
    const showSaved = () => {
        const saved = load(keyFor(parameters()));
        if (saved.done) {
            state.textContent = '此批次已完成。' + summary(saved);
            start.textContent = '重新采集';
        } else if (saved.cursor || saved.source) {
            state.textContent = '可继续上次批次。' + summary(saved);
            start.textContent = '继续采集';
        } else {
            state.textContent = '尚未开始';
            start.textContent = '一键批量采集';
        }
    };
    const addLog = (message, row) => {
        const line = document.createElement('li');
        line.textContent = message;
        if (row && (row.state === 'review' || row.state === 'failed') && row.cursor > 0) {
            const link = document.createElement('a');
            link.href = '/admin/vod-tools/drama-metadata?media_id=' + encodeURIComponent(row.cursor);
            link.textContent = '打开影片';
            line.append('　', link);
        }
        log.prepend(line);
        while (log.children.length > 100) log.lastElementChild.remove();
    };
    const collect = async (data, key, progress) => {
        const sources = data.get('provider') === 'both' ? ['tvzn', 'tmdb'] : [data.get('provider')];
        busy = true;
        syncButtons();
        try {
            while (running && progress.source < sources.length) {
                const body = new URLSearchParams(data);
                body.set('_token', form.querySelector('input[name="_token"]').value);
                body.set('provider', sources[progress.source]);
                body.set('cursor', String(progress.cursor));
                body.set('upper_id', String(progress.upper));
                state.textContent = '正在处理 ' + sources[progress.source].toUpperCase() + '。' + summary(progress);
                const response = await fetch(form.dataset.endpoint, {method: 'POST', body, credentials: 'same-origin'});
                const payload = await response.json().catch(() => null);
                if (!response.ok || !payload?.ok) throw new Error(payload?.message || '采集请求失败，请检查登录状态后继续。');
                const result = payload.data;
                progress.upper = result.upper_id;
                if (result.done) {
                    ++progress.source;
                    progress.cursor = 0;
                    progress.done = progress.source === sources.length;
                } else {
                    progress.cursor = result.cursor;
                    progress.tally[result.state] = (progress.tally[result.state] || 0) + 1;
                    addLog(payload.message, result);
                }
                save(key, progress);
                if (running && !progress.done && !result.done) await new Promise((resolve) => setTimeout(resolve, 800));
            }
            state.textContent = (progress.done ? '批次完成。' : '已暂停，可继续。') + summary(progress);
            start.textContent = progress.done ? '重新采集' : '继续采集';
        } catch (error) {
            state.textContent = error instanceof Error ? error.message : '采集请求失败';
            addLog(state.textContent);
            start.textContent = '继续采集';
        } finally {
            running = busy = false;
            syncButtons();
            if (!progress.done) reset.disabled = false;
        }
    };
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (running || busy) return;
        const data = parameters();
        if (data.get('mode') === 'selected' && !data.get('selected_ids')) {
            state.textContent = '请勾选要采集的影片';
            return;
        }
        if (!data.get('plots') && !data.get('cast')) {
            state.textContent = '请选择要采集的内容';
            return;
        }
        const key = keyFor(data);
        let progress = load(key);
        if (progress.done) progress = blank();
        if (!progress.cursor && !progress.source) log.replaceChildren();
        running = true;
        collect(data, key, progress);
    });
    pause.addEventListener('click', () => {
        running = false;
        syncButtons();
        state.textContent = '等待当前影片处理完毕…';
    });
    reset.addEventListener('click', () => {
        save(keyFor(parameters()), blank());
        log.replaceChildren();
        showSaved();
    });
    pickAll?.addEventListener('change', () => {
        items.forEach((input) => { input.checked = pickAll.checked; });
        if (pickAll.checked) form.querySelector('[name="mode"]').value = 'selected';
        showSaved();
    });
    const syncSelection = () => {
        if (!pickAll) return;
        pickAll.checked = items.length > 0 && items.every((input) => input.checked);
        pickAll.indeterminate = !pickAll.checked && items.some((input) => input.checked);
    };
    options.forEach((input) => input.addEventListener('change', () => {
        if (input.name === 'selected[]' && input.checked) form.querySelector('[name="mode"]').value = 'selected';
        syncSelection();
        showSaved();
    }));
    showSaved();
})();
