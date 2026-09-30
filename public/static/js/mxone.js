(function () {
    'use strict';

    var historyKey = 'ffcms_play_history';
    var historyLimit = Math.max(0, parseInt(window.mxoneUiConfig && window.mxoneUiConfig.recordLimit, 10) || 0);
    var themeKey = 'mxone_theme';
    var historyIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></svg>';
    var sunIcon = '<svg class="mx-icon-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.9 4.9 1.4 1.4"/><path d="m17.7 17.7 1.4 1.4"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m4.9 19.1 1.4-1.4"/><path d="m17.7 6.3 1.4-1.4"/></svg>';
    var moonIcon = '<svg class="mx-icon-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/></svg>';

    function ensureHeaderActions() {
        var head = document.querySelector('.mx-head-inner');
        if (!head) {
            return null;
        }
        var actions = head.querySelector('.mx-head-actions');
        if (!actions) {
            actions = document.createElement('div');
            actions.className = 'mx-head-actions';
            head.appendChild(actions);
        }
        return actions;
    }

    function historyIdentity(item) {
        var value = String(item && (item.historyId || item.id) || '').trim();
        // Old records used "vod-{id}-ep-{pid}" or "vod-{id}-{sid}-{pid}".
        // Collapse both forms to one stable key per video.
        var matched = value.match(/^vod-(\d+)(?:-(?:ep-)?\d+)?(?:-\d+)?$/);
        return matched ? 'vod-' + matched[1] : value;
    }

    function normalizeHistoryItem(item) {
        item = item || {};
        var name = String(item.name || '').trim();
        var episode = String(item.episode || '').trim();
        // Migrate the former "片名 第05集 / 5" display into two columns.
        var titleWithEpisode = name.match(/^(.*?)\s+(第\s*\d+\s*(?:集|期)|正片)$/);
        if (titleWithEpisode) {
            name = titleWithEpisode[1].trim();
            if (!episode || /^\d+$/.test(episode)) {
                episode = titleWithEpisode[2].replace(/\s+/g, '');
            }
        }
        if (/^\d+$/.test(episode)) {
            episode = '第' + episode + '集';
        }
        return {
            id: historyIdentity(item),
            historyId: historyIdentity(item),
            url: String(item.url || ''),
            name: name,
            episode: episode,
            time: Number(item.time) || 0
        };
    }

    function normalizeHistory(items) {
        var seen = {};
        return (Array.isArray(items) ? items : []).map(normalizeHistoryItem).filter(function (item) {
            if (!item.id || seen[item.id]) {
                return false;
            }
            seen[item.id] = true;
            return true;
        });
    }

    function readHistory() {
        try {
            return historyLimit > 0 ? normalizeHistory(JSON.parse(localStorage.getItem(historyKey) || '[]')).slice(0, historyLimit) : [];
        } catch (e) {
            return [];
        }
    }

    function writeHistory(items) {
        if (historyLimit < 1) {
            localStorage.removeItem(historyKey);
            return;
        }
        localStorage.setItem(historyKey, JSON.stringify(normalizeHistory(items).slice(0, historyLimit)));
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function historyHtml(items) {
        if (!items.length) {
            return '<p class="empty-note">暂无播放记录</p>';
        }
        return items.map(function (item) {
            return '<a class="history-item" href="' + escapeHtml(item.url) + '"><strong>' + escapeHtml(item.name) + '</strong><span>' + escapeHtml(item.episode || '') + '</span></a>';
        }).join('');
    }

    function mountHeaderHistory() {
        var actions = ensureHeaderActions();
        if (!actions || historyLimit < 1 || actions.querySelector('.mx-history-menu')) {
            return;
        }

        var menu = document.createElement('div');
        menu.className = 'mx-history-menu';
        menu.innerHTML = '<button type="button" class="mx-icon-btn mx-history-toggle" title="观看记录" aria-label="观看记录">' + historyIcon + '</button><div class="mx-history-pop"><div class="mx-history-top"><strong>播放记录</strong><button type="button" data-history-clear>清空</button></div><div class="mx-history-list"></div></div>';
        actions.appendChild(menu);

        var list = menu.querySelector('.mx-history-list');
        var refresh = function () {
            var items = readHistory();
            // Persist the migration too, so records created by the old player
            // no longer reappear once per episode on later visits.
            writeHistory(items);
            list.innerHTML = historyHtml(items);
        };
        refresh();

        var toggle = menu.querySelector('.mx-history-toggle');
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var opened = menu.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!menu.contains(event.target)) {
                menu.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        menu.querySelector('[data-history-clear]').addEventListener('click', function () {
            writeHistory([]);
            refresh();
        });
    }

    function mountHeaderSearch() {
        var forms = document.querySelectorAll('form[data-search-url]');
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function (event) {
                var input = form.querySelector('input[name="wd"]');
                var keyword = input ? String(input.value || '').trim() : '';
                if (!keyword) {
                    event.preventDefault();
                    if (input) { input.focus(); }
                    return;
                }
                var searchUrl = form.getAttribute('data-search-url') || '';
                if (searchUrl.indexOf('FFWD') !== -1) {
                    event.preventDefault();
                    window.location.href = searchUrl.replace('FFWD', encodeURIComponent(keyword));
                }
            });
        });
    }

    function mountFloatingActions() {
        var backToTop = document.querySelector('[data-back-to-top]');
        if (!backToTop) {
            return;
        }
        var refresh = function () {
            backToTop.classList.toggle('is-visible', window.pageYOffset > 320);
        };
        refresh();
        window.addEventListener('scroll', refresh, {passive: true});
        backToTop.addEventListener('click', function (event) {
            event.preventDefault();
            try {
                window.scrollTo({top: 0, behavior: 'smooth'});
            } catch (error) {
                window.scrollTo(0, 0);
            }
        });
    }

    function mountPosterFallbacks() {
        Array.prototype.forEach.call(document.querySelectorAll('img[data-fallback-poster]'), function (image) {
            image.addEventListener('error', function () {
                if (image.dataset.fallbackApplied === '1') { return; }
                image.dataset.fallbackApplied = '1';
                image.src = image.getAttribute('data-fallback-poster') || '/static/images/no-poster.svg';
            });
        });
    }

    function mountMobileHeader() {
        var button = document.querySelector('[data-mobile-more]');
        if (!button) {
            return;
        }
        var more = button.closest('.mx-mobile-more');
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var opened = more.classList.toggle('is-open');
            button.setAttribute('aria-expanded', opened ? 'true' : 'false');
        });
        document.addEventListener('click', function (event) {
            if (!more.contains(event.target)) {
                more.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                more.classList.remove('is-open');
                button.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function mountScenarioPagination() {
        var catalog = document.querySelector('[data-scenario-ajax]');
        if (!catalog || !window.fetch) {
            return;
        }
        var loading = false;
        catalog.addEventListener('click', function (event) {
            var link = event.target.closest('.mx-scenario-range-tabs a');
            if (!link || link.classList.contains('active') || loading) {
                return;
            }
            event.preventDefault();
            var targetUrl = link.href;
            var ajaxUrl = targetUrl + (targetUrl.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
            loading = true;
            catalog.classList.add('is-loading');
            catalog.setAttribute('aria-busy', 'true');
            fetch(ajaxUrl, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('scenario page request failed');
                }
                return response.text();
            }).then(function (html) {
                var shell = document.createElement('div');
                shell.innerHTML = html;
                var nextTabs = shell.querySelector('.mx-scenario-range-tabs');
                var nextChapters = shell.querySelector('.mx-scenario-chapters');
                var currentTabs = catalog.querySelector('.mx-scenario-range-tabs');
                var currentChapters = catalog.querySelector('.mx-scenario-chapters');
                if (!nextChapters || !currentChapters) {
                    throw new Error('scenario page response invalid');
                }
                if (currentTabs && nextTabs) {
                    currentTabs.replaceWith(nextTabs);
                }
                currentChapters.replaceWith(nextChapters);
            }).catch(function () {
                window.location.href = targetUrl;
            }).finally(function () {
                loading = false;
                catalog.classList.remove('is-loading');
                catalog.removeAttribute('aria-busy');
            });
        });
    }

    function applyTheme(theme) {
        var normalized = theme === 'light' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', normalized);
        try {
            localStorage.setItem(themeKey, normalized);
        } catch (e) {}
        var button = document.querySelector('[data-theme-toggle]');
        if (button) {
            button.setAttribute('aria-label', normalized === 'light' ? '切换到夜间模式' : '切换到白天模式');
            button.setAttribute('title', normalized === 'light' ? '夜间模式' : '白天模式');
        }
    }

    function mountThemeToggle() {
        var actions = ensureHeaderActions();
        if (!actions || actions.querySelector('[data-theme-toggle]')) {
            return;
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'mx-icon-btn mx-theme-toggle';
        button.setAttribute('data-theme-toggle', '1');
        button.innerHTML = sunIcon + moonIcon;
        actions.appendChild(button);
        button.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            applyTheme(current === 'light' ? 'dark' : 'light');
        });
        applyTheme(document.documentElement.getAttribute('data-theme') || 'dark');
    }

    function currentRedirect() {
        return encodeURIComponent(window.location.pathname + window.location.search + window.location.hash);
    }

    function userRoute(name, fallback) {
        var routes = window.mxoneUserRoutes || {};
        return routes[name] || fallback;
    }

    function routeWithRedirect(name, fallback) {
        var url = userRoute(name, fallback);
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'redirect=' + currentRedirect();
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    }

    function authHtml(data) {
        var user = data && data.data ? data.data : data;
        var userIcon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
        if (user && (Number(user.logged) === 1 || Number(user.user_id) > 0)) {
            return '<a class="mx-auth-user" href="' + escapeHtml(userRoute('center', '/user/center')) + '" title="个人中心">' + userIcon + '<span>' + escapeHtml(user.user_name || '会员中心') + '</span></a>';
        }
        return '<a class="mx-auth-login" href="' + escapeHtml(routeWithRedirect('login', '/user/login')) + '" title="登录">' + userIcon + '<span>登录</span></a><span>/</span><a class="mx-auth-register" href="' + escapeHtml(routeWithRedirect('register', '/user/register')) + '">注册</a>';
    }

    function mountHeaderAuth() {
        var actions = ensureHeaderActions();
        if (!actions || actions.querySelector('.mx-auth-menu')) {
            return;
        }
        var box = document.createElement('div');
        box.className = 'mx-auth-menu';
        box.innerHTML = authHtml(null);
        actions.appendChild(box);

        if (!window.fetch) {
            return;
        }
        fetch(userRoute('info', '/user/info'), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            box.innerHTML = authHtml(data);
        }).catch(function () {
            box.innerHTML = authHtml(null);
        });
    }

    function vodCardHtml(item) {
        var pic = item.vod_pic || '/static/images/no-poster.svg';
        var note = item.vod_continu || item.vod_year || '';
        return '<a class="mx-card" href="' + escapeHtml(item.url || ('/vod/' + encodeURIComponent(item.vod_id))) + '">' +
            '<span class="mx-pic"><img src="' + escapeHtml(pic) + '" alt="' + escapeHtml(item.vod_name) + '" onerror="this.onerror=null;this.src=\'/static/images/no-poster.svg\';"><i>' + escapeHtml(note) + '</i></span>' +
            '<strong>' + escapeHtml(item.vod_name) + '</strong>' +
            '<small>' + escapeHtml(item.vod_actor || '主演暂无') + '</small>' +
            '</a>';
    }

    function renderSameActorList(box, items) {
        if (!items || !items.length) {
            box.innerHTML = '<p class="mx-same-empty">暂无同主演影片</p>';
            return;
        }
        box.innerHTML = items.map(vodCardHtml).join('');
    }

    function mountSameActor() {
        var section = document.querySelector('[data-same-actor]');
        if (!section || section.__sameActorReady) {
            return;
        }
        section.__sameActorReady = true;
        var vodId = section.getAttribute('data-vod-id');
        var list = section.querySelector('[data-same-actor-list]');
        var cache = {};
        var defaultActor = section.getAttribute('data-default-actor') || '';
        // 默认演员的影片由服务端首屏渲染，切回时直接恢复，避免重复请求。
        var defaultContent = list ? list.innerHTML : '';
        var activeActor = defaultActor;
        // FeiFei renders the initial same-actor cards server-side and uses its
        // native search route for actor links. Only enable the asynchronous
        // switcher when a compatible endpoint is explicitly configured.
        if (!section.hasAttribute('data-actor-api') || !vodId || !list) {
            return;
        }
        section.addEventListener('click', function (event) {
            var button = event.target.closest('[data-actor]');
            if (!button) {
                return;
            }
            event.preventDefault();
            var actor = button.getAttribute('data-actor') || '';
            if (!actor) {
                return;
            }
            if (actor === activeActor) {
                return;
            }
            activeActor = actor;
            section.querySelectorAll('[data-actor]').forEach(function (item) {
                item.classList.toggle('active', item === button);
            });
            if (actor === defaultActor) {
                list.innerHTML = defaultContent;
                return;
            }
            if (Object.prototype.hasOwnProperty.call(cache, actor)) {
                renderSameActorList(list, cache[actor]);
                return;
            }
            list.innerHTML = '<p class="mx-same-loading">正在加载 ' + escapeHtml(actor) + ' 的影片...</p>';
            var endpoint = section.getAttribute('data-actor-api') || '/index.php?s=vod-actor';
            fetch(endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?') + 'id=' + encodeURIComponent(vodId) + '&actor=' + encodeURIComponent(actor), {
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'}
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                var items = data && Number(data.status) === 1 ? (data.data || data.items || []) : [];
                cache[actor] = items;
                // 用户已切到别的演员时，不让较晚返回的旧请求覆盖当前列表。
                if (activeActor === actor) {
                    renderSameActorList(list, items);
                }
            }).catch(function () {
                if (activeActor === actor) {
                    list.innerHTML = '<p class="mx-same-empty">加载失败，请稍后重试</p>';
                }
            });
        });
    }

    function normalizeRating(value, allowZero) {
        var score = parseFloat(value);
        if (!isFinite(score)) {
            score = allowZero ? 0 : 8;
        }
        score = Math.max(allowZero ? 0 : 1, Math.min(10, score));
        return (Math.round(score * 10) / 10).toFixed(1);
    }

    function displayRating(value) {
        var score = parseFloat(value);
        if (!isFinite(score)) {
            return '0';
        }
        score = Math.round(score * 10) / 10;
        return Math.abs(score - Math.round(score)) < 0.0001 ? String(Math.round(score)) : score.toFixed(1);
    }

    function mountVodRating() {
        var box = document.querySelector('[data-vod-rating]');
        if (!box || box.__ratingReady) {
            return;
        }
        box.__ratingReady = true;

        var id = box.getAttribute('data-id') || '';
        var stars = box.querySelector('[data-rating-stars]');
        var fill = box.querySelector('[data-rating-fill]');
        var message = box.querySelector('[data-rating-message]');
        var scoreText = box.querySelector('[data-rating-score]');
        var countText = box.querySelector('[data-rating-count]');
        var userText = box.querySelector('[data-rating-user]');
        var savedScore = normalizeRating(box.getAttribute('data-user-score') || box.getAttribute('data-score') || '0', true);
        var userScore = normalizeRating(box.getAttribute('data-user-score') || '0', true);
        var currentMessage = Number(userScore) > 0 ? ('已评分：' + displayRating(userScore) + ' 分') : ('当前：' + displayRating(box.getAttribute('data-score') || savedScore) + ' 分');
        var saving = false;

        function setMessage(text, error) {
            if (!message) {
                return;
            }
            message.textContent = text;
            message.classList.toggle('error', !!error);
        }

        function setCurrentMessage(text, error) {
            currentMessage = text;
            setMessage(text, error);
        }

        function paint(value) {
            value = normalizeRating(value, true);
            if (fill) {
                fill.style.width = (Number(value) * 10) + '%';
            }
        }

        function scoreFromEvent(event) {
            var rect = stars.getBoundingClientRect();
            var left = Math.max(0, Math.min(rect.width, event.clientX - rect.left));
            return normalizeRating(Math.max(1, Math.ceil((left / rect.width) * 10)));
        }

        if (!stars) {
            return;
        }

        paint(savedScore);
        setMessage(currentMessage, false);

        stars.addEventListener('mousemove', function (event) {
            if (saving) {
                return;
            }
            var value = scoreFromEvent(event);
            paint(value);
            setMessage('评分：' + displayRating(value) + ' 分', false);
        });
        stars.addEventListener('mouseleave', function () {
            paint(savedScore);
            setMessage(currentMessage, false);
        });
        stars.addEventListener('click', function (event) {
            event.preventDefault();
            var value = scoreFromEvent(event);
            if (!id) {
                setMessage('视频 ID 不正确', true);
                return;
            }
            if (saving) {
                return;
            }
            saving = true;
            stars.classList.add('is-saving');
            setMessage('正在保存评分...', false);
            fetch('/vod/rate', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: 'id=' + encodeURIComponent(id) + '&score=' + encodeURIComponent(value) + '&_token=' + encodeURIComponent(csrfToken())
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (!data || Number(data.status) !== 1) {
                    throw new Error(data && data.message ? data.message : '评分保存失败');
                }
                if (scoreText) {
                    scoreText.textContent = data.score;
                }
                if (countText) {
                    countText.textContent = data.count + ' 人评分';
                }
                if (userText) {
                    userText.textContent = '我的评分：' + data.user_score;
                }
                box.setAttribute('data-score', data.score);
                box.setAttribute('data-count', data.count);
                box.setAttribute('data-user-score', data.user_score);
                savedScore = normalizeRating(data.user_score);
                userScore = savedScore;
                paint(savedScore);
                setCurrentMessage('已评分：' + displayRating(savedScore) + ' 分', false);
            }).catch(function (error) {
                paint(savedScore);
                setCurrentMessage(error.message || '评分保存失败', true);
            }).finally(function () {
                saving = false;
                stars.classList.remove('is-saving');
            });
        });
    }

    function mountVodUpdown() {
        function todayKey() {
            var now = new Date();
            return now.getFullYear() + String(now.getMonth() + 1).padStart(2, '0') + String(now.getDate()).padStart(2, '0');
        }

        function voteKey(vodId) {
            return 'ffcms_vod_updown_' + vodId + '_' + todayKey();
        }

        function applyVoteState(vodId, type) {
            document.querySelectorAll('[data-vod-updown][data-id="' + vodId + '"]').forEach(function (node) {
                node.classList.toggle('active', node.getAttribute('data-type') === type);
                node.disabled = !!type;
            });
        }

        document.querySelectorAll('[data-vod-updown]').forEach(function (button) {
            var vodId = button.getAttribute('data-id') || '';
            if (!vodId) {
                return;
            }
            try {
                var votedType = localStorage.getItem(voteKey(vodId)) || '';
                if (votedType === 'up' || votedType === 'down') {
                    applyVoteState(vodId, votedType);
                }
            } catch (e) {}
        });

        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-vod-updown]');
            if (!button || button.disabled) {
                return;
            }
            var vodId = button.getAttribute('data-id') || '';
            var type = button.getAttribute('data-type') === 'down' ? 'down' : 'up';
            if (!vodId || !window.fetch) {
                return;
            }
            var key = voteKey(vodId);
            try {
                var votedType = localStorage.getItem(key) || '';
                if (votedType === 'up' || votedType === 'down') {
                    applyVoteState(vodId, votedType);
                    return;
                }
            } catch (e) {}
            button.disabled = true;
            fetch('/vod/vote', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: 'id=' + encodeURIComponent(vodId) + '&type=' + encodeURIComponent(type) + '&_token=' + encodeURIComponent(csrfToken())
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                var payload = data && data.data && typeof data.data === 'object' ? data.data : (data || {});
                if (!data || Number(data.status) !== 1) {
                    if (payload.voted === 'up' || payload.voted === 'down') {
                        try {
                            localStorage.setItem(key, payload.voted);
                        } catch (e) {}
                        applyVoteState(vodId, payload.voted);
                    }
                    return;
                }
                document.querySelectorAll('[data-up-count]').forEach(function (node) {
                    node.textContent = String(payload.up || 0);
                });
                document.querySelectorAll('[data-down-count]').forEach(function (node) {
                    node.textContent = String(payload.down || 0);
                });
                document.querySelectorAll('[data-vod-updown]').forEach(function (node) {
                    node.classList.toggle('active', node.getAttribute('data-type') === type);
                });
                try {
                    localStorage.setItem(key, type);
                } catch (e) {}
                applyVoteState(vodId, type);
            }).finally(function () {
                if (!button.classList.contains('active')) {
                    button.disabled = false;
                }
            });
        });
    }

    function mountVodFavorite() {
        var button = document.querySelector('[data-vod-favorite]');
        if (!button || button.__favoriteReady || !window.fetch) {
            return;
        }
        button.__favoriteReady = true;
        var vodId = button.getAttribute('data-id') || '';
        var api = button.getAttribute('data-api') || '';
        var label = button.querySelector('span');
        if (!vodId || !api) {
            button.hidden = true;
            return;
        }

        function applyState(active) {
            active = !!active;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.setAttribute('aria-label', active ? '取消收藏' : '收藏影片');
            button.title = active ? '取消收藏' : '收藏影片';
            if (label) {
                label.textContent = active ? '已收藏' : '收藏';
            }
        }

        function goLogin() {
            var loginUrl = button.getAttribute('data-login-url') || (window.mxoneUserRoutes && window.mxoneUserRoutes.login) || '/user/login';
            var current = window.location.pathname + window.location.search + window.location.hash;
            window.location.href = loginUrl + (loginUrl.indexOf('?') >= 0 ? '&' : '?') + 'redirect=' + encodeURIComponent(current);
        }

        button.classList.add('is-loading');
        fetch(api + '&id=' + encodeURIComponent(vodId), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            if (data && Number(data.status) === 200) {
                applyState(data.data && data.data.active);
            } else if (data && Number(data.status) === 5001) {
                button.setAttribute('data-login-required', '1');
            }
        }).finally(function () {
            button.classList.remove('is-loading');
        });

        button.addEventListener('click', function () {
            if (button.classList.contains('is-loading')) {
                return;
            }
            if (button.getAttribute('data-login-required') === '1') {
                goLogin();
                return;
            }
            button.classList.add('is-loading');
            button.disabled = true;
            fetch(api, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: 'id=' + encodeURIComponent(vodId) + '&_token=' + encodeURIComponent(csrfToken())
            }).then(function (response) {
                return response.ok ? response.json() : null;
            }).then(function (data) {
                if (data && Number(data.status) === 5001) {
                    goLogin();
                    return;
                }
                if (!data || Number(data.status) !== 200) {
                    throw new Error(data && data.info ? data.info : '收藏操作失败');
                }
                applyState(data.data && data.data.active);
            }).catch(function (error) {
                button.title = error.message || '收藏操作失败';
            }).finally(function () {
                button.disabled = false;
                button.classList.remove('is-loading');
            });
        });
    }

	function mountDescriptionToggle() {
		document.querySelectorAll('[data-desc-toggle]').forEach(function (box) {
			if (box.__descToggleReady) { return; }
			box.__descToggleReady = true;
			var content = box.querySelector('[data-desc-content]');
			var button = box.querySelector('[data-desc-button]');
			var label = button && button.querySelector('span');
			if (!content || !button) { return; }
			function setExpanded(expanded) {
				box.classList.toggle('is-expanded', expanded);
				button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
				if (label) { label.textContent = expanded ? '收起剧情' : '展开剧情'; }
			}

			function measure() {
				var wasExpanded = box.classList.contains('is-expanded');
				box.classList.remove('is-expanded', 'is-collapsible');
				button.hidden = true;
				var fullHeight = content.scrollHeight;
				box.classList.add('is-collapsible');
				var collapsedHeight = content.clientHeight;
				if (fullHeight > collapsedHeight + 2) {
					button.hidden = false;
					setExpanded(wasExpanded);
				} else {
					box.classList.remove('is-collapsible');
					setExpanded(false);
				}
			}

			button.addEventListener('click', function () {
				var expanded = !box.classList.contains('is-expanded');
				setExpanded(expanded);
			});
			measure();
			window.addEventListener('resize', function () { window.clearTimeout(box.__descResizeTimer); box.__descResizeTimer = window.setTimeout(measure, 120); });
		});
	}

    function mountHomeHero() {
        var slider = document.querySelector('[data-hero-slider]');
        if (!slider || slider.__heroReady) {
            return;
        }
        slider.__heroReady = true;
        var slides = Array.prototype.slice.call(slider.querySelectorAll('.mx-hero-slide'));
        var dots = Array.prototype.slice.call(slider.querySelectorAll('[data-hero-dot]'));
        if (slides.length <= 1) {
            return;
        }
        var index = 0;
        var timer = null;

        function show(next) {
            index = (next + slides.length) % slides.length;
            slides.forEach(function (slide, slideIndex) {
                slide.classList.toggle('active', slideIndex === index);
            });
            dots.forEach(function (dot, dotIndex) {
                dot.classList.toggle('active', dotIndex === index);
            });
        }

        function start() {
            window.clearInterval(timer);
            timer = window.setInterval(function () {
                show(index + 1);
            }, 4200);
        }

        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                show(parseInt(dot.getAttribute('data-hero-dot') || '0', 10));
                start();
            });
        });
        slider.addEventListener('mouseenter', function () {
            window.clearInterval(timer);
        });
        slider.addEventListener('mouseleave', start);
        start();
    }

    function mountPlaySources() {
        var tabs = document.querySelector('.mx-play-source-tabs');
        if (!tabs || tabs.__ready) return;
        tabs.__ready = true;
        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-play-source]');
            if (!tab) return;
            event.preventDefault();
            var sid = tab.getAttribute('data-play-source');
            tabs.querySelectorAll('[data-play-source]').forEach(function (item) { item.classList.toggle('active', item === tab); });
            document.querySelectorAll('[data-play-source-panel]').forEach(function (panel) { panel.hidden = panel.getAttribute('data-play-source-panel') !== sid; });
        });
    }

	function mountEpisodeLists() {
		document.querySelectorAll('[data-episode-source]').forEach(function (source) {
			if (source.__episodeListReady) { return; }
			source.__episodeListReady = true;
			var list = source.querySelector('[data-episode-list]');
			var sortButton = source.querySelector('[data-episode-sort-toggle]');
			if (!list) { return; }
			var items = Array.prototype.slice.call(list.querySelectorAll('[data-episode-index]'));
			var limit = Math.max(0, parseInt(list.getAttribute('data-limit') || '0', 10) || 0);
			var order = 'asc';
			var expanded = false;
			var moreButton = document.createElement('button');
			moreButton.type = 'button';
			moreButton.className = 'mx-episode-more';
			moreButton.setAttribute('data-episode-more', '');

			function orderedItems() { return order === 'desc' ? items.slice().reverse() : items.slice(); }
			function syncSortButton() {
				if (!sortButton) { return; }
				var nextOrder = order === 'asc' ? 'desc' : 'asc';
				sortButton.setAttribute('data-order', order);
				sortButton.setAttribute('aria-label', nextOrder === 'desc' ? '切换为倒序' : '切换为正序');
				sortButton.textContent = nextOrder === 'desc' ? '倒序' : '正序';
			}
			function render() {
				var ordered = orderedItems();
				// 至少有两集中间内容可折叠时才显示“全部…”，避免只多一两集也折叠。
				var shouldCollapse = limit > 0 && ordered.length > limit + 2;
				if (moreButton.parentNode) { moreButton.parentNode.removeChild(moreButton); }
				ordered.forEach(function (item, index) {
					list.appendChild(item);
					item.hidden = shouldCollapse && !expanded && index >= limit && index !== ordered.length - 1;
				});
				if (shouldCollapse) {
					moreButton.textContent = expanded ? '收起' : '全部…';
					moreButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
					if (expanded) { list.appendChild(moreButton); }
					else { list.insertBefore(moreButton, ordered[ordered.length - 1]); }
				}
				syncSortButton();
			}
			if (sortButton) { sortButton.addEventListener('click', function () { order = order === 'asc' ? 'desc' : 'asc'; expanded = false; render(); }); }
			moreButton.addEventListener('click', function () { expanded = !expanded; render(); });
			if (items.length < 2 && sortButton) { sortButton.hidden = true; }
			render();
		});
	}

    function mountComments() {
        function loginRequired(form) {
            return form && form.getAttribute('data-comment-login-required') === '1' && form.getAttribute('data-user-logged') !== '1';
        }

        function refreshCommentVcode(form) {
            if (!form) { return; }
            var image = form.querySelector('[data-vcode-refresh]');
            var input = form.querySelector('input[name="forum_vcode"]');
            if (input) { input.value = ''; }
            if (image) {
                var url = image.getAttribute('data-vcode-url') || image.getAttribute('src') || '';
                image.setAttribute('src', url.replace(/([?&])_vcode_t=\d+/, '$1').replace(/[?&]$/, '') + (url.indexOf('?') === -1 ? '?' : '&') + '_vcode_t=' + Date.now());
            }
        }

        function openLoginModal() {
            var modal = document.querySelector('[data-login-modal]');
            if (!modal) {
                window.location.href = routeWithRedirect('login', '/user/login');
                return;
            }
            modal.hidden = false;
            document.body.classList.add('mx-modal-open');
        }

        document.addEventListener('click', function (event) {
            var vcodeImage = event.target.closest('[data-vcode-refresh]');
            if (vcodeImage) {
                event.preventDefault();
                refreshCommentVcode(vcodeImage.closest('.mx-comment-editor, .mx-reply-editor'));
                return;
            }
            var replyButton = event.target.closest('[data-reply-target]');
            if (!replyButton) {
                return;
            }
            event.preventDefault();
            var host = replyButton.closest('.mx-comment, .mx-reply-nested');
            var target = null;
            if (host) {
                var body = null;
                Array.prototype.slice.call(host.children).forEach(function (child) {
                    if (child.classList && child.classList.contains('mx-comment-body')) {
                        body = child;
                    }
                });
                if (body) {
                    Array.prototype.slice.call(body.children).forEach(function (child) {
                        if (child.classList && child.classList.contains('mx-reply-editor')) {
                            target = child;
                        }
                    });
                }
            }
            if (!target) {
                target = document.getElementById(replyButton.getAttribute('data-reply-target'));
            }
            if (!target) {
                return;
            }
            if (loginRequired(target)) {
                openLoginModal();
                return;
            }
            document.querySelectorAll('.mx-reply-editor.active').forEach(function (form) {
                if (form !== target) {
                    form.classList.remove('active');
                }
            });
            target.classList.toggle('active');
            if (target.classList.contains('active')) {
                var vcodeImage = target.querySelector('[data-vcode-refresh]');
                if (vcodeImage && !vcodeImage.getAttribute('src')) {
                    refreshCommentVcode(target);
                }
                var textarea = target.querySelector('textarea');
                if (textarea) {
                    textarea.focus();
                }
            }
        });

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.matches('.mx-comment-editor, .mx-reply-editor')) {
                return;
            }
            if (loginRequired(form)) {
                event.preventDefault();
                openLoginModal();
                return;
            }
            if (form.getAttribute('data-mx-submit') === '1') {
                event.preventDefault();
                var button = form.querySelector('button[type="submit"]');
                if (button) { button.disabled = true; }
                fetch(form.getAttribute('action'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(form),
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
                }).then(function (response) {
                    return response.text().then(function (raw) {
                        var data = null;
                        try { data = JSON.parse(raw); } catch (e) {}
                        if (!data) {
                            throw new Error('评论接口返回格式错误');
                        }
                        return data;
                    });
                }).then(function (data) {
                    if (data && (Number(data.status) === 1 || Number(data.status) === 200 || Number(data.status) === 201)) {
                        window.location.reload();
                        return;
                    }
                    var message = (data && (data.info || data.msg)) || '评论提交失败，请稍后重试';
                    var notice = form.querySelector('.mx-comment-error');
                    if (!notice) { notice = document.createElement('p'); notice.className = 'mx-comment-error'; form.appendChild(notice); }
                    notice.textContent = message;
                    refreshCommentVcode(form);
                    if (button) { button.disabled = false; }
                }).catch(function () {
                    var notice = form.querySelector('.mx-comment-error');
                    if (!notice) { notice = document.createElement('p'); notice.className = 'mx-comment-error'; form.appendChild(notice); }
                    notice.textContent = '评论提交失败，请稍后重试';
                    refreshCommentVcode(form);
                    if (button) { button.disabled = false; }
                });
            }
        });

        document.addEventListener('focusin', function (event) {
            var field = event.target;
            if (!field || !field.matches('.mx-comment-editor textarea, .mx-reply-editor textarea')) {
                return;
            }
            var form = field.closest('.mx-comment-editor, .mx-reply-editor');
            if (loginRequired(form)) {
                field.blur();
                openLoginModal();
            }
        });

        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-login-close]')) {
                var modal = document.querySelector('[data-login-modal]');
                if (modal) {
                    modal.hidden = true;
                    document.body.classList.remove('mx-modal-open');
                }
            }
        });
    }

    function accountDialogHtml(type) {
        var routes = window.mxoneUserRoutes || {};
        var minDays = Math.max(1, Number(routes.vipMinDays) || 1);
        var price = Math.max(0, Number(routes.vipPrice) || 0);
        if (type === 'vip') {
            return '<h3>VIP 权限续期</h3><p class="mx-account-help">最低购买 ' + minDays + ' 天，每天 ' + price + ' 影币。</p><label>购买天数<input name="score_ext" type="number" min="' + minDays + '" value="' + minDays + '" required></label>';
        }
        if (type === 'email') {
            return '<h3>修改登录邮箱</h3><p class="mx-account-help">需要验证当前密码。</p><label>当前密码<input name="user_pwd" type="password" autocomplete="current-password" required></label><label>新邮箱<input name="user_email" type="email" autocomplete="email" required></label>';
        }
        return '<h3>修改登录密码</h3><p class="mx-account-help">修改成功后需要重新登录。</p><label>当前密码<input name="user_pwd_old" type="password" autocomplete="current-password" required></label><label>新密码<input name="user_pwd" type="password" minlength="6" autocomplete="new-password" required></label><label>确认新密码<input name="user_pwd_re" type="password" minlength="6" autocomplete="new-password" required></label>';
    }

    function paymentName(type) {
        return {
            alipay: '支付宝', wxpay: '微信支付', paypal: 'PayPal', rj: '瑞捷支付',
            code_ali: '支付宝码支付', code_qq: 'QQ 钱包码支付', code_wxpay: '微信码支付'
        }[type] || type;
    }

    function walletDialogHtml() {
        var routes = window.mxoneUserRoutes || {};
        var methods = Array.isArray(routes.payMethods) ? routes.payMethods : [];
        var min = Math.max(1, Number(routes.payMin) || 1);
        var scale = Math.max(1, Number(routes.payScale) || 1);
        var payment = '';
        if (methods.length) {
            var options = methods.map(function (type, index) {
                return '<label class="mx-wallet-method"><input type="radio" name="pay_type" value="' + escapeHtml(type) + '"' + (index === 0 ? ' checked' : '') + '><span>' + escapeHtml(paymentName(type)) + '</span></label>';
            }).join('');
            payment = '<form class="mx-wallet-form" data-wallet-payment method="post" action="' + escapeHtml(routes.payment || '') + '" target="_blank"><h4>在线充值</h4><p>1 元 = ' + scale + ' 影币，最低充值 ' + min + ' 元。</p><label>充值金额（元）<input name="score_ext" type="number" min="' + min + '" value="' + min + '" required></label><div class="mx-wallet-methods">' + options + '</div><button class="mx-account-submit" type="submit">前往支付</button></form>';
        } else {
            payment = '<div class="mx-wallet-unavailable"><h4>在线充值</h4><p>管理员尚未配置支付商户，目前不能直接在线付款。</p></div>';
        }
        var sell = routes.cardSell ? '<a class="mx-wallet-sell" href="' + escapeHtml(routes.cardSell) + '" target="_blank" rel="noopener">购买充值卡</a>' : '';
        return '<h3>充值影币</h3><p class="mx-account-help">可以使用后台生成的充值卡；配置支付商户后会同时开放在线支付。</p>' + payment + '<form class="mx-wallet-form" data-wallet-card><h4>充值卡密</h4><label>卡密<input name="card_number" type="text" maxlength="64" autocomplete="off" required></label><p class="mx-account-notice" aria-live="polite"></p><button class="mx-account-submit" type="submit">立即兑换</button>' + sell + '</form>';
    }

    function showWalletDialog() {
        var old = document.querySelector('[data-account-modal]');
        if (old) { old.remove(); }
        var modal = document.createElement('div');
        modal.className = 'mx-account-modal';
        modal.setAttribute('data-account-modal', 'wallet');
        modal.innerHTML = '<div class="mx-account-mask" data-account-close></div><div class="mx-account-box mx-wallet-box"><button class="mx-account-close" type="button" data-account-close aria-label="关闭">×</button><div class="mx-account-form">' + walletDialogHtml() + '</div></div>';
        document.body.appendChild(modal);
        document.body.classList.add('mx-modal-open');
        var cardForm = modal.querySelector('[data-wallet-card]');
        if (cardForm) {
            cardForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var button = cardForm.querySelector('button[type="submit"]');
                var notice = cardForm.querySelector('.mx-account-notice');
                button.disabled = true;
                notice.textContent = '正在兑换...';
                notice.className = 'mx-account-notice';
                fetch((window.mxoneUserRoutes || {}).card, {method: 'POST', credentials: 'same-origin', body: new FormData(cardForm), headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
                    .then(function (response) { return response.text(); })
                    .then(function (raw) {
                        var data = null;
                        try { data = JSON.parse(raw); } catch (e) {}
                        if (!data) { throw new Error('bad response'); }
                        if (Number(data.status) === 200) {
                            notice.textContent = '兑换成功，影币已到账。';
                            notice.className = 'mx-account-notice success';
                            window.setTimeout(function () { window.location.reload(); }, 700);
                            return;
                        }
                        if (Number(data.status) === 404 && String(data.info || '').indexOf('登录') !== -1) {
                            window.location.href = routeWithRedirect('login', '/user/login');
                            return;
                        }
                        notice.textContent = data.info || '兑换失败。';
                        notice.className = 'mx-account-notice error';
                        button.disabled = false;
                    }).catch(function () {
                        notice.textContent = '请求失败，请稍后再试。';
                        notice.className = 'mx-account-notice error';
                        button.disabled = false;
                    });
            });
        }
    }

    function claimDaily(trigger) {
        var routes = window.mxoneUserRoutes || {};
        if (!routes.daily) { return; }
        if (trigger) { trigger.setAttribute('aria-disabled', 'true'); }
        fetch(routes.daily, {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
            .then(function (response) { return response.text(); })
            .then(function (raw) {
                var data = null;
                try { data = JSON.parse(raw); } catch (e) {}
                if (!data) { throw new Error('bad response'); }
                if (Number(data.status) === 200) {
                    window.alert('领取成功，获得 ' + Number(data.data.reward || routes.dailyReward || 0) + ' 影币。');
                    window.location.reload();
                    return;
                }
                if (Number(data.status) === 404) {
                    window.location.href = routeWithRedirect('login', '/user/login');
                    return;
                }
                window.alert(data.info || '领取失败。');
                if (trigger) { trigger.removeAttribute('aria-disabled'); }
            }).catch(function () {
                window.alert('请求失败，请稍后再试。');
                if (trigger) { trigger.removeAttribute('aria-disabled'); }
            });
    }

    function showAccountDialog(type) {
        var old = document.querySelector('[data-account-modal]');
        if (old) { old.remove(); }
        var routes = window.mxoneUserRoutes || {};
        var endpoint = type === 'vip' ? routes.vip : (type === 'email' ? routes.email : routes.password);
        if (!endpoint) { return; }
        var modal = document.createElement('div');
        modal.className = 'mx-account-modal';
        modal.setAttribute('data-account-modal', type);
        modal.innerHTML = '<div class="mx-account-mask" data-account-close></div><div class="mx-account-box"><button class="mx-account-close" type="button" data-account-close aria-label="关闭">×</button><form class="mx-account-form">' + accountDialogHtml(type) + '<p class="mx-account-notice" aria-live="polite"></p>' + (type === 'vip' ? '<div class="mx-account-wallet-actions" hidden><button type="button" class="user-score-daily">每日领取</button><button type="button" class="user-score-payment">充值影币</button></div>' : '') + '<button class="mx-account-submit" type="submit">确认提交</button></form></div>';
        document.body.appendChild(modal);
        document.body.classList.add('mx-modal-open');
        var form = modal.querySelector('form');
        var notice = modal.querySelector('.mx-account-notice');
        var submit = modal.querySelector('.mx-account-submit');
        var firstInput = modal.querySelector('input');
        if (firstInput) { firstInput.focus(); }
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submit.disabled = true;
            notice.textContent = '正在提交...';
            notice.className = 'mx-account-notice';
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: new FormData(form),
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}
            }).then(function (response) {
                return response.text();
            }).then(function (raw) {
                var data = null;
                try { data = JSON.parse(raw); } catch (e) {}
                if (!data) { throw new Error('接口返回格式错误'); }
                if (Number(data.status) === 200) {
                    notice.textContent = type === 'vip' ? 'VIP 续期成功。' : (type === 'email' ? '邮箱修改成功。' : '密码修改成功，请重新登录。');
                    notice.className = 'mx-account-notice success';
                    window.setTimeout(function () {
                        if (type === 'password') {
                            window.location.href = routeWithRedirect('login', '/user/login');
                        } else {
                            window.location.reload();
                        }
                    }, 700);
                    return;
                }
                if (Number(data.status) === 404) {
                    window.location.href = routeWithRedirect('login', '/user/login');
                    return;
                }
                notice.textContent = Number(data.status) === 501 && type === 'vip' ? '影币不足，本次需要 ' + data.info + ' 影币。' : (data.info || '操作失败，请稍后重试。');
                notice.className = 'mx-account-notice error';
                var walletActions = modal.querySelector('.mx-account-wallet-actions');
                if (walletActions && Number(data.status) === 501) { walletActions.hidden = false; }
                submit.disabled = false;
            }).catch(function () {
                notice.textContent = '请求失败，请稍后重试。';
                notice.className = 'mx-account-notice error';
                submit.disabled = false;
            });
        });
    }

    function mountMemberAccountActions() {
        document.addEventListener('click', function (event) {
            var recordDelete = event.target.closest('.ff-record-delete');
            if (recordDelete) {
                event.preventDefault();
                if (recordDelete.disabled || !window.fetch) { return; }
                var recordId = recordDelete.getAttribute('data-id') || '';
                var recordUrl = recordDelete.getAttribute('data-url') || '/index.php?g=home&m=record&a=delete';
                if (!recordId) { return; }
                recordDelete.disabled = true;
                recordDelete.classList.add('is-loading');
                var originalText = recordDelete.textContent;
                recordDelete.textContent = '删除中';
                fetch(recordUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'id=' + encodeURIComponent(recordId)
                }).then(function (response) {
                    return response.ok ? response.json() : null;
                }).then(function (data) {
                    if (!data || Number(data.status) !== 200) {
                        throw new Error(data && data.info ? data.info : '删除失败');
                    }
                    var item = recordDelete.closest('.vod-record > li');
                    var list = item && item.parentNode;
                    if (item) { item.remove(); }
                    if (list && !list.querySelector('li')) {
                        var empty = document.createElement('p');
                        empty.className = 'mx-record-empty';
                        empty.textContent = '暂无记录';
                        list.parentNode.insertBefore(empty, list.nextSibling);
                    }
                }).catch(function (error) {
                    recordDelete.textContent = error.message || '删除失败';
                    recordDelete.classList.add('is-error');
                    window.setTimeout(function () {
                        recordDelete.textContent = originalText;
                        recordDelete.classList.remove('is-error');
                    }, 1800);
                    recordDelete.disabled = false;
                    recordDelete.classList.remove('is-loading');
                });
                return;
            }
            var trigger = event.target.closest('.user-score-upvip, .user-change-email, .user-change-pwd');
            if (trigger) {
                event.preventDefault();
                showAccountDialog(trigger.classList.contains('user-score-upvip') ? 'vip' : (trigger.classList.contains('user-change-email') ? 'email' : 'password'));
                return;
            }
            var daily = event.target.closest('.user-score-daily');
            if (daily) {
                event.preventDefault();
                if (!daily.hasAttribute('aria-disabled')) { claimDaily(daily); }
                return;
            }
            if (event.target.closest('.user-score-payment, .user-score-card')) {
                event.preventDefault();
                showWalletDialog();
                return;
            }
            var purchase = event.target.closest('[data-vod-purchase]');
            if (purchase) {
                event.preventDefault();
                if (purchase.hasAttribute('aria-disabled')) { return; }
                purchase.setAttribute('aria-disabled', 'true');
                var payNotice = purchase.parentNode.querySelector('.mx-paywall-notice');
                if (payNotice) { payNotice.textContent = '正在校验影币...'; }
                fetch(purchase.getAttribute('data-url'), {method: 'GET', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}})
                    .then(function (response) { return response.text(); })
                    .then(function (raw) {
                        var data = null;
                        try { data = JSON.parse(raw); } catch (e) {}
                        if (!data) { throw new Error('bad response'); }
                        if (Number(data.status) === 200) { window.location.reload(); return; }
                        if (Number(data.status) === 500 || Number(data.status) === 501) { window.location.href = routeWithRedirect('login', '/user/login'); return; }
                        if (Number(data.status) === 504) { showWalletDialog(); }
                        if (payNotice) { payNotice.textContent = Number(data.status) === 504 ? '影币不足，请先领取或充值。' : (data.info || '支付失败。'); }
                        purchase.removeAttribute('aria-disabled');
                    }).catch(function () {
                        if (payNotice) { payNotice.textContent = '请求失败，请稍后再试。'; }
                        purchase.removeAttribute('aria-disabled');
                    });
                return;
            }
            if (event.target.closest('[data-account-close]')) {
                var modal = document.querySelector('[data-account-modal]');
                if (modal) { modal.remove(); }
                document.body.classList.remove('mx-modal-open');
            }
        });
    }

    window.mxone = {
        addHistory: function (item) {
            if (historyLimit < 1) {
                return;
            }
            item = normalizeHistoryItem(item);
            if (!item.id) {
                return;
            }
            var items = readHistory().filter(function (row) {
                return row.id !== item.id;
            });
            item.time = Date.now();
            items.unshift(item);
            writeHistory(items);
        },
        renderHistory: function (selector) {
            var box = document.querySelector(selector);
            if (!box) {
                return;
            }
            var items = readHistory();
            box.innerHTML = historyHtml(items);
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        mountPosterFallbacks();
        mountHeaderSearch();
        mountHeaderHistory();
        mountFloatingActions();
        mountMobileHeader();
        mountScenarioPagination();
        mountThemeToggle();
        mountHeaderAuth();
        mountSameActor();
        mountHomeHero();
        mountPlaySources();
		mountEpisodeLists();
        mountVodRating();
        mountVodUpdown();
        mountVodFavorite();
		mountDescriptionToggle();
        mountComments();
        mountMemberAccountActions();
        var history = document.querySelector('[data-mx-history-item]');
        if (history) {
            var historyItem = {
                id: history.getAttribute('data-vod-id') || '',
                name: history.getAttribute('data-vod-name') || '',
                episode: history.getAttribute('data-episode-title') || '',
                url: window.location.pathname + window.location.search
            };
            window.mxone.addHistory(historyItem);
            if (window.fetch && csrfToken()) {
                fetch('/user/history', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: 'id=' + encodeURIComponent(historyItem.id) + '&episode_id=' + encodeURIComponent(history.getAttribute('data-episode-id') || '') + '&_token=' + encodeURIComponent(csrfToken())
                }).catch(function () {});
            }
        }
    });
}());
