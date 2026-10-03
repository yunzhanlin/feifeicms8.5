<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;
use think\middleware\SessionInit;

Route::get('/', 'Index/index');
Route::get('install', 'Install/index')->middleware(SessionInit::class);
Route::post('install', 'Install/submit')->middleware(SessionInit::class);
Route::get('health', 'Health/index');
Route::get('search', 'Search/index');
Route::get('vod/search', 'Search/index');
Route::get('latest', 'Page/latest');
Route::get('hot', 'Page/hot');
Route::get('news', 'Page/news');
Route::get('special', 'Page/special');
Route::get('person', 'Page/person');
Route::get('map', 'Page/map');
Route::get('rss', 'Page/rss');
Route::get('payment', 'Page/payment')->middleware(SessionInit::class);
Route::get('vip', 'Page/vip')->middleware(SessionInit::class);
Route::get('features', 'Page/features')->middleware(SessionInit::class);
Route::get('list/:id', 'Category/show')->pattern(['id' => '\\d+']);
Route::get('list/read/id/:id', 'Category/show')->pattern(['id' => '\\d+']);
Route::get('list/read/id/:id.html', 'Category/show')->pattern(['id' => '\\d+']);
Route::get('news/:id', 'News/detail')->pattern(['id' => '\\d+']);
Route::get('news/read/id/:id', 'News/detail')->pattern(['id' => '\\d+']);
Route::get('news/read/id/:id.html', 'News/detail')->pattern(['id' => '\\d+']);
Route::get('special/:id', 'Special/detail')->pattern(['id' => '\\d+']);
Route::get('special/read/id/:id', 'Special/detail')->pattern(['id' => '\\d+']);
Route::get('star/:id', 'Person/detail')->pattern(['id' => '\\d+']);
Route::get('role/:id', 'Person/detail')->pattern(['id' => '\\d+']);
Route::get('vod/read/id/:id', 'Vod/detail')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('vod/read/id/:id.html', 'Vod/detail')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('vod/play/id/:id/sid/:sid/pid/:pid', 'Vod/play')
    ->pattern(['id' => '\\d+', 'sid' => '\\d+', 'pid' => '\\d+'])->middleware(SessionInit::class);
Route::get('vod/play/id/:id/sid/:sid/pid/:pid.html', 'Vod/play')
    ->pattern(['id' => '\\d+', 'sid' => '\\d+', 'pid' => '\\d+'])->middleware(SessionInit::class);
foreach (['htm', 'shtml', 'shtm'] as $legacySuffix) {
    Route::get('list/:id.' . $legacySuffix, 'Category/show')->pattern(['id' => '\\d+']);
    Route::get('list/read/id/:id.' . $legacySuffix, 'Category/show')->pattern(['id' => '\\d+']);
    Route::get('news/:id.' . $legacySuffix, 'News/detail')->pattern(['id' => '\\d+']);
    Route::get('news/read/id/:id.' . $legacySuffix, 'News/detail')->pattern(['id' => '\\d+']);
    Route::get('special/:id.' . $legacySuffix, 'Special/detail')->pattern(['id' => '\\d+']);
    Route::get('star/:id.' . $legacySuffix, 'Person/detail')->pattern(['id' => '\\d+']);
    Route::get('role/:id.' . $legacySuffix, 'Person/detail')->pattern(['id' => '\\d+']);
    Route::get('vod/:id.' . $legacySuffix, 'Vod/detail')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
    Route::get('vod/read/id/:id.' . $legacySuffix, 'Vod/detail')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
    Route::get('play/:id/:sid/:pid.' . $legacySuffix, 'Vod/play')
        ->pattern(['id' => '\\d+', 'sid' => '\\d+', 'pid' => '\\d+'])->middleware(SessionInit::class);
    Route::get('vod/play/id/:id/sid/:sid/pid/:pid.' . $legacySuffix, 'Vod/play')
        ->pattern(['id' => '\\d+', 'sid' => '\\d+', 'pid' => '\\d+'])->middleware(SessionInit::class);
}
Route::get('vod/:id/comments', 'Interaction/comments')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('vod/:id/scenarios', 'Vod/scenarios')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('scenario/:id', 'Vod/scenario')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('vod/:id', 'Vod/detail')->pattern(['id' => '\\d+'])->middleware(SessionInit::class);
Route::get('play/:id/:sid/:pid', 'Vod/play')
    ->pattern(['id' => '\\d+', 'sid' => '\\d+', 'pid' => '\\d+'])->middleware(SessionInit::class);
Route::post('vod/rate', 'Interaction/rate')->middleware(SessionInit::class);
Route::post('vod/vote', 'Interaction/vote')->middleware(SessionInit::class);
Route::post('vod/unlock', 'Interaction/unlock')->middleware(SessionInit::class);
Route::post('forum/post', 'Interaction/comment')->middleware(SessionInit::class);
Route::any('user/favorite', 'Interaction/favorite')->middleware(SessionInit::class);
Route::post('user/history', 'Interaction/history')->middleware(SessionInit::class);
Route::any('guestbook', 'Guestbook/index')->middleware(SessionInit::class);
Route::any('user/login', 'User/login')->middleware(SessionInit::class);
Route::any('user/register', 'User/register')->middleware(SessionInit::class);
Route::get('user/center', 'User/center')->middleware(SessionInit::class);
Route::get('user/info', 'User/info')->middleware(SessionInit::class);
Route::post('user/logout', 'User/logout')->middleware(SessionInit::class);
Route::post('user/redeem', 'User/redeem')->middleware(SessionInit::class);
Route::get('danmu/read', 'Danmaku/read')->middleware(SessionInit::class);
Route::post('danmu/send', 'Danmaku/send')->middleware(SessionInit::class);
Route::get('danmu/remote', 'Danmaku/remote')->middleware(SessionInit::class);
Route::get('danmu/parse', 'Danmaku/parse')->middleware(SessionInit::class);
Route::any('api/provide/vod', 'api.VodProvider/index');
Route::any('api.php/provide/vod', 'api.VodProvider/index');

Route::get('admin.php', 'admin.Login/index')->middleware(SessionInit::class);
Route::post('admin.php/login', 'admin.Login/submit')->middleware(SessionInit::class);
Route::group('admin', function (): void {
    Route::get('', 'admin.Dashboard/index');
    Route::post('logout', 'admin.Login/logout');

    Route::get('categories', 'admin.Categories/index');
    Route::get('categories/create', 'admin.Categories/create');
    Route::post('categories', 'admin.Categories/store');
    Route::get('categories/:id/edit', 'admin.Categories/edit')->pattern(['id' => '\\d+']);
    Route::post('categories/:id', 'admin.Categories/update')->pattern(['id' => '\\d+']);
    Route::post('categories/:id/delete', 'admin.Categories/delete')->pattern(['id' => '\\d+']);
    Route::post('categories/batch', 'admin.Categories/batch');

    Route::get('vod', 'admin.Vod/index');
    Route::get('vod/create', 'admin.Vod/create');
    Route::post('vod', 'admin.Vod/store');
    Route::post('vod/metadata/douban', 'admin.Vod/doubanMetadata');
    Route::post('vod/upload', 'admin.Vod/uploadImage');
    Route::get('vod/:id/edit', 'admin.Vod/edit')->pattern(['id' => '\\d+']);
    Route::post('vod/:id', 'admin.Vod/update')->pattern(['id' => '\\d+']);
    Route::post('vod/:id/delete', 'admin.Vod/delete')->pattern(['id' => '\\d+']);
    Route::post('vod/batch', 'admin.Vod/batch');
    Route::post('vod/:id/quick', 'admin.Vod/quick')->pattern(['id' => '\\d+']);
    Route::post('vod/:id/weight', 'admin.Vod/weight')->pattern(['id' => '\\d+']);
    Route::post('vod/:id/scenarios/collect', 'admin.Vod/collectScenarios')->pattern(['id' => '\\d+']);
    Route::get('vod-tools/douban', 'admin.VodTools/douban');
    Route::post('vod-tools/douban/next', 'admin.VodTools/doubanNext');
    Route::get('vod-tools/douban-duplicates', 'admin.VodTools/duplicates');
    Route::get('vod-tools/douban-comments', 'admin.VodTools/comments');
    Route::post('vod-tools/douban-comments/next', 'admin.VodTools/commentsNext');
    Route::get('vod-tools/scenario-collect', 'admin.VodTools/scenarios');
    Route::post('vod-tools/scenario-collect/run', 'admin.VodTools/scenariosRun');
    Route::get('vod/:id/playback', 'admin.Playback/index')->pattern(['id' => '\\d+']);
    Route::post('vod/:id/sources', 'admin.Playback/storeSource')->pattern(['id' => '\\d+']);
    Route::post('vod/:id/sources/:sourceId', 'admin.Playback/updateSource')->pattern(['id' => '\\d+', 'sourceId' => '\\d+']);
    Route::post('vod/:id/sources/:sourceId/delete', 'admin.Playback/deleteSource')->pattern(['id' => '\\d+', 'sourceId' => '\\d+']);
    Route::post('vod/:id/sources/:sourceId/episodes', 'admin.Playback/storeEpisode')->pattern(['id' => '\\d+', 'sourceId' => '\\d+']);
    Route::post('vod/:id/sources/:sourceId/episodes/:episodeId', 'admin.Playback/updateEpisode')->pattern(['id' => '\\d+', 'sourceId' => '\\d+', 'episodeId' => '\\d+']);
    Route::post('vod/:id/sources/:sourceId/episodes/:episodeId/delete', 'admin.Playback/deleteEpisode')->pattern(['id' => '\\d+', 'sourceId' => '\\d+', 'episodeId' => '\\d+']);

    Route::get('scenarios', 'admin.Scenarios/index');
    Route::get('scenarios/create', 'admin.Scenarios/create');
    Route::post('scenarios', 'admin.Scenarios/store');
    Route::get('scenarios/media/:mediaId', 'admin.Scenarios/manage')->pattern(['mediaId' => '\\d+']);
    Route::post('scenarios/media/:mediaId', 'admin.Scenarios/saveMedia')->pattern(['mediaId' => '\\d+']);
    Route::get('scenarios/:id/edit', 'admin.Scenarios/edit')->pattern(['id' => '\\d+']);
    Route::post('scenarios/:id', 'admin.Scenarios/update')->pattern(['id' => '\\d+']);
    Route::post('scenarios/:id/delete', 'admin.Scenarios/delete')->pattern(['id' => '\\d+']);

    Route::get('content/:type', 'admin.Content/index')->pattern(['type' => 'articles|topics|people']);
    Route::get('content/:type/create', 'admin.Content/create')->pattern(['type' => 'articles|topics|people']);
    Route::post('content/:type', 'admin.Content/store')->pattern(['type' => 'articles|topics|people']);
    Route::get('content/:type/:id/edit', 'admin.Content/edit')->pattern(['type' => 'articles|topics|people', 'id' => '\\d+']);
    Route::post('content/:type/:id', 'admin.Content/update')->pattern(['type' => 'articles|topics|people', 'id' => '\\d+']);
    Route::post('content/:type/:id/delete', 'admin.Content/delete')->pattern(['type' => 'articles|topics|people', 'id' => '\\d+']);
    Route::post('content/:type/batch', 'admin.Content/batch')->pattern(['type' => 'articles|topics|people']);

    Route::get('users', 'admin.Users/index');
    Route::get('users/create', 'admin.Users/create');
    Route::post('users', 'admin.Users/store');
    Route::get('users/:id/edit', 'admin.Users/edit')->pattern(['id' => '\\d+']);
    Route::post('users/:id', 'admin.Users/update')->pattern(['id' => '\\d+']);
    Route::post('users/:id/delete', 'admin.Users/delete')->pattern(['id' => '\\d+']);
    Route::post('users/batch', 'admin.Users/batch');
    Route::get('users/:id/logs', 'admin.Users/logs')->pattern(['id' => '\\d+']);
    Route::get('comments', 'admin.Comments/index');
    Route::post('comments/:id/moderate', 'admin.Comments/moderate')->pattern(['id' => '\\d+']);
    Route::post('comments/:id/delete', 'admin.Comments/delete')->pattern(['id' => '\\d+']);
    Route::post('comments/:id/reply', 'admin.Comments/reply')->pattern(['id' => '\\d+']);
    Route::post('comments/batch', 'admin.Comments/batch');
    Route::get('danmaku', 'admin.Danmaku/index');
    Route::post('danmaku/:id/delete', 'admin.Danmaku/delete')->pattern(['id' => '\\d+']);

    Route::get('collections', 'admin.Collections/index');
    Route::get('collections/jobs', 'admin.Collections/jobs');
    Route::get('collections/create', 'admin.Collections/create');
    Route::post('collections', 'admin.Collections/store');
    Route::get('collections/:id/edit', 'admin.Collections/edit')->pattern(['id' => '\\d+']);
    Route::get('collections/:id/resource', 'admin.Collections/resource')->pattern(['id' => '\\d+']);
    Route::post('collections/:id', 'admin.Collections/update')->pattern(['id' => '\\d+']);
    Route::post('collections/:id/mapping', 'admin.Collections/mapping')->pattern(['id' => '\\d+']);
    Route::post('collections/:id/delete', 'admin.Collections/delete')->pattern(['id' => '\\d+']);
    Route::post('collections/:id/queue', 'admin.Collections/queue')->pattern(['id' => '\\d+']);
    Route::post('collections/jobs/:jobId/execute', 'admin.Collections/execute')->pattern(['jobId' => '\\d+']);
    Route::post('collections/jobs/:jobId/retry', 'admin.Collections/retry')->pattern(['jobId' => '\\d+']);
    Route::get('crontab', 'admin.Crontab/index');
    Route::post('crontab', 'admin.Crontab/store');
    Route::post('crontab/:id/toggle', 'admin.Crontab/toggle')->pattern(['id' => '\\d+']);
    Route::post('crontab/:id/delete', 'admin.Crontab/delete')->pattern(['id' => '\\d+']);

    Route::get('operations/:type', 'admin.Operations/index')->pattern(['type' => 'players|slides|links|navigation|ads']);
    Route::get('operations/:type/create', 'admin.Operations/create')->pattern(['type' => 'players|slides|links|navigation|ads']);
    Route::post('operations/:type', 'admin.Operations/store')->pattern(['type' => 'players|slides|links|navigation|ads']);
    Route::get('operations/:type/:id/edit', 'admin.Operations/edit')->pattern(['type' => 'players|slides|links|navigation|ads', 'id' => '\\d+']);
    Route::post('operations/:type/:id', 'admin.Operations/update')->pattern(['type' => 'players|slides|links|navigation|ads', 'id' => '\\d+']);
    Route::post('operations/:type/:id/delete', 'admin.Operations/delete')->pattern(['type' => 'players|slides|links|navigation|ads', 'id' => '\\d+']);
    Route::post('operations/:type/batch', 'admin.Operations/batch')->pattern(['type' => 'players|slides|links|navigation|ads']);

    Route::get('billing', 'admin.Billing/index');
    Route::post('billing/orders/:id', 'admin.Billing/updateOrder')->pattern(['id' => '\\d+']);
    Route::post('billing/cards', 'admin.Billing/createCard');
    Route::post('billing/cards/:id/disable', 'admin.Billing/disableCard')->pattern(['id' => '\\d+']);

    Route::get('system', 'admin.System/index');
    Route::post('system/cache/clear', 'admin.System/clearCache');
    Route::post('system/search/rebuild', 'admin.System/rebuildSearch');
    Route::post('system/jobs/:id/retry', 'admin.System/retryJob')->pattern(['id' => '\\d+']);

    Route::get('tools/cache', 'admin.Tools/cache');
    Route::post('tools/cache/clear', 'admin.Tools/clearToolCache');
    Route::post('tools/search/rebuild', 'admin.Tools/rebuildSearch');
    Route::get('tools/version', 'admin.Tools/version');
    Route::post('tools/version/check', 'admin.Tools/checkVersion');
    Route::get('tools/version/status', 'admin.Updater/status');
    Route::post('tools/version/start', 'admin.Updater/start');
    Route::get('tools/templates', 'admin.Tools/templates');
    Route::post('tools/templates', 'admin.Tools/saveTemplate');
    Route::post('tools/templates/create', 'admin.Tools/createTemplate');
    Route::post('tools/templates/delete', 'admin.Tools/deleteTemplate');
    Route::get('tools/uploads', 'admin.Tools/uploads');
    Route::post('tools/uploads', 'admin.Tools/upload');
    Route::post('tools/uploads/fetch', 'admin.Tools/fetchUpload');
    Route::post('tools/uploads/:name/thumbnail', 'admin.Tools/thumbnailUpload')->pattern(['name' => '[A-Za-z0-9._-]+']);
    Route::post('tools/uploads/:name/delete', 'admin.Tools/deleteUpload')->pattern(['name' => '[A-Za-z0-9._-]+']);
    Route::get('tools/duplicates', 'admin.Tools/duplicates');
    Route::get('tools/batch', 'admin.Tools/batchMaintenance');
    Route::post('tools/batch/playback', 'admin.Tools/deletePlaybackSource');
    Route::post('tools/batch/slugs', 'admin.Tools/generateSlugs');
    Route::post('tools/batch/tags', 'admin.Tools/generateTags');
    Route::get('tools/records', 'admin.Tools/records');
    Route::post('tools/records/history/:id/delete', 'admin.Tools/deleteHistory')->pattern(['id' => '\\d+']);
    Route::post('tools/records/favorites/delete', 'admin.Tools/deleteFavorite');
    Route::post('tools/records/clear', 'admin.Tools/clearRecords');
    Route::get('tools/replace', 'admin.Tools/replace');
    Route::post('tools/replace', 'admin.Tools/runReplace');
    Route::get('tools/static', 'admin.Tools/staticPages');
    Route::post('tools/static/generate', 'admin.Tools/generateStatic');
    Route::get('tools/legacy-upgrade', 'admin.LegacyUpgrade/index');
    Route::post('tools/legacy-upgrade/preflight', 'admin.LegacyUpgrade/preflight');
    Route::post('tools/legacy-upgrade/batch', 'admin.LegacyUpgrade/batch');
    Route::post('tools/legacy-upgrade/finish', 'admin.LegacyUpgrade/finish');

    Route::post('settings/email/test', 'admin.Settings/testEmail');
    Route::post('settings/rewrite/preview', 'admin.Settings/previewRewrite');
    Route::get('settings/:section', 'admin.Settings/index')->pattern(['section' => 'base|rewrite|pay|player|content|cache|collection|files|email|register|comments|weixin']);
    Route::post('settings/:section', 'admin.Settings/update')->pattern(['section' => 'base|rewrite|pay|player|content|cache|collection|files|email|register|comments|weixin']);

    Route::get('tags', 'admin.Tags/index');
    Route::post('tags', 'admin.Tags/store');
    Route::post('tags/:id/delete', 'admin.Tags/delete')->pattern(['id' => '\\d+']);
    Route::post('tags/:id/update', 'admin.Tags/update')->pattern(['id' => '\\d+']);
    Route::post('tags/merge', 'admin.Tags/merge');

    Route::get('administrators', 'admin.Administrators/index');
    Route::get('administrators/create', 'admin.Administrators/create');
    Route::post('administrators', 'admin.Administrators/store');
    Route::get('administrators/:id/edit', 'admin.Administrators/edit')->pattern(['id' => '\\d+']);
    Route::post('administrators/:id', 'admin.Administrators/update')->pattern(['id' => '\\d+']);
    Route::post('administrators/:id/delete', 'admin.Administrators/delete')->pattern(['id' => '\\d+']);

    Route::get('database', 'admin.Database/index');
    Route::post('database/backup', 'admin.Database/backup');
    Route::post('database/check', 'admin.Database/check');
    Route::post('database/repair', 'admin.Database/repair');
    Route::post('database/optimize', 'admin.Database/optimize');
    Route::post('database/backups/:name/restore', 'admin.Database/restore')->pattern(['name' => '[A-Za-z0-9._-]+']);
    Route::post('database/backups/:name/delete', 'admin.Database/delete')->pattern(['name' => '[A-Za-z0-9._-]+']);
    Route::get('database/backups/:name', 'admin.Database/download')->pattern(['name' => '[A-Za-z0-9._-]+']);
})->completeMatch()->middleware([SessionInit::class, app\middleware\AdminGuard::class]);

// FeiFeiCMS 4.3/7.4 custom routes are evaluated only after every normal route.
// Explicit catch-alls keep multi-segment rules reliable with Nginx ?s= compatibility mode.
foreach ([8, 7, 6, 5, 4, 3, 2, 1] as $rewriteDepth) {
    $segments = [];
    $patterns = [];
    for ($rewritePart = 1; $rewritePart <= $rewriteDepth; $rewritePart++) {
        $segments[] = ':u' . $rewritePart;
        $patterns['u' . $rewritePart] = '[^/]+';
    }
    Route::get(implode('/', $segments), 'Rewrite/segments')->pattern($patterns)->middleware(SessionInit::class);
}
// A final miss route also supports custom deployments that pass pathinfo directly.
Route::miss('Rewrite/dispatch', 'GET')->middleware(SessionInit::class);
