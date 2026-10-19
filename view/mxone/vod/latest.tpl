{assign name="pageTitle" value="最近更新 - MXOne" /}
{assign name="pageActive" value="" /}
{include file="common/head" /}
{include file="common/header" /}
<main class="mx-wrap mx-main">
    <section>
        <div class="mx-title">
            <h1>最近更新</h1>
            <span>按更新时间排序 · 共 {$total} 部</span>
        </div>
        <div class="mx-grid">
            {volist name="vods" id="item"}{include file="module/vod_card_year" /}{/volist}
        </div>
        <div class="mx-pager">{$pager|raw}</div>
    </section>
</main>
{assign name="footerText" value="MXOne · FeiFeiCMS 最近更新" /}
{include file="common/footer" /}
