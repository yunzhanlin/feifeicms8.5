{assign name="pageTitle" value="专题 - MXOne" /}
{assign name="pageActive" value="special" /}
{include file="common/head" /}
{include file="common/header" /}
<main class="mx-wrap mx-main">
    <section class="mx-block">
        <div class="mx-title">
            <h1>影視专题</h1>
            <span>Collections</span>
        </div>
        <div class="mx-special-grid">
            {volist name="specials" id="item"}
            {include file="module/special_grid_card" /}
            {/volist}
        </div>
        <div class="mx-pager">{$pager|raw}</div>
    </section>
</main>
{assign name="footerText" value="MXOne · 专题" /}
{include file="common/footer" /}
