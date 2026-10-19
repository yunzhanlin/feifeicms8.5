{assign name="pageTitle" value="热播推荐 - MXOne" /}
{assign name="pageActive" value="" /}
{include file="common/head" /}
{include file="common/header" /}
<main class="mx-wrap mx-main">
    <section>
        <div class="mx-title">
            <h1>热播推荐</h1>
            <span>按人气排序 · 共 {$total} 部</span>
        </div>
        <div class="mx-grid">
            {volist name="vods" id="item"}{include file="module/vod_card" /}{/volist}
        </div>
        <div class="mx-pager">{$pager|raw}</div>
    </section>
</main>
{assign name="footerText" value="MXOne · FeiFeiCMS 热播推荐" /}
{include file="common/footer" /}
