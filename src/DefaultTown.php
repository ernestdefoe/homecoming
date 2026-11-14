<?php

namespace ErnestDefoe\Homecoming;

use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\Event\Saved;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The admin typed a default town: look it up once, as the setting is saved,
 * and keep its centre and name for members with no place of their own. An
 * empty town clears it; a town the geocoder can't find leaves no default.
 */
class DefaultTown
{
    public function __construct(
        private readonly Places $places,
        private readonly SettingsRepositoryInterface $settings,
        private readonly LocaleManager $locales,
    ) {
    }

    public function handle(Saved $event): void
    {
        if (! array_key_exists('ernestdefoe-homecoming.default_town', $event->settings)) {
            return;
        }

        $town = trim((string) $event->settings['ernestdefoe-homecoming.default_town']);
        $found = $town === '' ? null : ($this->places->search($town, $this->locales->getLocale())[0] ?? null);

        $this->settings->set('ernestdefoe-homecoming.default_lat', $found ? (string) $found['lat'] : '');
        $this->settings->set('ernestdefoe-homecoming.default_lng', $found ? (string) $found['lng'] : '');
        $this->settings->set('ernestdefoe-homecoming.default_label', $found ? $found['label'] : '');
    }
}
