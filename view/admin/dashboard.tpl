{include file="admin/layout/header" /}
{if $checkUpdate}<div data-update-notice data-update-token="{$csrf|htmlspecialchars|raw}"></div>{/if}
<section class="section-block dashboard-system legacy-system-home">
    <div class="table-wrap">
        <table class="data-table system-info-table legacy-runtime-table">
            <thead><tr><th colspan="2" class="cell-left"><a class="runtime-detail" href="/health" target="_blank">详细&gt;&gt;</a>系统基本信息</th></tr></thead>
            <tbody>
                <tr><th>技术支持/BUG反馈：</th><td><a href="mailto:{$environment.support|htmlspecialchars|raw}">{$environment.support|htmlspecialchars|raw}</a></td></tr>
                <tr><th>服务器 (IP/端口)：</th><td>{$environment.serverAddress|htmlspecialchars|raw}</td></tr>
                <tr><th>站点安装目录：</th><td>{$environment.installPath|htmlspecialchars|raw}</td></tr>
                <tr><th>服务器操作系统：</th><td>{$environment.serverSoftware|htmlspecialchars|raw}</td></tr>
                <tr><th>PHP版本：</th><td>{$environment.php|htmlspecialchars|raw} <span class="runtime-requirement">&gt;=8.2</span></td></tr>
                <tr><th>脚本解释引擎：</th><td>{$environment.sapi|htmlspecialchars|raw}</td></tr>
                <tr><th>MySQL 数据库支持：</th><td>{$environment.databaseClient|htmlspecialchars|raw} {$environment.database|htmlspecialchars|raw} <span class="runtime-requirement">PHP 8.x 使用 PDO MySQL 驱动</span></td></tr>
                <tr><th>file_get_contents支持：</th><td>{if $environment.fileGetContents}<span class="env-pass">√</span> 默认采集函数（必须）{else /}<span class="env-fail">× 未启用（必须）</span>{/if}</td></tr>
                <tr><th>curl_init支持：</th><td>{if $environment.curl}<span class="env-pass">√</span> 优化采集函数（可选）{else /}<span class="env-fail">× 未启用（可选）</span>{/if}</td></tr>
                <tr><th>mb_strimwidth支持：</th><td>{if $environment.mbStrimwidth}<span class="env-pass">√</span> 字符串截取函数（可选）{else /}<span class="env-fail">× 未启用（可选）</span>{/if}</td></tr>
                <tr><th>openssl 扩展：</th><td>{if $environment.openssl}<span class="env-pass">√</span> 邮件发送需要用到的组件（可选）{else /}<span class="env-fail">× 未启用（可选）</span>{/if}</td></tr>
                <tr><th>允许上传文件最大值：</th><td>{$environment.uploadLimit|htmlspecialchars|raw}</td></tr>
                <tr><th>GD图形处理扩展库版本：</th><td>{$environment.gd|htmlspecialchars|raw} <span class="runtime-requirement">&gt;=2.0.34</span></td></tr>
                <tr><th>程序最新版本检测：</th><td>{$environment.version|htmlspecialchars|raw}</td></tr>
            </tbody>
        </table>
    </div>
</section>
{include file="admin/layout/footer" /}
