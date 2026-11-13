<?php

namespace ErnestDefoe\Homecoming\Widget;

class WelcomeStripBlock extends HomecomingBlock
{
    protected function piece(): string
    {
        return 'welcome_strip';
    }

    public function icon(): string
    {
        return 'fas fa-house-user';
    }
}
