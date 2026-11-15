<?php

namespace ErnestDefoe\Homecoming;

use ErnestDefoe\Homecoming\Weather\Places;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\LocaleManager;
use Flarum\Settings\Event\Saving;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The admin typed a default town: look it up as the settings are saved (one
 * geocoder call, 3 s at most, only when this setting changes) and save its
 * centre and name with them. An empty town clears it. A town the geocoder
 * can't find, or a geocoder that can't be reached, stops the save with a
 * message, so a town is never kept without its coordinates.
 */
class DefaultTown
{
    public function __construct(
        private readonly Places $places,
        private readonly LocaleManager $locales,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(Saving $event): void
    {
        if (! array_key_exists('ernestdefoe-homecoming.default_town', $event->settings)) {
            return;
        }

        $town = trim((string) $event->settings['ernestdefoe-homecoming.default_town']);
        $found = null;

        if ($town !== '') {
            $results = $this->places->search($town, $this->locales->getLocale());
            if ($results === null) {
                throw new ValidationException(['ernestdefoe-homecoming.default_town' => $this->translator->trans('ernestdefoe-homecoming.admin.settings.default_town_unreachable')]);
            }
            $found = $results[0] ?? throw new ValidationException(['ernestdefoe-homecoming.default_town' => $this->translator->trans('ernestdefoe-homecoming.admin.settings.default_town_not_found', ['town' => $town])]);
        }

        $event->settings['ernestdefoe-homecoming.default_town'] = $town;
        $event->settings['ernestdefoe-homecoming.default_lat'] = $found ? (string) $found['lat'] : '';
        $event->settings['ernestdefoe-homecoming.default_lng'] = $found ? (string) $found['lng'] : '';
        $event->settings['ernestdefoe-homecoming.default_label'] = $found ? $found['label'] : '';
    }
}
