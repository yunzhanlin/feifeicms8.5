<?php
declare(strict_types=1);

/*
 * FeiFeiCMS 7.x compatible setting catalogue.
 *
 * Field format:
 *   label, type, default, options, help, public, secret
 */
$field = static function (
    string $label,
    string $type = 'text',
    string|int $default = '',
    array $options = [],
    string $help = '',
    bool $public = false,
    bool $secret = false,
    bool $required = false,
    string $visibleWhen = ''
): array {
    return compact('label', 'type', 'default', 'options', 'help', 'public', 'secret', 'required', 'visibleWhen');
};

$rewriteRoutes = <<<'RULES'
list-ename-id-(:letter)===(:letter)/p1
list-ename-id-(:letter)-p-(:num)===(:letter)/p(:num)
news-read-id-(:num)-p-(:num)===zixun/(:num)-(:num)
news-read-id-(:num)===zixun/(:num)
scenario-read-id-(:num)-pid-(:num)===juqing/(:num)-(:num)
scenario-read-id-(:num)===juqing/(:num)
special-read-id-(:num)===zhuanti/(:num)
star-read-id-(:num)===mingxing/(:num)
role-read-id-(:num)===juese/(:num)
guestbook-read-id-(:num)===liuyan/(:num)
forum-read-id-(:num)===forum/(:num)
user-index-id-(:num)===user/(:num)
vod-juqing-id-(:num)===fenji/(:num)
vod-taici-id-(:num)===taici/(:num)
vod-zixun-id-(:num)===yingxun/(:num)
vod-yanyuan-id-(:num)===yanyuan/(:num)
vod-pingfen-id-(:num)===pingfen/(:num)
vod-kandian-id-(:num)===kandian/(:num)
vod-shoubo-id-(:num)===shoubo/(:num)
vod-jieju-id-(:num)===jieju/(:num)
vod-rss-id-(:num)===rss/(:num)
vod-yugao-id-(:num)===yugao/(:num)
vod-xiazai-id-(:num)===xiazai/(:num)
vod-forum-id-(:num)-p-(:num)===yingping(:num)-(:num)
vod-forum-id-(:num)===yingping/(:num)
vod-read-dir-(:letter)-id-(:letternum)===(:letter)/(:letternum)
vod-play-dir-(:letter)-id-(:num)-sid-(:num)-pid-(:num)===(:letter)/(:num)/(:num)-(:num)
RULES;

return [
    'base' => [
        'label' => '全局配置',
        'groups' => [
            '基本配置' => [
                'site_name' => $field('网站名称', 'text', '飞飞影视', help: '请填写贵站名字。', public: true, required: true),
                'site_status' => $field('网站状态', 'boolean', '1', help: '关闭后前台显示维护提示，后台仍可访问。', public: true),
                'site_notice' => $field('关闭提示', 'textarea', '网站维护中，请稍后访问。', public: true, visibleWhen: 'site_status:0'),
                'html_minify' => $field('前台 HTML 压缩', 'boolean', '0', help: '压缩 HTML 空白字符，脚本、样式和 JSON 接口不会处理。'),
                'site_path' => $field('安装路径', 'text', '/', help: "网站安装路径，一般不需要修改，结尾必需加斜杆 '/'。", required: true),
                'db_name' => $field('MYSQL数据库', 'readonly', (string) env('DB_DATABASE', 'feifeicms'), help: '已存在的MYSQL数据库。', required: true),
                'site_domain' => $field('网站主域名', 'text', '', required: true),
                'default_theme' => $field('网站主模板', 'select', 'mxone'),
                'site_domain_m' => $field('移动端域名', 'text', '', help: '留空则不自动跳转到子域名，只支持如 m.example.com 这样的二级子域名。'),
                'default_theme_m' => $field('移动端模板', 'select', 'mxone', help: '移动端子域名开启时用于访问移动端子域名时独立使用。'),
                'url_html' => $field('网站运行方案', 'select', '0', ['0' => '全站动态访问模式', '1' => '核心页面生成静态网页'], '当您的网站流量非常大的时候，建议选择将一些页面生成静态，需要站长手动操作生成相关网页。'),
                'html_file_suffix' => $field('静态网页后缀名', 'select', '.html', ['.html' => '.html', '.htm' => '.htm', '.shtml' => '.shtml', '.shtm' => '.shtm'], visibleWhen: 'url_html:1'),
                'url_list' => $field('分类列表页保存路径', 'text', 'list/{id}/index{page}.html', visibleWhen: 'url_html:1'),
                'url_vod_detail' => $field('视频详情页保存路径', 'text', 'vod/{id}.html', visibleWhen: 'url_html:1'),
                'url_vod_play' => $field('视频播放页保存路径', 'text', 'play/{id}-{sid}-{pid}.html', visibleWhen: 'url_html:1'),
                'url_news_detail' => $field('文章详情页保存路径', 'text', 'news/{id}.html', visibleWhen: 'url_html:1'),
                'apikey_keyword' => $field('中文分词APIKEY', 'text', '', help: '留空使用系统默认 KEY，频率限制为每分钟6次。'),
                'apikey_douban' => $field('豆瓣资料APIKEY', 'password', '', help: '留空使用系统默认 KEY，频率限制为每分钟6次。', secret: true),
                'douban_cookie' => $field('豆瓣 Cookie', 'textarea', '', help: '填写浏览器访问豆瓣时的完整 Cookie；留空并提交可清除。', secret: true),
                'site_hot' => $field("热门搜索 一行一个\n\"|\"分隔格式含义如下：\n视频标题|超链接|超链接打开方式", 'textarea', '', public: true),
                'site_tongji' => $field('统计代码', 'textarea', ''),
            ],
            '扩展配置' => [
                'admin_order_type' => $field('后台数据管理排序', 'radio', 'id', ['addtime' => '时间', 'id' => 'ID值']),
                'admin_time_edit' => $field('允许编辑入库时间', 'boolean', '1'),
                'site_icp' => $field('网站备案信息', 'text', '', public: true),
                'site_email' => $field('站长联系邮箱', 'email', ''),
                'admin_ads_file' => $field('广告保存目录', 'text', 'ads', help: '请填写已经创建好的文件夹目录。'),
                'ui_slide_max' => $field('轮播上限（张）', 'number', '10', help: '调用多少张轮播图片，0为不限制。'),
                'ui_slide_index' => $field('轮播间隔（毫秒）', 'number', '3000', help: '1秒等于1000毫秒。'),
                'ui_scenario' => $field('分集剧情默认展示集数', 'number', '0', help: '设为0则默认全部展开显示。'),
                'ui_search_limit' => $field('搜索联想最多展示条数', 'number', '10', help: '设为0则不启用搜索联想功能。'),
                'ui_record' => $field('观看记录最多展示条数', 'number', '50', help: '设为0则不启用观看记录保存功能。'),
                'ui_playurl' => $field('播放列表最多展示集数', 'number', '0', help: '设为0则不启用，默认全部展示。'),
                'site_copyright' => $field('版权信息', 'textarea', '', public: true),
            ],
            'SEO优化' => [
                'site_title' => $field('（首页）标题', 'text', '飞飞影视', public: true),
                'site_keywords' => $field('（首页）关键字', 'text', '', public: true),
                'site_description' => $field('（首页）描述', 'text', '发现值得观看的影视内容', public: true),
            ],
        ],
    ],
    'rewrite' => [
        'label' => 'URL优化',
        'groups' => [
            'URL优化、伪静态配置' => [
                'url_html_suffix' => $field('网站路径后缀', 'select', '.html', ['.html' => '.html', '.htm' => '.htm', '.shtml' => '.shtml', '.shtm' => '.shtm', '' => '不需要后缀']),
                'url_rewrite' => $field('伪静态重写功能', 'boolean', '1'),
                'url_router_on' => $field('URL自定义开关', 'boolean', '1'),
                'rewrite_route' => $field('URL自定义规则', 'textarea', $rewriteRoutes, help: '每行一条“默认 URL===自定义 URL”；支持数字、字母、字母数字和任意单段字符占位符。保存前会双向校验。'),
            ],
        ],
    ],
    'pay' => [
        'label' => '付费点播',
        'groups' => [
            '积分与 VIP' => [
                'pay_enabled' => $field('开启付费功能', 'boolean', '0'),
                'pay_name' => $field('充值中心名称', 'text', '站内充值', public: true),
                'pay_notice' => $field('充值说明', 'textarea', '请输入管理员发放的充值卡密。', public: true),
                'currency' => $field('计费币种', 'select', 'points', ['points' => '积分', 'CNY' => '人民币']),
                'default_price' => $field('默认单片积分', 'number', '0'),
                'vip_enabled' => $field('开启 VIP', 'boolean', '1'),
                'trial_seconds' => $field('默认试看秒数', 'number', '0'),
                'user_pay_small' => $field('最低充值金额', 'number', '1'),
                'user_pay_scale' => $field('人民币积分比例', 'number', '1'),
                'user_pay_vip_ext' => $field('VIP 延期天数', 'number', '30'),
                'user_pay_vip_small' => $field('VIP 最低开通天数', 'number', '30'),
                'user_score_daily_enable' => $field('每日登录送积分', 'boolean', '0'),
                'user_score_daily' => $field('每日赠送积分', 'number', '5'),
                'pay_card_sell' => $field('卡密购买地址', 'url', ''),
            ],
            '支付宝' => [
                'pay_alipay_enabled' => $field('启用支付宝', 'boolean', '0'),
                'pay_alipay_account' => $field('支付宝账号', 'text', ''),
                'pay_alipay_appid' => $field('支付宝 AppID', 'text', ''),
                'pay_alipay_appkey' => $field('支付宝密钥', 'password', '', secret: true),
            ],
            '微信支付' => [
                'pay_wxpay_enabled' => $field('启用微信支付', 'boolean', '0'),
                'pay_wxpay_account' => $field('微信商户号', 'text', ''),
                'pay_wxpay_appid' => $field('微信 AppID', 'text', ''),
                'pay_wxpay_appkey' => $field('微信商户密钥', 'password', '', secret: true),
            ],
            '其他支付' => [
                'pay_paypal_enabled' => $field('启用 PayPal', 'boolean', '0'),
                'pay_paypal_account' => $field('PayPal 账号', 'text', ''),
                'pay_rj_appid' => $field('瑞捷 AppID', 'text', ''),
                'pay_rj_appkey' => $field('瑞捷密钥', 'password', '', secret: true),
                'pay_code_appid' => $field('码支付 AppID', 'text', ''),
                'pay_code_appkey' => $field('码支付密钥', 'password', '', secret: true),
                'pay_code_type' => $field('码支付类型', 'text', 'alipay'),
                'pay_code_act' => $field('码支付通道', 'text', '0'),
            ],
        ],
    ],
    'player' => [
        'label' => '独立播放器',
        'groups' => [
            '播放器' => [
                'engine' => $field('播放器内核', 'select', 'artplayer', ['artplayer' => 'ArtPlayer', 'native' => '浏览器原生播放器']),
                'play_second' => $field('片头跳过秒数', 'number', '0'),
                'play_autoplay' => $field('自动播放', 'boolean', '0'),
                'play_auto_next' => $field('自动播放下一集', 'boolean', '1'),
                'remember_progress' => $field('记忆播放进度', 'boolean', '1'),
                'play_buffer' => $field('缓冲广告地址', 'url', ''),
                'play_pause' => $field('暂停广告地址', 'url', ''),
                'play_live' => $field('直播错误提示地址', 'url', ''),
                'play_jiexi' => $field('默认解析接口', 'url', ''),
                'parser_allowlist' => $field('解析域名白名单', 'textarea', '', help: '一行一个域名。'),
                'player_url_hide_enabled' => $field('隐藏真实播放地址', 'boolean', '0'),
            ],
            '内容筛选字典' => [
                'news_type' => $field('文章分类字典', 'text', ''),
                'special_type' => $field('专题分类字典', 'text', ''),
                'star_type' => $field('明星分类字典', 'text', ''),
                'role_type' => $field('角色分类字典', 'text', ''),
                'play_type' => $field('视频类型字典', 'text', '喜剧,爱情,恐怖,动作,科幻,剧情,战争,警匪,犯罪,动画,奇幻,武侠,冒险,悬疑,惊悚,经典,青春,文艺,微电影,古装,历史,运动,农村,儿童,网络电影'),
                'play_area' => $field('地区字典', 'text', '中国大陆,美国,中国香港,中国台湾,韩国,日本,法国,英国,德国,泰国,印度,欧洲,东南亚,其他'),
                'play_year' => $field('年份字典', 'text', ''),
                'play_language' => $field('语言字典', 'text', '国语,英语,粤语,闽南语,韩语,日语,其它'),
                'play_state' => $field('状态字典', 'text', '连载,完结,预告'),
                'play_version' => $field('版本字典', 'text', '高清,蓝光,4K,预告片'),
                'play_weekday' => $field('星期字典', 'text', '一,二,三,四,五,六,日'),
            ],
            '弹幕' => [
                'danmaku_enabled' => $field('开启本地弹幕', 'boolean', '1'),
                'danmu_filter_enabled' => $field('开启敏感词过滤', 'boolean', '1'),
                'danmu_filter_url_enabled' => $field('禁止弹幕网址', 'boolean', '1'),
                'danmu_filter_words' => $field('弹幕敏感词', 'textarea', '', help: '一行一个。'),
                'player_remote_danmu_enabled' => $field('开启远程弹幕', 'boolean', '0'),
                'player_remote_danmu_api' => $field('远程弹幕 API', 'url', ''),
                'player_remote_danmu_token' => $field('远程弹幕 Token', 'password', '', secret: true),
                'player_remote_danmu_timeout' => $field('远程请求超时', 'number', '15'),
                'player_remote_danmu_cache_time' => $field('远程弹幕缓存秒数', 'number', '21600'),
                'player_remote_danmu_match_cache_time' => $field('匹配缓存秒数', 'number', '604800'),
                'player_remote_danmu_max' => $field('远程弹幕最大条数', 'number', '20000'),
                'play_server' => $field('播放器服务器', 'textarea', '', help: '每行：标识$$$地址。'),
            ],
        ],
    ],
    'content' => [
        'label' => '内容设置',
        'groups' => [
            '发布与列表' => [
                'page_size' => $field('默认每页条数', 'number', '24'),
                'admin_page_size' => $field('后台每页条数', 'number', '30'),
                'default_status' => $field('新增内容状态', 'select', 'draft', ['draft' => '未审核', 'published' => '已审核']),
                'related_limit' => $field('相关内容数量', 'number', '12'),
                'allow_html' => $field('正文允许 HTML', 'boolean', '1'),
                'auto_slug' => $field('自动生成 Slug', 'boolean', '1'),
                'auto_tags' => $field('自动提取标签', 'boolean', '0'),
                'archive_enabled' => $field('启用内容归档', 'boolean', '1'),
                'rss_limit' => $field('RSS 输出数量', 'number', '50'),
                'sitemap_limit' => $field('网站地图数量', 'number', '1000'),
            ],
            '图片与摘要' => [
                'summary_length' => $field('自动摘要长度', 'number', '180'),
                'lazy_image' => $field('图片懒加载', 'boolean', '1'),
                'default_poster' => $field('默认封面地址', 'url', ''),
                'default_avatar' => $field('默认头像地址', 'url', ''),
                'image_proxy' => $field('图片代理地址', 'url', ''),
            ],
        ],
    ],
    'cache' => [
        'label' => '缓存设置',
        'groups' => [
            '缓存驱动' => [
                'driver' => $field('缓存驱动', 'select', 'redis', ['redis' => 'Redis', 'file' => '文件']),
                'ttl' => $field('默认缓存秒数', 'number', '3600'),
                'prefix' => $field('缓存前缀', 'text', 'feifeicms:'),
                'cache_foreach' => $field('循环标签缓存秒数', 'number', '600'),
                'mxone_actor_cache_time' => $field('演员资料缓存秒数', 'number', '21600'),
            ],
            '页面缓存' => [
                'page_cache' => $field('开启页面缓存', 'boolean', '1'),
                'cache_page_list' => $field('分类页缓存秒数', 'number', '86400'),
                'cache_page_type' => $field('筛选页缓存秒数', 'number', '86400'),
                'cache_page_vod' => $field('视频页缓存秒数', 'number', '86400'),
                'cache_page_news' => $field('文章页缓存秒数', 'number', '86400'),
                'cache_page_special' => $field('专题页缓存秒数', 'number', '86400'),
                'cache_page_forum' => $field('评论页缓存秒数', 'number', '3600'),
                'cache_page_person' => $field('人物页缓存秒数', 'number', '86400'),
                'cache_page_user' => $field('用户页缓存秒数', 'number', '0'),
                'html_cache_on' => $field('模板静态缓存', 'boolean', '0'),
                'html_cache_time' => $field('模板静态缓存秒数', 'number', '3600'),
            ],
            '搜索' => [
                'search_driver' => $field('搜索驱动', 'select', 'mysql', ['mysql' => 'MySQL', 'meilisearch' => 'Meilisearch（推荐，替代 Xunsearch）']),
                'search_fallback' => $field('搜索故障回退', 'select', 'mysql', ['mysql' => 'MySQL', 'none' => '不回退']),
                'search_host' => $field('搜索服务地址', 'url', (string) config('feifei.search.meilisearch.host', 'http://127.0.0.1:7700')),
                'search_index' => $field('索引名称', 'text', (string) config('feifei.search.meilisearch.index', 'feifeicms_media')),
                'search_key' => $field('搜索服务密钥', 'password', (string) config('feifei.search.meilisearch.key', ''), secret: true),
            ],
        ],
    ],
    'collection' => [
        'label' => '采集设置',
        'groups' => [
            '采集行为' => [
                'enabled' => $field('允许采集', 'boolean', '1'),
                'batch_size' => $field('每批条数', 'number', '50'),
                'timeout' => $field('请求超时秒数', 'number', '15'),
                'retry_count' => $field('失败重试次数', 'number', '2'),
                'collect_time' => $field('每页暂停秒数', 'number', '0'),
                'auto_publish' => $field('采集后自动审核', 'boolean', '1'),
                'user_agent' => $field('User-Agent', 'text', 'FeiFeiCMS/8'),
                'collect_name' => $field('片名尾部忽略字符数', 'number', '0'),
                'collect_original' => $field('同义词 SEO 处理', 'boolean', '0'),
                'collect_actor' => $field('自动建立人物资料', 'boolean', '0'),
                'collect_hits' => $field('默认人气', 'number', '0'),
                'collect_updown' => $field('默认顶踩', 'number', '0'),
                'collect_gold' => $field('默认评分', 'number', '0'),
                'collect_golder' => $field('默认评分人数', 'number', '0'),
                'collect_forum' => $field('默认评论热度', 'number', '0'),
                'collect_passwd' => $field('远程采集密码', 'password', '', secret: true),
                'collect_ips' => $field('允许采集 IP', 'textarea', '', help: '一行一个或逗号分隔；留空不限制。'),
            ],
            '格式化与合并' => [
                'merge_enabled' => $field('自动合并同名影片', 'boolean', '1'),
                'merge_by_year' => $field('合并时校验年份', 'boolean', '1'),
                'merge_by_external_id' => $field('优先按豆瓣/IMDb 合并', 'boolean', '1'),
                'normalize_area' => $field('标准化地区名称', 'boolean', '1'),
                'normalize_language' => $field('标准化语言名称', 'boolean', '1'),
                'replace_rules' => $field('文本替换规则', 'textarea', "大陆=>中国大陆\n内地=>中国大陆\n香港=>中国香港\n台湾=>中国台湾\n汉语=>国语\n普通话=>国语\n汉语普通话=>国语"),
                'api_allowed_players' => $field('允许入库的线路标识', 'textarea', '', help: '一行一个；留空允许全部。'),
                'api_download_enabled' => $field('开放采集 API 下载地址', 'boolean', '0'),
            ],
            '元数据服务' => [
                'tmdb_api_key' => $field('TMDB API Key', 'password', '', secret: true),
                'tmdb_api_base' => $field('TMDB API 地址', 'url', 'https://api.tmdb.org/3'),
                'torrent_enabled' => $field('启用种子补全', 'boolean', '0'),
                'torrent_eztv_api' => $field('EZTV API', 'url', 'https://eztvx.to/api/get-torrents'),
                'torrent_xlys_base' => $field('迅雷影视域名', 'url', 'https://www.xlys02.com'),
                'torrent_xlys_reader' => $field('网页读取代理', 'url', 'https://r.jina.ai/'),
                'torrent_sync_delay' => $field('种子同步间隔秒数', 'number', '3'),
                'torrent_front_limit' => $field('前台种子数量', 'number', '30'),
                'yyets_enabled' => $field('启用人人影视资源', 'boolean', '0'),
                'yyets_api' => $field('人人影视 API', 'url', 'https://yyets.click/api/'),
                'yyets_sync_delay' => $field('人人影视同步间隔秒数', 'number', '2'),
            ],
            'TV API' => [
                'tv_api_enabled' => $field('开放 TV API', 'boolean', '0'),
                'tv_api_token_enabled' => $field('TV API Token 验证', 'boolean', '1'),
                'tv_api_token' => $field('TV API Token', 'password', '', secret: true),
                'tv_app_update_manifest' => $field('TV 更新清单地址', 'url', ''),
                'tv_api_allowed_players' => $field('TV 可用线路', 'textarea', ''),
            ],
        ],
    ],
    'files' => [
        'label' => '附件设置',
        'groups' => [
            '本地附件' => [
                'storage' => $field('存储方式', 'select', 'local', ['local' => '本地', 'ftp' => 'FTP', 's3' => 'S3 兼容存储']),
                'upload_path' => $field('上传目录', 'text', 'uploads'),
                'upload_style' => $field('文件名规则', 'text', 'Ymd'),
                'upload_class' => $field('允许类型', 'text', 'jpg,jpeg,png,webp,gif'),
                'base_url' => $field('附件域名', 'url', ''),
                'max_size_mb' => $field('最大上传 MB', 'number', '20'),
                'upload_http' => $field('采集远程图片', 'boolean', '0'),
                'upload_http_down' => $field('每批下载数量', 'number', '20'),
                'upload_face_width' => $field('头像宽度', 'number', '300'),
                'upload_face_height' => $field('头像高度', 'number', '300'),
            ],
            '缩略图与水印' => [
                'upload_thumb' => $field('缩略图方式', 'select', '0', ['0' => '关闭', '1' => '等比例缩小', '2' => '指定大小截取']),
                'upload_thumb_w' => $field('缩略图宽度', 'number', '300'),
                'upload_thumb_h' => $field('缩略图高度', 'number', '420'),
                'upload_water' => $field('开启水印', 'boolean', '0'),
                'upload_water_pct' => $field('水印透明度', 'number', '80'),
                'upload_water_pos' => $field('水印位置', 'number', '9'),
                'upload_water_img' => $field('水印图片', 'text', 'watermark.png'),
            ],
            'FTP' => [
                'upload_ftp' => $field('开启 FTP', 'boolean', '0'),
                'upload_ftp_del' => $field('上传后删除本地文件', 'boolean', '0'),
                'upload_ftp_host' => $field('FTP 主机', 'text', ''),
                'upload_ftp_user' => $field('FTP 用户', 'text', ''),
                'upload_ftp_pass' => $field('FTP 密码', 'password', '', secret: true),
                'upload_ftp_port' => $field('FTP 端口', 'number', '21'),
                'upload_ftp_dir' => $field('FTP 目录', 'text', '/'),
            ],
            '图片代理' => [
                'upload_http_prefix' => $field('远程附件前缀', 'url', ''),
                'upload_referer' => $field('外部防盗链代理', 'url', ''),
                'upload_referer_domains' => $field('防盗链域名', 'textarea', ''),
                'upload_referer_local' => $field('本地图片代理', 'text', ''),
                'upload_safety' => $field('禁止下载的域名', 'textarea', ''),
            ],
        ],
    ],
    'email' => [
        'label' => '邮件设置',
        'groups' => [
            'SMTP' => [
                'enabled' => $field('开启邮件', 'boolean', '0'),
                'email_host' => $field('SMTP 服务器', 'text', ''),
                'email_username' => $field('SMTP 账号', 'text', ''),
                'email_password' => $field('SMTP 密码/授权码', 'password', '', secret: true),
                'email_port' => $field('SMTP 端口', 'number', '465'),
                'email_secure' => $field('加密方式', 'select', 'ssl', ['ssl' => 'SSL', 'tls' => 'TLS', 'none' => '无']),
                'from_name' => $field('发件人名称', 'text', '飞飞影视'),
                'email_usertest' => $field('测试收件邮箱', 'email', ''),
            ],
        ],
    ],
    'register' => [
        'label' => '注册设置',
        'groups' => [
            '会员注册' => [
                'user_register' => $field('开放注册', 'boolean', '1'),
                'user_register_check' => $field('注册需邮箱验证', 'boolean', '0'),
                'email_required' => $field('邮箱必填', 'boolean', '0'),
                'default_group' => $field('默认用户组', 'text', 'member'),
                'username_min' => $field('用户名最小长度', 'number', '3'),
                'username_max' => $field('用户名最大长度', 'number', '30'),
                'password_min' => $field('密码最小长度', 'number', '8'),
                'user_register_score' => $field('注册赠送积分', 'number', '0'),
                'user_register_vipday' => $field('注册赠送 VIP 天数', 'number', '0'),
                'user_register_score_pid' => $field('邀请人赠送积分', 'number', '0'),
                'user_register_second' => $field('同 IP 注册间隔秒数', 'number', '60'),
                'user_register_welcome' => $field('注册欢迎语', 'textarea', '欢迎加入！'),
            ],
        ],
    ],
    'comments' => [
        'label' => '评论设置',
        'groups' => [
            '评论与留言' => [
                'forum_type' => $field('评论系统', 'select', 'feifei', ['feifei' => '系统自带', 'changyan' => '畅言']),
                'forum_type_changyan_appid' => $field('畅言 AppID', 'text', ''),
                'forum_type_changyan_conf' => $field('畅言配置 ID', 'text', ''),
                'user_forum' => $field('开启评论', 'boolean', '1'),
                'guest_enabled' => $field('允许游客评论', 'boolean', '1'),
                'user_check' => $field('评论需要审核', 'boolean', '1'),
                'user_email_forum' => $field('新评论邮件通知', 'boolean', '0'),
                'user_email_guestbook' => $field('新留言邮件通知', 'boolean', '0'),
                'user_email_error' => $field('报错邮件通知', 'boolean', '0'),
                'user_second' => $field('发言间隔秒数', 'number', '30'),
                'max_length' => $field('评论最大字数', 'number', '1000'),
                'page_size' => $field('评论每页数量', 'number', '30'),
                'user_replace' => $field('敏感词替换', 'textarea', '', help: '一行一个词，命中后替换为 ***。'),
                'blocked_ips' => $field('禁止发言 IP', 'textarea', ''),
            ],
        ],
    ],
    'weixin' => [
        'label' => '微信设置',
        'groups' => [
            '公众号' => [
                'enabled' => $field('开启微信接口', 'boolean', '0'),
                'wx_check' => $field('强制关注位置', 'select', '0', ['0' => '关闭', '1' => '详情页', '2' => '播放页']),
                'wx_cids' => $field('适用分类 ID', 'text', ''),
                'wx_order' => $field('搜索排序', 'text', 'id desc'),
                'wx_token' => $field('接口 Token', 'password', '', secret: true),
                'wx_follow' => $field('关注提示', 'text', ''),
                'wx_none_txt' => $field('无结果提示', 'text', '没有找到相关内容'),
                'wx_none_url' => $field('无结果跳转', 'url', ''),
                'wx_domain' => $field('公众号域名', 'url', ''),
                'wx_jiexi' => $field('微信播放解析', 'url', ''),
            ],
            '自定义回复' => [
                'wx_item' => $field('关键词回复规则', 'textarea', '[]', help: 'JSON 数组，每项包含 keyword、title、content、pic、link。'),
            ],
        ],
    ],
];
