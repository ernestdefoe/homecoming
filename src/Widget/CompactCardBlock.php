<?php

namespace ErnestDefoe\Homecoming\Widget;

class CompactCardBlock extends HomecomingBlock
{
    protected function piece(): string
    {
        return 'compact_card';
    }

    public function icon(): string
    {
        return 'fas fa-id-card';
    }
}
