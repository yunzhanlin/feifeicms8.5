(function () {
    'use strict';

    function safeUrl(url) {
        url = String(url || '').trim();
        if (!url) {
            return '';
        }
        if (/[\u0000-\u001f\u007f]/.test(url) || /^(javascript|data|vbscript):/i.test(url)) {
            return '';
        }
        return url;
    }

    function endpoint(base, params) {
        var url = new URL(base, window.location.origin);
        Object.keys(params || {}).forEach(function (key) {
            if (params[key] !== '' && params[key] !== null && params[key] !== undefined) {
                url.searchParams.set(key, params[key]);
            }
        });
        return url.pathname + url.search;
    }

    function showMessage(box, title, message, kind) {
        var isError = kind === 'error';
        box.innerHTML = '<div class="ff-player-empty' + (isError ? ' is-error' : ' is-loading') + '" role="' + (isError ? 'alert' : 'status') + '">'
            + '<b class="ff-player-state-icon" aria-hidden="true">' + (isError ? '!' : '•••') + '</b>'
            + '<strong></strong><span></span>'
            + (isError ? '<small>可以刷新页面，或切换其他播放线路后重试。</small><button type="button">重新加载</button>' : '')
            + '</div>';
        var strong = box.querySelector('strong');
        var span = box.querySelector('span');
        if (strong) {
            strong.textContent = title || '播放器提示';
        }
        if (span) {
            span.textContent = message || '未知错误';
        }
        var retry = box.querySelector('button');
        if (retry) {
            retry.addEventListener('click', function () {
                window.location.reload();
            });
        }
    }

    function showError(box, message) {
        showMessage(box, '视频播放失败', message || '播放地址无效、已过期，或者视频源暂时无法连接。', 'error');
    }

    function mediaErrorMessage(video) {
        var code = video && video.error ? Number(video.error.code || 0) : 0;
        var messages = {
            1: '视频加载已被中止，请重新加载后再试。',
            2: '视频源网络连接失败，请刷新页面或切换线路。',
            3: '视频解码失败，当前浏览器可能不支持该视频格式。',
            4: '播放地址无效、已过期，或者当前浏览器不支持此格式。'
        };
        return messages[code] || '视频源暂时无法播放，请刷新页面或切换线路。';
    }

    function normalizeDanmuResponse(payload) {
        var list = Array.isArray(payload)
            ? payload
            : Array.isArray(payload && payload.data)
                ? payload.data
                : Array.isArray(payload && payload.data && payload.data.danmuku)
                    ? payload.data.danmuku
                : Array.isArray(payload && payload.danmuku)
                    ? payload.danmuku
                    : [];

        return list.filter(function (item) {
            if (Array.isArray(item)) {
                return typeof item[4] === 'string' && item[4].trim();
            }
            return item && typeof item.text === 'string' && item.text.trim();
        }).map(function (item) {
            var isRemoteTuple = Array.isArray(item);
            var rawMode = isRemoteTuple ? item[1] : (item.mode !== undefined ? item.mode : item.type);
            var modeMap = {right: 0, scroll: 0, top: 1, bottom: 2};
            var mode = Object.prototype.hasOwnProperty.call(modeMap, String(rawMode).toLowerCase())
                ? modeMap[String(rawMode).toLowerCase()]
                : Number(rawMode);
            var color = String(isRemoteTuple ? item[2] : (item.color || ''));
            if (/^#[0-9a-f]{3}$/i.test(color)) {
                color = '#' + color.charAt(1) + color.charAt(1)
                    + color.charAt(2) + color.charAt(2)
                    + color.charAt(3) + color.charAt(3);
            }
            return {
                text: String(isRemoteTuple ? item[4] : item.text).slice(0, 200),
                time: Math.max(0, Number(isRemoteTuple ? item[0] : (item.time || 0))),
                mode: [0, 1, 2].indexOf(mode) >= 0 ? mode : 0,
                color: /^#[0-9a-f]{6}$/i.test(color) ? color : '#FFFFFF',
                border: Boolean(!isRemoteTuple && item.border)
            };
        });
    }

    function fetchJson(url, options) {
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            cache: 'no-store'
        }, options || {})).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (payload) {
                if (!response.ok) {
                    throw new Error((payload && (payload.message || payload.info)) || ('HTTP ' + response.status));
                }
                return payload;
            });
        });
    }

    function mediaTypeFromUrl(url) {
        var path = '';
        try {
            path = new URL(url, window.location.origin).pathname.toLowerCase();
        } catch (error) {
            path = String(url).split('?')[0].toLowerCase();
        }
        if (/\.m3u8$/.test(path)) {
            return 'm3u8';
        }
        if (/\.mp4$/.test(path)) {
            return 'mp4';
        }
        if (/\.webm$/.test(path)) {
            return 'webm';
        }
        if (/\.ogg$/.test(path)) {
            return 'ogg';
        }
        return '';
    }

    function playM3u8(video, url, art) {
        if (window.Hls && window.Hls.isSupported()) {
            if (art.hls) {
                art.hls.destroy();
            }
            var hls = new window.Hls({
                enableWorker: true,
                lowLatencyMode: Boolean(art.ffApp && art.ffApp.hlsLowLatency),
                backBufferLength: Number(art.ffApp && art.ffApp.hlsBackBuffer) || 90
            });
            hls.loadSource(url);
            hls.attachMedia(video);
            art.hls = hls;
            art.on('destroy', function () {
                hls.destroy();
            });
            return;
        }
        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = url;
            return;
        }
        art.notice.show = '当前浏览器不支持 HLS/M3U8 播放';
    }

    function resolvePlainSource(app) {
        var directType = mediaTypeFromUrl(app.videoUrl);
        if (directType) {
            return Promise.resolve({
                url: app.videoUrl,
                type: directType,
                parsed: false
            });
        }
        return fetchJson(endpoint(app.parseApi, {url: app.videoUrl})).then(function (result) {
            var data = result && result.data ? result.data : {};
            if (!data.url) {
                throw new Error((result && result.message) || '解析接口未返回播放地址');
            }
            return {
                url: data.url,
                type: data.type || mediaTypeFromUrl(data.url) || 'm3u8',
                parsed: true
            };
        });
    }

    function resolveSource(app) {
        if (!app.sourceToken) {
            return resolvePlainSource(app);
        }
        return fetchJson(app.sourceApi, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({token: app.sourceToken})
        }).then(function (result) {
            var data = result && result.data ? result.data : {};
            app.videoUrl = safeUrl(data.url);
            if (!app.videoUrl) {
                throw new Error((result && result.message) || '播放令牌没有返回有效地址');
            }
            return resolvePlainSource(app);
        });
    }

    function installPlaybackMemory(art, videoId) {
        var key = 'dmartplayer:progress:' + videoId;
        var restored = false;
        var lastSavedAt = 0;

        function canUseDuration() {
            return Number.isFinite(art.duration) && art.duration > 0;
        }
        function restore() {
            if (restored || !canUseDuration()) {
                return;
            }
            restored = true;
            var savedTime = Number(localStorage.getItem(key) || 0);
            if (savedTime > 5 && savedTime < art.duration - 8) {
                art.currentTime = savedTime;
                art.notice.show = '已从 ' + Math.floor(savedTime) + ' 秒继续播放';
            }
        }
        function save() {
            if (!canUseDuration()) {
                return;
            }
            var now = Date.now();
            if (now - lastSavedAt < 3000) {
                return;
            }
            lastSavedAt = now;
            if (art.currentTime > 5 && art.currentTime < art.duration - 5) {
                localStorage.setItem(key, String(Math.floor(art.currentTime)));
            }
        }

        art.video.addEventListener('loadedmetadata', restore);
        art.video.addEventListener('canplay', restore);
        art.video.addEventListener('timeupdate', save);
        art.video.addEventListener('pause', save);
        window.addEventListener('beforeunload', save);
    }

    function installSkipSettings(app) {
        // 同一部影片的所有分集共用片头、片尾设置。旧版本把 episode
        // 写入键名，导致自动进入下一集后设置看起来“丢失”。
        var scopeId = app.recordDid
            ? 'vod-' + String(app.recordDid)
            : String(app.videoId || '').replace(/-ep-\d+$/i, '');
        var prefix = 'dmartplayer:skip:' + scopeId + ':';
        var legacyPrefix = 'dmartplayer:skip:' + String(app.videoId || '') + ':';
        var skipIntroSeconds = read('intro');
        var skipOutroSeconds = read('outro');
        var controller = null;

        function read(type) {
            var stored = localStorage.getItem(prefix + type);
            if (stored === null && legacyPrefix !== prefix) {
                stored = localStorage.getItem(legacyPrefix + type);
                if (stored !== null) {
                    localStorage.setItem(prefix + type, stored);
                }
            }
            var value = Number(stored);
            return Number.isFinite(value) && value >= 0 ? value : 0;
        }
        function format(seconds) {
            seconds = Math.max(0, Number(seconds || 0));
            return seconds ? seconds + ' 秒' : '关闭';
        }
        function set(type, seconds, art) {
            seconds = Math.max(0, Number(seconds || 0));
            if (type === 'intro') {
                skipIntroSeconds = seconds;
            } else {
                skipOutroSeconds = seconds;
            }
            localStorage.setItem(prefix + type, String(seconds));
            if (controller) {
                controller.reset(type);
            }
            if (art) {
                art.notice.show = (type === 'intro' ? '片头' : '片尾') + '跳过：' + format(seconds);
            }
        }
        function custom(type, art) {
            var label = type === 'intro' ? '片头' : '片尾';
            var current = type === 'intro' ? skipIntroSeconds : skipOutroSeconds;
            var input = window.prompt('请输入' + label + '跳过秒数，0 表示关闭', String(current || ''));
            if (input === null) {
                return current;
            }
            var seconds = Number(input);
            if (!Number.isFinite(seconds) || seconds < 0) {
                art.notice.show = '请输入大于或等于 0 的数字';
                return current;
            }
            set(type, Math.round(seconds * 1000) / 1000, art);
            return type === 'intro' ? skipIntroSeconds : skipOutroSeconds;
        }
        function build(type, artRef) {
            var getValue = function () {
                return type === 'intro' ? skipIntroSeconds : skipOutroSeconds;
            };
            var presets = [0, 30, 60, 90, 120, 180].map(function (seconds) {
                return {
                    html: format(seconds),
                    value: seconds,
                    default: getValue() === seconds
                };
            });
            presets.push({
                html: '自定义...',
                value: 'custom',
                default: [0, 30, 60, 90, 120, 180].indexOf(getValue()) < 0
            });
            return {
                html: type === 'intro' ? '片头跳过' : '片尾跳过',
                tooltip: format(getValue()),
                selector: presets,
                onSelect: function (item) {
                    var seconds = item.value === 'custom' ? custom(type, artRef.value) : Number(item.value);
                    if (item.value !== 'custom') {
                        set(type, seconds, artRef.value);
                    }
                    return format(seconds);
                }
            };
        }
        function install(art) {
            var introSkipped = false;
            var outroSkipped = false;
            function canUseDuration() {
                return Number.isFinite(art.duration) && art.duration > 0;
            }
            function skipIntro() {
                var intro = Math.max(0, Number(skipIntroSeconds || 0));
                if (introSkipped || !intro || !canUseDuration()) {
                    return;
                }
                if (art.currentTime < intro && art.duration > intro + 1) {
                    art.currentTime = intro;
                    art.notice.show = '已跳过片头 ' + intro + ' 秒';
                }
                introSkipped = true;
            }
            function skipOutro() {
                var outro = Math.max(0, Number(skipOutroSeconds || 0));
                if (outroSkipped || !outro || !canUseDuration() || art.duration <= outro + 1) {
                    return;
                }
                if (art.currentTime >= art.duration - outro) {
                    outroSkipped = true;
                    art.currentTime = Math.max(0, art.duration - 0.25);
                    art.notice.show = '已跳过片尾 ' + outro + ' 秒';
                }
            }
            art.on('ready', skipIntro);
            art.video.addEventListener('loadedmetadata', skipIntro);
            art.video.addEventListener('timeupdate', skipOutro);
            controller = {
                reset: function (type) {
                    if (type === 'intro') {
                        introSkipped = false;
                        skipIntro();
                    } else {
                        outroSkipped = false;
                    }
                }
            };
        }
        return {
            build: build,
            install: install
        };
    }

    function renderFallback(box, source, app) {
        var url = source && source.url ? source.url : '';
        var type = source && source.type ? source.type : mediaTypeFromUrl(url);
        if (type === 'm3u8' || type === 'mp4' || type === 'webm' || type === 'ogg') {
            var autoplay = app && app.autoplay ? ' autoplay' : '';
            box.innerHTML = '<video class="ff-player-video" src="' + encodeURI(url) + '" controls playsinline preload="metadata"' + autoplay + '></video>';
            var fallbackVideo = box.querySelector('video');
            if (fallbackVideo) {
                fallbackVideo.addEventListener('error', function () {
                    showError(box, mediaErrorMessage(fallbackVideo));
                });
            }
            return;
        }
        showError(box, '解析后的播放地址不是可直接播放的视频地址');
    }

    function render(target, options) {
        var box = typeof target === 'string' ? document.querySelector(target) : target;
        if (!box) {
            return;
        }
        var app = {
            videoUrl: safeUrl((options && options.url) || box.getAttribute('data-url')),
            sourceToken: String((options && options.sourceToken) || box.getAttribute('data-source-token') || ''),
            sourceApi: (options && options.sourceApi) || box.getAttribute('data-source-api') || '/danmu/source',
            type: String((options && options.type) || box.getAttribute('data-type') || '').toLowerCase(),
            title: (options && options.title) || box.getAttribute('data-title') || document.title,
            videoId: (options && options.videoId) || box.getAttribute('data-video-id') || '',
            legacyVideoId: (options && options.legacyVideoId) || box.getAttribute('data-legacy-video-id') || '',
            danmuReadApi: (options && options.danmuReadApi) || box.getAttribute('data-danmu-read-api') || '/danmu/read',
            danmuSendApi: (options && options.danmuSendApi) || box.getAttribute('data-danmu-send-api') || '/danmu/send',
            remoteDanmuApi: (options && options.remoteDanmuApi) || box.getAttribute('data-remote-danmu-api') || '/danmu/remote',
            parseApi: (options && options.parseApi) || box.getAttribute('data-parse-api') || '/danmu/parse',
            recordApi: (options && options.recordApi) || box.getAttribute('data-record-api') || '',
            recordSid: (options && options.recordSid) || box.getAttribute('data-record-sid') || '1',
            recordDid: (options && options.recordDid) || box.getAttribute('data-record-did') || '',
            recordDidSid: (options && options.recordDidSid) || box.getAttribute('data-record-did-sid') || '',
            recordDidPid: (options && options.recordDidPid) || box.getAttribute('data-record-did-pid') || '',
            recordName: (options && options.recordName) || box.getAttribute('data-record-name') || '',
            recordCategory: (options && options.recordCategory) || box.getAttribute('data-record-category') || '',
            recordEpisode: (options && options.recordEpisode) || box.getAttribute('data-record-episode') || '',
            autoplay: String((options && options.autoplay) || box.getAttribute('data-autoplay') || '0') === '1',
            autoNext: String((options && options.autoNext) || box.getAttribute('data-auto-next') || '0') === '1',
            nextUrl: safeUrl((options && options.nextUrl) || box.getAttribute('data-next-url') || ''),
            pageUrl: safeUrl((options && options.pageUrl) || box.getAttribute('data-page-url') || ''),
            theme: String((options && options.theme) || box.getAttribute('data-theme') || '#22C55E'),
            preload: String((options && options.preload) || box.getAttribute('data-preload') || 'metadata'),
            volume: Number((options && options.volume) || box.getAttribute('data-volume') || 0.7),
            muted: String((options && options.muted) || box.getAttribute('data-muted') || '0') === '1',
            screenshot: String((options && options.screenshot) || box.getAttribute('data-screenshot') || '1') === '1',
            pip: String((options && options.pip) || box.getAttribute('data-pip') || '1') === '1',
            playbackRate: String((options && options.playbackRate) || box.getAttribute('data-playback-rate') || '1') === '1',
            miniProgressBar: String((options && options.miniProgressBar) || box.getAttribute('data-mini-progress') || '1') === '1',
            lock: String((options && options.lock) || box.getAttribute('data-lock') || '1') === '1',
            fastForward: String((options && options.fastForward) || box.getAttribute('data-fast-forward') || '1') === '1',
            danmuEnabled: String((options && options.danmuEnabled) || box.getAttribute('data-danmu-enabled') || '1') === '1',
            hlsLowLatency: String((options && options.hlsLowLatency) || box.getAttribute('data-hls-low-latency') || '0') === '1',
            hlsBackBuffer: Number((options && options.hlsBackBuffer) || box.getAttribute('data-hls-back-buffer') || 90)
        };
        if (!/^#[0-9a-f]{6}$/i.test(app.theme)) app.theme = '#22C55E';
        if (['none', 'metadata', 'auto'].indexOf(app.preload) < 0) app.preload = 'metadata';
        if (!Number.isFinite(app.volume)) app.volume = 0.7;
        app.volume = Math.max(0, Math.min(1, app.volume));
        if (!Number.isFinite(app.hlsBackBuffer)) app.hlsBackBuffer = 90;
        app.hlsBackBuffer = Math.max(10, Math.min(600, app.hlsBackBuffer));
        app.videoId = app.videoId || String(Math.abs(hashCode(app.videoUrl)));

        // Keep both MXONE local history and the native FeiFeiCMS record in sync.
        // This runs once when a play page is opened, including guest users (cookie mode).
        if (app.recordDid && app.recordApi) {
            var recordParams = new URLSearchParams({
                sid: String(app.recordSid || 1),
                did: String(app.recordDid),
                type: '1',
                did_sid: String(app.recordDidSid || 0),
                did_pid: String(app.recordDidPid || 0)
            });
            fetch(app.recordApi + (app.recordApi.indexOf('?') >= 0 ? '&' : '?') + recordParams.toString(), {credentials: 'same-origin'}).catch(function () {});
        }
        if (window.mxone && typeof window.mxone.addHistory === 'function') {
            window.mxone.addHistory({
                // The local header history is one row per film. The native
                // FeiFei record endpoint above already follows this rule.
                id: app.recordDid ? 'vod-' + app.recordDid : app.videoId,
                url: app.pageUrl || window.location.href,
                name: app.recordName || app.title,
                episode: app.recordEpisode || (app.recordDidPid ? '第' + app.recordDidPid + '集' : '')
            });
        }

        if (!app.videoUrl && !app.sourceToken) {
            box.innerHTML = '<div class="ff-player-empty">暂无播放地址</div>';
            return;
        }

        var artRef = {value: null};
        var skip = installSkipSettings(app);

        function loadDanmuku() {
            var tasks = [
                fetchJson(endpoint(app.danmuReadApi, {id: app.videoId, legacy_id: app.legacyVideoId, url: app.videoUrl}))
            ];
            tasks.push(fetchJson(endpoint(app.remoteDanmuApi, {
                title: app.recordName || app.title,
                episode: app.recordDidPid || 1,
                category: app.recordCategory
            })));
            return Promise.allSettled(tasks).then(function (results) {
                var danmuku = [];
                results.forEach(function (result) {
                    if (result.status === 'fulfilled') {
                        danmuku = danmuku.concat(normalizeDanmuResponse(result.value));
                    }
                });
                return danmuku.sort(function (left, right) {
                    return left.time - right.time;
                });
            });
        }

        function saveDanmu(danmu) {
            app.lastDanmuError = '';
            return fetchJson(app.danmuSendApi, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    id: app.videoId,
                    _token: ((document.querySelector('meta[name="csrf-token"]') || {}).content || ''),
                    url: app.videoUrl,
                    text: danmu.text,
                    time: Number(danmu.time || 0),
                    mode: Number(danmu.mode || 0),
                    color: danmu.color || '#FFFFFF',
                    border: Boolean(danmu.border)
                })
            }).then(function (result) {
                var ok = result && (result.code === 0 || Number(result.status) === 200);
                if (!ok) {
                    app.lastDanmuError = (result && (result.message || result.info || result.errorMessage))
                        || '弹幕发送失败，请稍后再试';
                }
                return ok;
            }).catch(function (error) {
                app.lastDanmuError = error && error.message ? error.message : '弹幕发送失败，请稍后再试';
                return false;
            });
        }

        showMessage(box, '正在解析播放地址', '请稍候，正在获取真实播放地址...');

        resolveSource(app).then(function (source) {
            app.resolvedVideoUrl = safeUrl(source.url) || app.videoUrl;
            if (!window.Artplayer) {
                renderFallback(box, source, app);
                return;
            }
            box.innerHTML = '';
            var plugins = [];
            if (app.danmuEnabled && window.artplayerPluginDanmuku) {
                plugins.push(window.artplayerPluginDanmuku({
                    danmuku: loadDanmuku,
                    speed: 5,
                    opacity: 0.92,
                    margin: [12, '50%'],
                    fontSize: window.matchMedia('(max-width: 600px)').matches ? 16 : (window.matchMedia('(max-width: 900px)').matches ? 20 : 25),
                    color: '#FFFFFF',
                    mode: 0,
                    modes: [0, 1, 2],
                    antiOverlap: true,
                    synchronousPlayback: false,
                    visible: true,
                    emitter: true,
                    maxLength: 200,
                    lockTime: 3,
                    theme: 'dark',
                    beforeEmit: function (danmu) {
                        return saveDanmu(danmu).then(function (ok) {
                            if (!ok && artRef.value) artRef.value.notice.show = app.lastDanmuError || '弹幕发送失败，请稍后再试';
                            return ok;
                        });
                    },
                    filter: function (danmu) { return danmu.text.length <= 200; },
                    OPACITY: {name: '透明度', default: 0.92},
                    MARGIN: {name: '显示区域', default: 0.25},
                    SPEED: {name: '速度', default: 5},
                    COLOR: ['#FFFFFF', '#22C55E', '#38BDF8', '#FACC15', '#FB7185', '#C084FC']
                }));
            }
            var art = new window.Artplayer({
                container: box,
                url: source.url,
                title: app.title,
                type: source.type === 'm3u8' ? 'm3u8' : '',
                customType: {m3u8: function (video, url, artInstance) {
                    artInstance.ffApp = app;
                    playM3u8(video, url, artInstance);
                }},
                autoplay: app.autoplay,
                muted: app.muted,
                volume: app.volume,
                preload: app.preload,
                autoPlayback: false,
                setting: true,
                hotkey: true,
                pip: app.pip,
                screenshot: app.screenshot,
                mutex: true,
                fullscreen: true,
                fullscreenWeb: true,
                autoOrientation: true,
                playbackRate: app.playbackRate,
                aspectRatio: true,
                miniProgressBar: app.miniProgressBar,
                settings: [
                    skip.build('intro', artRef),
                    skip.build('outro', artRef)
                ],
                lock: app.lock,
                fastForward: app.fastForward,
                theme: app.theme,
                plugins: plugins
            });
            art.ffApp = app;
            artRef.value = art;
	            installPlaybackMemory(art, app.videoId);
	            skip.install(art);
	            art.video.addEventListener('ended', function () {
	                if (app.autoNext && app.nextUrl) {
	                    if (window.parent && window.parent !== window) window.parent.location.href = app.nextUrl;
	                    else window.location.href = app.nextUrl;
	                }
	            });
                art.video.addEventListener('error', function () {
                    var message = mediaErrorMessage(art.video);
                    try {
                        art.destroy(false);
                    } catch (ignore) {}
                    showError(box, message);
                });
	            window.art = art;
        }).catch(function (error) {
            showError(box, error && error.message);
        });
    }

    function hashCode(value) {
        var hash = 0;
        for (var index = 0; index < String(value).length; index++) {
            hash = ((hash << 5) - hash) + String(value).charCodeAt(index);
            hash |= 0;
        }
        return hash;
    }

    window.FeiFeiPlayer = {
        render: render,
        safeUrl: safeUrl
    };

    document.addEventListener('DOMContentLoaded', function () {
        var auto = document.querySelector('[data-ff-player]');
        if (auto) {
            render(auto);
        }
    });
}());
