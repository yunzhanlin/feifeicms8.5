<!doctype html>
<html lang="zh-CN" data-mx-record-limit="{$frontendUi.recordLimit|default=0}" data-mx-slide-interval="{$frontendUi.slideInterval|default=3000}" data-mx-episode-limit="{$frontendUi.episodeLimit|default=0}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {notempty name="csrf"}<meta name="csrf-token" content="{$csrf|htmlentities}">{/notempty}
    <title>{notempty name="seo.title"}{$seo.title|htmlentities}{else /}{notempty name="pageTitle"}{$pageTitle}{else /}MXOne{/notempty}{/notempty}</title>
    {notempty name="seo.keywords"}<meta name="keywords" content="{$seo.keywords|htmlentities}">{/notempty}
    {notempty name="seo.description"}<meta name="description" content="{$seo.description|htmlentities}">{/notempty}
    <link rel="icon" href="{:ff_theme_asset('images/logo.png')}">
    <link rel="stylesheet" href="{:ff_theme_asset('css/mxone.css')}">
    <link rel="stylesheet" href="{:ff_theme_asset('css/style.css')}">
    <link rel="stylesheet" href="{:ff_theme_asset('css/swiper-bundle.min.css')}">
    <link rel="stylesheet" href="{:ff_theme_asset('css/system.css')}">
    {notempty name="playerJs"}
    <script src="{:ff_asset_url('shared', 'player/vendor/artplayer.js')}" defer></script>
    <script src="{:ff_asset_url('shared', 'player/vendor/hls.min.js')}" defer></script>
    <script src="{:ff_asset_url('shared', 'player/vendor/artplayer-plugin-danmuku.js')}" defer></script>
    <script src="{:ff_asset_url('shared', 'player/player.js')}" defer></script>
    {/notempty}
    <script src="{:ff_theme_asset('js/app.js')}" defer></script>
</head>
<body{notempty name="bodyClass"} class="{$bodyClass}"{/notempty}>
