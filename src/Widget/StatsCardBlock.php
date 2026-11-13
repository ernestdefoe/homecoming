<?php

namespace ErnestDefoe\Homecoming\Widget;

class StatsCardBlock extends HomecomingBlock
{
    protected function piece(): string
    {
        return 'stats_card';
    }

    public function icon(): string
    {
        return 'fas fa-chart-simple';
    }
}
