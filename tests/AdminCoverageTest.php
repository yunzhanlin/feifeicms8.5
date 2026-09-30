<?php
declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;

final class AdminCoverageTest extends TestCase
{
    public function testRequiredAdminModulesHaveRoutesAndViews(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        foreach (['categories', 'playback', 'scenarios', 'content/:type', 'users', 'comments', 'collections', 'operations/:type', 'billing', 'system', 'settings', 'tags', 'administrators', 'database', 'tools/cache', 'tools/version', 'tools/uploads', 'tools/templates', 'tools/duplicates', 'tools/batch', 'tools/replace', 'tools/records', 'tools/static', 'crontab', 'vod-tools/douban', 'vod-tools/douban-duplicates', 'vod-tools/douban-comments', 'vod-tools/scenario-collect'] as $segment) {
            self::assertStringContainsString($segment, $routes);
        }
        foreach (["database/check", "database/repair", "database/backups/:name/restore", "database/backups/:name/delete", "tools/records/history/:id/delete", "tools/records/favorites/delete", "tools/records/clear"] as $databaseRoute) {
            self::assertStringContainsString($databaseRoute, $routes);
        }

        foreach ([
            'categories/index.html', 'categories/edit.html', 'vod/playback.html', 'scenarios/index.html', 'scenarios/edit.html', 'scenarios/manage.html',
            'content/index.html', 'content/edit.html', 'users/index.html', 'users/edit.html',
            'comments/index.html', 'collections/index.html', 'collections/edit.html', 'collections/jobs.html',
            'operations/index.html', 'operations/edit.html', 'billing/index.html', 'system/index.html',
            'settings/index.html', 'tags/index.html', 'administrators/index.html', 'administrators/edit.html', 'database/index.html',
            'tools/cache.html', 'tools/version.html', 'tools/uploads.html', 'tools/templates.html', 'tools/duplicates.html', 'tools/batch.html', 'tools/replace.html', 'tools/records.html', 'tools/static.html', 'crontab/index.html',
            'vod_tools/douban.html', 'vod_tools/duplicates.html', 'vod_tools/comments.html', 'vod_tools/scenarios.html',
        ] as $view) {
            self::assertFileExists(dirname(__DIR__) . '/view/admin/' . $view);
        }
    }

    public function testAdminTemplatesDoNotDependOnBlockedInlineJavaScript(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/view/admin'));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'html') {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/\son(?:click|submit)=/i', $html, $file->getPathname());
        }
    }

    public function testAdminKeepsClassicFeifeiStructureAndResponsiveStates(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/view/admin/layout/header.html');
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.css');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');

        foreach (['admin-topbar', 'admin-tabs', 'admin-sidebar', 'admin-side-menu', 'admin-content'] as $class) {
            self::assertStringContainsString($class, $header);
        }
        foreach (['data-admin-tab="vod"', 'data-admin-menu="vod"', 'data-admin-tab="scenarios"', 'data-admin-menu="scenarios"', 'data-admin-tab="tags"', 'data-admin-tab="database"', 'top_logo.gif', 'href="#admin-menu"'] as $marker) {
            self::assertStringContainsString($marker, $header);
        }
        foreach (['待复查', '豆瓣资料补全', '豆瓣ID查重', '豆瓣评论采集', '分集剧情补全', '已锁定视频', '有分集剧情', '有豆瓣编号', '需支付影币', '可试看影片', '有系列影片'] as $label) {
            self::assertStringContainsString($label, $header);
        }
        foreach (['/admin/vod-tools/douban', '/admin/vod-tools/douban-duplicates', '/admin/vod-tools/douban-comments', '/admin/vod-tools/scenario-collect'] as $link) {
            self::assertStringContainsString($link, $header);
        }
        foreach (['top_bg.gif', 'bg2.gif', 'button_bg.gif', '.legacy-tabs', '.legacy-form-row', ':focus-visible', '@media (max-width: 760px)', '.empty-row td'] as $marker) {
            self::assertStringContainsString($marker, $css);
        }
        self::assertStringContainsString('scrollbar-gutter: stable', $css);
        self::assertStringContainsString('.editor-form { display: flow-root;', $css);
        self::assertStringContainsString('overflow: visible', $css);
        self::assertStringContainsString('/static/admin.css?v=40', $header);
        self::assertStringContainsString('/static/admin.js?v=32', $header);
        self::assertStringContainsString("cell.textContent = '暂无数据'", $script);
        self::assertStringContainsString("已发布", $script);
        self::assertStringContainsString('data-editor-tabs', (string) file_get_contents(dirname(__DIR__) . '/view/admin/vod/edit.html'));
        self::assertFileExists(dirname(__DIR__) . '/public/static/admin-legacy/top_logo.gif');

        $categories = (string) file_get_contents(dirname(__DIR__) . '/view/admin/categories/index.html');
        foreach (['legacy-category-table', 'category-name-heading', 'category-name-cell', 'category-tree-indent'] as $marker) {
            self::assertStringContainsString($marker, $categories);
        }
        self::assertStringContainsString('.legacy-category-table td.category-name-cell', $css);
        self::assertStringContainsString('text-align: left !important', $css);
    }

    public function testCollectionConsoleKeepsTheFeifei74ListStructure(): void
    {
        $list = (string) file_get_contents(dirname(__DIR__) . '/view/admin/collections/index.html');
        foreach (['API资源站（{$resourceLabel}）列表', '添加{$resourceLabel}资源库', '分类转换', '剧情采集', '采集当天', '采集本周', '采集所有', '修改', '删除', '[新增显示]', '[新增隐藏]'] as $label) {
            self::assertStringContainsString($label, $list);
        }
        foreach (['资源库管理', '资源库名称', '资源库地址', '最近采集记录', '类型</th>', '状态</th>'] as $modernLabel) {
            self::assertStringNotContainsString($modernLabel, $list);
        }
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertStringContainsString("Route::get('collections/jobs', 'admin.Collections/jobs')", $routes);
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.css');
        self::assertStringContainsString('.legacy-collection-table', $css);
        $resource = (string) file_get_contents(dirname(__DIR__) . '/view/admin/collections/resource.html');
        foreach (['分类转换', '搜索与操作', '分类、片名、状态', '个性采集', 'data-invert-checks', 'name="partial" value="1"'] as $marker) {
            self::assertStringContainsString($marker, $resource);
        }
        self::assertStringContainsString("post('partial', '') === '1'", (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Collections.php'));
        self::assertStringContainsString("['t', 'h', 'wd', 'ids', 'play']", (string) file_get_contents(dirname(__DIR__) . '/app/service/CollectionPayloadNormalizer.php'));
        $sourceForm = (string) file_get_contents(dirname(__DIR__) . '/view/admin/collections/edit.html');
        foreach (['name="resource_type" value="scenario"', '关联视频资源库', 'name="media_source_id"', '视频新增数据', '新增并显示', '新增但隐藏（待审核）', '只更新，不新增', 'name="new_data_policy"'] as $marker) {
            self::assertStringContainsString($marker, $sourceForm);
        }
        self::assertStringContainsString('/admin/collections?type=scenario', (string) file_get_contents(dirname(__DIR__) . '/view/admin/layout/header.html'));
        self::assertStringContainsString('data-collection-resource-type', (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js'));
        self::assertStringContainsString("newDataPolicy === 'update_only'", (string) file_get_contents(dirname(__DIR__) . '/app/service/CollectionRunner.php'));
        self::assertStringContainsString('new_data_policy VARCHAR(20)', (string) file_get_contents(dirname(__DIR__) . '/database/schema.sql'));
    }

    public function testVideoEditorUsesStandaloneScenarioModuleAndFirstClassExternalIds(): void
    {
        $editor = (string) file_get_contents(dirname(__DIR__) . '/view/admin/vod/edit.html');
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');
        self::assertStringNotContainsString('name="summary"', $editor);
        self::assertStringNotContainsString('name="scenario"', $editor);
        self::assertStringContainsString('name="douban_id"', $editor);
        self::assertStringContainsString('name="imdb_id"', $editor);
        self::assertStringContainsString('>免费观看</option>', $editor);
        self::assertStringContainsString('type="text" name="release_date"', $editor);
        self::assertStringNotContainsString('type="date" name="release_date"', $editor);
        self::assertStringContainsString('name="refresh_updated_at" value="1" aria-label=', $editor);
        self::assertStringNotContainsString('name="refresh_updated_at" value="1" checked', $editor);
        foreach (['data-douban-fetch', 'data-platform-import', 'data-vod-choice', 'data-vod-upload', 'name="source_ref"', 'name="episode_label"'] as $marker) {
            self::assertStringContainsString($marker, $editor);
        }
        self::assertStringContainsString('vod/metadata/douban', $routes);
        self::assertStringContainsString('vod/upload', $routes);
        self::assertStringContainsString("requestJson('/admin/vod/metadata/douban'", $script);
        self::assertStringContainsString("requestJson('/admin/vod/upload'", $script);
        self::assertStringContainsString('/admin/scenarios/media/{$media.id}', $editor);
    }

    public function testScenarioManagementGroupsEpisodesByMediaAndSupportsOnePageEditing(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $list = (string) file_get_contents(dirname(__DIR__) . '/view/admin/scenarios/index.html');
        $manage = (string) file_get_contents(dirname(__DIR__) . '/view/admin/scenarios/manage.html');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Scenarios.php');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');

        foreach (["get('scenarios/media/:mediaId'", "post('scenarios/media/:mediaId'"] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach (['剧情集数', '集数范围', '管理全部剧情', 'scenario-media-list'] as $marker) {
            self::assertStringContainsString($marker, $list);
        }
        foreach (['data-scenario-batch-form', 'data-scenario-row', '添加一集', '全部显示', '全部隐藏', '保存全部剧情'] as $marker) {
            self::assertStringContainsString($marker, $manage);
        }
        foreach (['scenario.batch_update', 'scenarioRowsPayload', '1000000 + $id'] as $marker) {
            self::assertStringContainsString($marker, $controller);
        }
        self::assertStringContainsString("document.querySelectorAll('[data-scenario-batch-form]')", $script);
    }

    public function testSearchIndexCanBeRebuiltFromClassicCacheManagement(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $cache = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/cache.html');
        $manager = (string) file_get_contents(dirname(__DIR__) . '/app/service/search/SearchManager.php');
        $indexer = (string) file_get_contents(dirname(__DIR__) . '/app/service/SearchIndexer.php');
        self::assertStringContainsString("post('tools/search/rebuild'", $routes);
        foreach (['前台搜索索引', '已审核视频', '索引文档', '立即全量同步'] as $label) {
            self::assertStringContainsString($label, $cache);
        }
        self::assertStringContainsString('$fallback->total > 0 ? $fallback : $result', $manager);
        self::assertStringContainsString('array_values($rows->toArray())', $indexer);
    }

    public function testLegacyTemplateContractRemainsAvailable(): void
    {
        $helpers = (string) file_get_contents(dirname(__DIR__) . '/app/common.php');
        foreach ([
            'ff_url', 'ff_url_read_vod', 'ff_url_vod_read', 'ff_url_play', 'ff_url_vod_play',
            'ff_mysql_list', 'ff_mysql_vod', 'ff_mysql_news', 'ff_mysql_special',
            'ff_mysql_star', 'ff_mysql_role', 'ff_mysql_tags', 'ff_mysql_slide', 'ff_mysql_nav',
        ] as $function) {
            self::assertStringContainsString("function {$function}", $helpers);
        }
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        self::assertStringContainsString("Route::get(implode('/', \$segments), 'Rewrite/segments')", $routes);
        self::assertStringContainsString("Route::miss('Rewrite/dispatch', 'GET')", $routes);
        self::assertFileExists(dirname(__DIR__) . '/app/controller/Rewrite.php');
    }

    public function testClassicToolMenuIsCompleteAndTemplateSourceIsEncodedExactlyOnce(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/view/admin/layout/header.html');
        foreach (['缓存管理', '版本升级', '附件管理', '模板管理', '同名检测', '批量维护', '静态生成', '计划任务'] as $label) {
            self::assertStringContainsString($label, $header);
        }
        foreach (['访客统计', '访问明细', '蜘蛛统计', '疑似刷量'] as $removedLabel) {
            self::assertStringNotContainsString($removedLabel, $header);
        }

        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');
        self::assertStringContainsString("path.startsWith('/admin/tools')", $script);
        self::assertStringContainsString("path.startsWith('/admin/crontab')", $script);

        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Tools.php');
        $template = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/templates.html');
        self::assertStringContainsString("'editorContent' => htmlspecialchars(", $controller);
        self::assertStringContainsString('{$editorContent|raw}', $template);
        self::assertStringNotContainsString('{$content|htmlspecialchars}', $template);
        foreach (['tools/templates/create', 'tools/templates/delete'] as $route) self::assertStringContainsString($route, (string) file_get_contents(dirname(__DIR__) . '/route/app.php'));
        foreach (['网站模板管理', '文件夹名/文件名', '文件描述', '下级目录', '模板编辑', '删除'] as $label) self::assertStringContainsString($label, $template);
        foreach (['templateDirectoryPath', 'templateDescription', 'templateEditableExtensions', 'formatFileSize'] as $marker) self::assertStringContainsString($marker, $controller);
        self::assertStringContainsString('array_keys($this->themes->options())', $controller);
        self::assertStringContainsString('The top level is a theme selector', $controller);
    }

    public function testClassicMaintenanceActionsAreBackedByRealWriteRoutes(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $tools = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Tools.php');
        $database = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Database.php');
        $records = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/records.html');
        $replace = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/replace.html');

        foreach (['deleteHistory', 'deleteFavorite', 'clearRecords', 'runReplace'] as $action) {
            self::assertStringContainsString('function ' . $action, $tools);
        }
        foreach (['function check', 'function repair', 'CHECK TABLE', 'REPAIR TABLE', 'ANALYZE TABLE'] as $marker) {
            self::assertStringContainsString($marker, $database);
        }
        foreach (['tools/records/history/:id/delete', 'tools/records/favorites/delete', 'tools/records/clear', 'tools/replace', 'database/check', 'database/repair'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        self::assertStringContainsString('data-confirm="确定清空全部播放记录', $records);
        self::assertStringContainsString('name="preview" value="1"', $replace);
        self::assertStringContainsString('name="preview" value="0"', $replace);
    }

    public function testMailTestAndStaticGenerationUseRealBackendServices(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $settings = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Settings.php');
        $settingsView = (string) file_get_contents(dirname(__DIR__) . '/view/admin/settings/index.html');
        $tools = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Tools.php');
        $staticView = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/static.html');

        self::assertFileExists(dirname(__DIR__) . '/app/service/SmtpMailer.php');
        self::assertStringContainsString('settings/email/test', $routes);
        self::assertStringContainsString('function testEmail', $settings);
        self::assertStringContainsString('sendTest(', $settings);
        self::assertStringContainsString('发送测试邮件', $settingsView);
        foreach (['renderStaticTarget', 'app\\controller\\Index::class', 'app\\controller\\Category::class', 'app\\controller\\Vod::class', 'app\\controller\\News::class', 'app\\controller\\Special::class', "'categories' => ['ffx_categories'", "'articles' => ['ffx_articles'", "'topics' => ['ffx_topics'"] as $marker) {
            self::assertStringContainsString($marker, $tools);
        }
        foreach (['value="feeds"', 'value="home"', 'value="categories"', 'value="media"', 'value="articles"', 'value="topics"'] as $scope) {
            self::assertStringContainsString($scope, $staticView);
        }
    }

    public function testTemplateAndAttachmentToolsHaveRealMutationActions(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $tools = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Tools.php');
        $uploads = (string) file_get_contents(dirname(__DIR__) . '/view/admin/tools/uploads.html');
        foreach (['tools/templates/create', 'tools/templates/delete', 'tools/uploads/fetch', 'tools/uploads/:name/thumbnail'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach (['function createTemplate', 'function deleteTemplate', 'function fetchUpload', 'function thumbnailUpload', 'SafeRemoteUrl', 'CURLOPT_RESOLVE', "'progress' =>"] as $marker) {
            self::assertStringContainsString($marker, $tools);
        }
        self::assertStringContainsString('下载远程图片', $uploads);
        self::assertStringContainsString('生成缩略图', $uploads);
    }

    public function testClassicSystemHomeAndConfigurationMenuStayAligned(): void
    {
        $header = (string) file_get_contents(dirname(__DIR__) . '/view/admin/layout/header.html');
        $dashboard = (string) file_get_contents(dirname(__DIR__) . '/view/admin/dashboard.html');
        $settings = (string) file_get_contents(dirname(__DIR__) . '/view/admin/settings/index.html');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');

        foreach (['全局配置', 'URL优化', '付费点播', '播放来源', '独立播放器', '内容设置', '缓存设置', '采集设置', '附件设置', '邮件设置', '注册设置', '评论设置', '微信设置', '运行环境'] as $label) {
            self::assertStringContainsString($label, $header);
        }
        foreach (['技术支持/BUG反馈', '服务器 (IP/端口)', '站点安装目录', '服务器操作系统', 'PHP版本', '脚本解释引擎', 'MySQL 数据库支持', 'file_get_contents支持', 'curl_init支持', 'mb_strimwidth支持', 'openssl 扩展', '允许上传文件最大值', 'GD图形处理扩展库版本', '程序最新版本检测'] as $label) {
            self::assertStringContainsString($label, $dashboard);
        }
        self::assertStringContainsString('href="/admin" data-admin-tab="system"', $header);
        self::assertStringContainsString("path === '/admin' ? 'system' : 'home'", $script);
        self::assertStringContainsString('legacy-settings-form', $settings);
        foreach (['data-settings-form', 'legacy-settings-group-tabs', 'role="tabpanel"', 'legacy-setting-label', 'setting-required', 'setting-readonly'] as $marker) {
            self::assertStringContainsString($marker, $settings);
        }
        foreach (['基本配置', '扩展配置', 'SEO优化', '网站主模板', '移动端模板'] as $label) {
            self::assertStringContainsString($label, (string) file_get_contents(dirname(__DIR__) . '/config/admin_settings.php'));
        }
        foreach (['data-setting-visible-when', 'ArrowLeft', 'aria-selected'] as $marker) {
            self::assertStringContainsString($marker, $script);
        }
        self::assertFileExists(dirname(__DIR__) . '/app/service/ThemeRegistry.php');
        self::assertStringContainsString('ff_theme_view', (string) file_get_contents(dirname(__DIR__) . '/app/common.php'));
        self::assertStringNotContainsString('<div class="tabs">{foreach $sections', $settings);
        foreach (['网站路径后缀', '伪静态重写功能', 'URL自定义开关', 'URL自定义规则', '(:letternum)', '规则验证', '仅验证，不保存', '规则编译预览'] as $label) {
            self::assertStringContainsString($label, $settings);
        }
        self::assertStringNotContainsString('metric-grid', $dashboard);
        self::assertStringNotContainsString('最近采集任务', $dashboard);
    }

    public function testVideoListMatchesFeifei74DataColumnsAndRealBatchActions(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/view/admin/vod/index.html');
        $routes = (string) file_get_contents(dirname(__DIR__) . '/route/app.php');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controller/admin/Vod.php');
        $script = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.js');
        $css = (string) file_get_contents(dirname(__DIR__) . '/public/static/admin.css');

        foreach (['视频名称 服务器组', '权重', '人气', '年代', '时间', '豆瓣', '相关操作'] as $heading) {
            self::assertStringContainsString($heading, $view);
        }
        foreach (['全选', '反选', '取消审核', '批量审核', '批量获取剧情', '批量删除', '批量移动', '批量打开新窗口', '批量查询', '设置系列', '合并影片'] as $action) {
            self::assertStringContainsString($action, $view);
        }
        foreach (['剧情获取', '剧情刷新', '评论获取', '编辑', '隐藏', '显示', '解锁', '锁定', '删除'] as $rowAction) {
            self::assertStringContainsString($rowAction, $view);
        }
        foreach (['vod/:id/quick', 'vod/:id/weight', 'vod/:id/scenarios/collect'] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach (['function batch', 'function quick', 'function weight', 'function collectScenarios', "'move'", "'series'", "'merge'", "'lock'", "'unlock'"] as $marker) {
            self::assertStringContainsString($marker, $controller);
        }
        foreach (['data-vod-list-form', 'data-vod-submit', 'data-vod-scenario-batch', 'data-vod-comment', 'data-vod-weight', 'data-vod-page-jump'] as $marker) {
            self::assertStringContainsString($marker, $script . $view);
        }
        foreach (['.legacy-vod-list', '.vod-name-cell', '.vod-stars', '.vod-row-actions', '.legacy-vod-page-cell', '.legacy-vod-batch-cell'] as $selector) {
            self::assertStringContainsString($selector, $css);
        }
        self::assertFileExists(dirname(__DIR__) . '/app/service/MediaMerge.php');
        $merge = (string) file_get_contents(dirname(__DIR__) . '/app/service/MediaMerge.php');
        foreach (['mergePlayback', 'mergeScenarios', 'mergeRelations', "status'] === 'published'", "'status' => 'archived'"] as $marker) {
            self::assertStringContainsString($marker, $merge);
        }
    }
}
