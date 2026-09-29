<?php
declare(strict_types=1);

namespace tests;

use app\service\DoubanComments;
use PHPUnit\Framework\TestCase;

final class DoubanCommentsTest extends TestCase
{
    public function testParsesOnlyAnonymousCommentTextAndDeduplicatesIt(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<div class="comment-item"><a class="comment-info">Alice</a><span class="short">一条 &amp; 很好的短评</span><span class="votes">99</span></div>
<div class="comment-item"><span class="short"> 一条 &amp; 很好的短评 </span></div>
<div class="comment-item"><span class="short">第二条评论</span><img alt="avatar"></div>
</body></html>
HTML;
        $comments = (new DoubanComments())->parseHtml($html);
        self::assertSame(['一条 & 很好的短评', '第二条评论'], $comments);
        self::assertStringNotContainsString('Alice', implode('', $comments));
        self::assertStringNotContainsString('99', implode('', $comments));
    }
}
