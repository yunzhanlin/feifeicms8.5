<?php
declare(strict_types=1);
namespace app\service;

/** Program-owned admin content; upgrading never replaces site-owned templates or CSS. */
final class DoubanCommentsPage
{
    public static function render(array $data): string
    {
        $e = static fn(mixed $v): string => htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $token = $e($data['csrf']);
        $html = '<div class="title"><div class="tabs"><a class="active" href="/admin/vod-tools/douban-comments">豆瓣评论采集</a><a href="/admin/vod-tools/douban">豆瓣资料补全</a><a href="/admin/comments?target_type=media">影片评论管理</a></div></div>';
        if ($data['notice'] !== '') $html .= '<div class="notice success" role="status">'.$e($data['notice']).'</div>';
        if ($data['error'] !== '') $html .= '<div class="notice danger" role="alert">'.$e($data['error']).'</div>';
        if (!$data['ready']) $html .= '<div class="notice danger" role="alert">评论表需要升级。请先执行 php think feifei:schema:upgrade，再采集入库。</div>';
        $html .= '<div class="table-wrap"><table class="data-table legacy-tool-table"><tbody><tr><th>可采集影片</th><td>'.(int)$data['total'].' 部（同豆瓣 ID 仅保留最新影片）</td></tr><tr><th>已关联豆瓣短评</th><td>'.(int)$data['imported'].' 条</td></tr><tr><th>豆瓣 Cookie</th><td>'.($data['cookieConfigured']?'已配置':'未配置').' · <a href="/admin/settings/base">前往全局配置</a></td></tr></tbody></table></div>';
        $html .= '<div class="title"><div class="tabs"><span>单片预览与入库</span></div></div><form method="post" action="/admin/vod-tools/douban-comments/next" class="legacy-tool-control"><input type="hidden" name="_token" value="'.$token.'"><input type="hidden" name="action" value="preview"><label>影片 ID：<input name="media_id" type="number" min="1" required value="'.((int)$data['mediaId']?:'').'"></label><label>排序：<select name="sort"><option value="new_score">热门评论</option><option value="time">最新评论（需豆瓣授权）</option></select></label><label>页码：<input name="page" type="number" min="1" max="101" value="1" class="short-input"></label><button type="submit">获取预览（每页20条）</button></form>';
        if ($data['preview'] !== null) {
            $p = $data['preview'];
            $html .= '<div class="title"><div class="tabs"><span>预览：#'.(int)$p['media_id'].' '.$e($p['title']).' · 豆瓣 '.$e($p['subject_id']).'</span></div></div><p>时间按豆瓣原始北京时间显示。预览不会入库；勾选需要的评论后确认。</p>';
            $html .= '<form method="post" action="/admin/vod-tools/douban-comments/next"><input type="hidden" name="_token" value="'.$token.'"><input type="hidden" name="action" value="import"><input type="hidden" name="preview_token" value="'.$e($p['token']).'"><div class="table-wrap"><table class="data-table"><thead><tr><th>选择</th><th>头像</th><th>原用户名</th><th>原评论时间</th><th>评论内容</th></tr></thead><tbody>';
            foreach ($p['comments'] as $index=>$c) {
                $valid = $c['author_name'] !== '' && $c['created_at'] !== null;
                $html .= '<tr><td><input type="checkbox" name="selected[]" value="'.(int)$index.'" '.($valid?'checked':'disabled').'></td><td>';
                $html .= $c['avatar_url'] !== '' ? '<img src="'.$e($c['display_avatar_url'] ?? $c['avatar_url']).'" alt="头像" width="36" height="36" loading="lazy" referrerpolicy="no-referrer">' : '无头像';
                $html .= '</td><td>'.$e($c['author_name']?:'原页缺少作者').'</td><td>'.$e($c['published_at']?:'原页缺少时间，跳过入库').'</td><td>'.nl2br($e($c['content'])).'</td></tr>';
            }
            if ($p['comments'] === []) $html .= '<tr><td colspan="5">本页没有可采集的短评。</td></tr>';
            $html .= '</tbody></table></div><div class="form-actions"><button type="submit" '.(!$data['ready']||$p['comments']===[]?'disabled':'').'>将所选评论入库到本片</button></div></form>';
        }
        $html .= '<div class="title"><div class="tabs"><span>查找影片 ID</span></div></div><form method="get" action="/admin/vod-tools/douban-comments" class="legacy-tool-control"><label>片名：<input name="q" value="'.$e($data['keyword']).'" maxlength="80"></label><button type="submit">查找</button></form><div class="table-wrap"><table class="data-table"><thead><tr><th>影片 ID</th><th>影片</th><th>豆瓣 ID</th><th>操作</th></tr></thead><tbody>';
        foreach ($data['movies'] as $movie) $html .= '<tr><td>'.(int)$movie['id'].'</td><td>'.$e($movie['title']).'</td><td>'.$e($movie['douban_id']).'</td><td><a href="/admin/vod-tools/douban-comments?media_id='.(int)$movie['id'].'">选择本片</a> · <a href="/admin/vod/'.(int)$movie['id'].'/edit">编辑影片</a></td></tr>';
        if ($data['movies'] === []) $html .= '<tr><td colspan="4">没有匹配影片，请先在影片编辑页填写正确的豆瓣 ID。</td></tr>';
        $html .= '</tbody></table></div><div class="title"><div class="tabs"><span>批量采集热门短评</span></div></div><form class="legacy-tool-control" data-vod-tool-batch data-endpoint="/admin/vod-tools/douban-comments/next" data-storage-key="ff-douban-comments-attribution-v1" data-progress-owner="'.(int)$data['adminId'].'"><input type="hidden" name="_token" value="'.$token.'"><label>请求间隔：<input class="short-input" name="delay" type="number" min="1500" max="60000" step="100" value="4000"> 毫秒</label><button type="button" data-tool-start '.(!$data['ready']?'disabled':'').'>开始采集</button><button type="button" data-tool-pause disabled>暂停</button><button type="button" data-tool-reset>重置进度</button><span class="legacy-tool-state" data-tool-state>尚未开始</span></form>';
        $html .= '<div class="legacy-tool-help"><p>批量模式逐片获取热门20条短评，按豆瓣评论 ID 和本站影片 ID 去重；同一豆瓣 ID 对应多部本站影片时，只入库到影片 ID 最大的最新一部，旧影片跳过。单片预览也会自动定位到最新影片。</p><p>原用户名、原评论时间和头像一并保存，头像下载到本站，不创建或冒充站内会员。原页缺少作者或时间的记录不会伪造；重复采集会补齐旧匿名数据，保留审核、置顶和归档状态。</p><p>遇到豆瓣安全验证或访问频率限制时停止批量请求，更新 Cookie 或稍后重试。预览有效期15分钟。</p></div><div class="legacy-tool-log" data-tool-log><p>执行日志将显示在这里。</p></div>';
        return $html;
    }
}
