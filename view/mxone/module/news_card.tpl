<a class="mx-news-card" href="{:ff_url('news',$news.news_id)}">
    <img src="{$news.news_pic|default='/theme-assets/shared/images/no-poster.svg'}" alt="{$news.news_name}">
    <strong>{$news.news_name}</strong>
    <p>{$news.summary_text}</p>
</a>
