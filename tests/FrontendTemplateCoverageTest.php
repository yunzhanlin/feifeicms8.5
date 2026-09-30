<?php
declare(strict_types=1);

namespace tests;

use PHPUnit\Framework\TestCase;

final class FrontendTemplateCoverageTest extends TestCase
{
    public function testMxoneShowcaseCoversPublicFeatureRoutes(): void
    {
        $root = dirname(__DIR__);
        $routes = (string) file_get_contents($root . '/route/app.php');
        foreach (["Route::get('features'", "Route::get('vod/:id/scenarios'", "Route::get('scenario/:id'"] as $route) {
            self::assertStringContainsString($route, $routes);
        }
        foreach (['page/features.html', 'vod/scenarios.html', 'vod/scenario.html'] as $view) {
            self::assertFileExists($root . '/view/mxone/' . $view);
        }
        $showcase = (string) file_get_contents($root . '/view/mxone/page/features.html');
        foreach (['实时数据调用', '分集剧情与评论', '运营组件', '模板调用方法', 'ff_mysql_vod', 'README.md'] as $marker) {
            self::assertStringContainsString($marker, $showcase);
        }
    }

    public function testLegacyFrontendHelpersAndDetailFieldsAreDemonstrated(): void
    {
        $root = dirname(__DIR__);
        $helpers = (string) file_get_contents($root . '/app/common.php');
        foreach (['ff_mysql_scenario', 'ff_mysql_forum', 'ff_mysql_link', 'ff_mysql_ads'] as $function) {
            self::assertStringContainsString('function ' . $function, $helpers);
        }
        $detail = (string) file_get_contents($root . '/view/mxone/vod/detail.html');
        foreach (['vod_douban_id', 'vod_imdb_id', 'data-episode-list', '演职人员', '附件资源', '分集剧情'] as $marker) {
            self::assertStringContainsString($marker, $detail);
        }
        $play = (string) file_get_contents($root . '/view/mxone/vod/play.html');
        foreach (['mx-play-source-tabs', 'data-play-source-panel', 'data-episode-sort-toggle', 'siteAds.vod_play'] as $marker) {
            self::assertStringContainsString($marker, $play);
        }
    }

    public function testReadmeDocumentsRoutesTagsAndAdvertisementSlots(): void
    {
        $guide = (string) file_get_contents(dirname(__DIR__) . '/README.md');
        foreach (['/features', '/vod/{id}/scenarios', '/scenario/{id}', 'ff_play_url', 'ff_mysql_vod', 'ff_mysql_scenario', 'home_top', 'vod_detail', 'vod_play'] as $marker) {
            self::assertStringContainsString($marker, $guide);
        }
    }
}
