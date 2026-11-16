(() => {
    const field = (name) => document.getElementById('collection-' + name);
    const form = field('step');
    const page = form.elements.namedItem('page');
    const counts = ['processed', 'created', 'updated', 'skipped', 'errors'];
    const labels = ['处理', '新增', field('updated-label').textContent, '跳过', '错误'];
    const statusUrl = new URL(location.href);
    statusUrl.search = '';
    if (form.dataset.auto === '1') history.replaceState(null, '', statusUrl);
    statusUrl.searchParams.set('status', '1');
    let state = form.dataset.state;
    let lastPage = Number(form.dataset.last);
    let active = false;
    let inFlight = false;
    let timer;

    function buttons() {
        form.hidden = state !== 'queued' && state !== 'running';
        field('start').parentElement.hidden = active;
        field('start').disabled = inFlight;
        field('pause').parentElement.hidden = !active;
        field('retry').hidden = state !== 'failed';
    }

    function render(result) {
        state = result.state;
        page.value = result.page;
        field('pages').textContent = result.donePages + (result.pageTotal ? ' / ' + result.pageTotal : '');
        const bar = field('bar');
        bar.hidden = !result.pageTotal;
        if (result.pageTotal) {
            bar.max = result.pageTotal;
            bar.value = result.donePages;
        }
        const changes = counts.map((key, index) => {
            const cell = field(key);
            const change = result[key] - Number(cell.textContent);
            cell.textContent = result[key];
            return labels[index] + ' ' + change;
        });
        if (result.lastPage > lastPage) {
            const log = field('log');
            const line = document.createElement('li');
            line.textContent = '第 ' + result.lastPage + ' 页完成 · ' + changes.join(' · ');
            log.prepend(line);
            if (log.children.length > 50) log.lastElementChild.remove();
        }
        lastPage = result.lastPage;
        field('message').textContent = result.message;
        field('message').hidden = !result.message;
        const messages = {
            queued: '等待采集第 ' + result.page + ' 页',
            running: '正在采集第 ' + result.page + ' 页',
            completed: '采集完成',
            completed_with_errors: '采集完成，有错误',
            failed: '第 ' + result.page + ' 页失败，已保留断点',
        };
        field('status').textContent = messages[state] || '任务已停止';
    }

    async function next() {
        if (!active || inFlight) return;
        inFlight = true;
        buttons();
        const polling = state === 'running';
        const requestedPage = Number(page.value);
        field('status').textContent = '正在采集第 ' + requestedPage + ' 页…';
        try {
            const response = polling ? await fetch(statusUrl, {cache: 'no-store'}) : await fetch(form.action, {
                method: 'POST',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
                body: new URLSearchParams(new FormData(form)),
            });
            if (!response.ok) throw new Error();
            const result = await response.json();
            render(result);
            if (state === 'queued' && result.interrupted && result.page === requestedPage) {
                active = false;
                field('status').textContent = '采集未能启动，请检查采集设置后继续';
            } else if (state !== 'queued' && state !== 'running') {
                active = false;
            } else if (!active) {
                field('status').textContent = '已暂停 · 下次从第 ' + page.value + ' 页继续';
            }
            if (active) timer = setTimeout(next, state === 'running' ? 1200 : Math.max(250, Number(form.dataset.delay)));
        } catch {
            active = false;
            field('status').textContent = '请求中断，请刷新进度页后继续';
        } finally {
            inFlight = false;
            buttons();
        }
    }

    function start() {
        active = true;
        next();
    }
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        start();
    });
    field('pause').addEventListener('click', () => {
        active = false;
        clearTimeout(timer);
        field('status').textContent = inFlight ? '暂停中 · 等待本页结束' : '已暂停 · 下次从第 ' + page.value + ' 页继续';
        buttons();
    });
    buttons();
    if (form.dataset.auto === '1' && (state === 'queued' || state === 'running')) start();
})();
