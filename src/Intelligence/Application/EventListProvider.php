<?php

namespace App\Intelligence\Application;

interface EventListProvider
{
    /**
     * @return array<int, EventListRow>
     */
    public function list(EventListFilter $filter): array;
}
