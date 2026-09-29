<?php
declare(strict_types=1);

namespace app\service\search;

interface VodSearchDriver
{
    public function search(string $keyword, int $page, int $pageSize): SearchResult;
}
