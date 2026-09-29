<?php
declare(strict_types=1);

namespace app\service\search;

use app\model\Media;

final class MySqlVodSearch implements VodSearchDriver
{
    public function search(string $keyword, int $page, int $pageSize): SearchResult
    {
        $query = Media::where('status', 'published')->whereNull('deleted_at')
            ->where(function ($query) use ($keyword): void {
                $query->whereLike('title', '%' . $keyword . '%')
                    ->whereLike('original_title', '%' . $keyword . '%', 'OR')
                    ->whereLike('subtitle', '%' . $keyword . '%', 'OR')
                    ->whereLike('summary', '%' . $keyword . '%', 'OR');
            });

        $total = (clone $query)->count();
        $items = $query->order('published_at', 'desc')->order('id', 'desc')
            ->page($page, $pageSize)
            ->select()
            ->toArray();

        return new SearchResult($items, $total, $page, $pageSize, 'mysql');
    }
}
