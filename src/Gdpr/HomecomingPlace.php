<?php

namespace ErnestDefoe\Homecoming\Gdpr;

use Flarum\Gdpr\Data\Type;

/**
 * flarum/gdpr: the member's weather place (a town and a point rounded to about
 * a kilometre). Exported with their data; erased in BOTH modes, because
 * anonymizing keeps the user row, so the foreign key's cascade never fires.
 * Registered from extend.php only while flarum/gdpr is enabled.
 */
class HomecomingPlace extends Type
{
    public static function dataType(): string
    {
        return 'HomecomingPlace';
    }

    public static function exportDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-homecoming.lib.gdpr.export');
    }

    public static function anonymizeDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-homecoming.lib.gdpr.erase');
    }

    public static function deleteDescription(): string
    {
        return self::staticTranslator()->trans('ernestdefoe-homecoming.lib.gdpr.erase');
    }

    public function export(): ?array
    {
        $place = $this->user->getConnection()->table('homecoming_places')->where('user_id', $this->user->id)->first(['lat', 'lng', 'label', 'updated_at']);

        return $place ? [['homecoming/place.json' => $this->encodeForExport((array) $place)]] : null;
    }

    public function anonymize(): void
    {
        $this->delete();
    }

    public function delete(): void
    {
        $this->user->getConnection()->table('homecoming_places')->where('user_id', $this->user->id)->delete();
    }
}
