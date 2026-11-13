import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import { registerEditorWidgets } from '../common/fofWidgets';

const EXT = 'ernestdefoe-homecoming';
const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`${EXT}.admin.settings.${key}`, params, true);
const setting = (key: string) => `${EXT}.${key}`;

// Keys are words, never integer-like (JS would re-sort those first).
const STATS = ['discussions', 'posts', 'likes', 'best_answers', 'collections', 'badges', 'points', 'level', 'joined'];
const statOptions = () => Object.fromEntries(STATS.map((key) => [key, t(`stat_options.${key}`)]));

export const extend = [
  new Extend.Admin()
    .setting(
      () => ({
        setting: setting('placement'),
        type: 'select',
        label: t('placement'),
        help: t('placement_help'),
        options: { top: t('placement_top'), none: t('placement_none') },
        default: 'top',
      }),
      100
    )
    .setting(
      () => ({
        setting: setting('stat1'),
        type: 'select',
        label: t('stat', { number: 1 }),
        help: t('stats_help'),
        options: statOptions(),
        default: 'discussions',
      }),
      90
    )
    .setting(() => ({ setting: setting('stat2'), type: 'select', label: t('stat', { number: 2 }), options: statOptions(), default: 'posts' }), 89)
    .setting(() => ({ setting: setting('stat3'), type: 'select', label: t('stat', { number: 3 }), options: statOptions(), default: 'likes' }), 88)
    .setting(() => ({ setting: setting('stat4'), type: 'select', label: t('stat', { number: 4 }), options: statOptions(), default: 'joined' }), 87)
    .setting(() => ({ setting: setting('unread_days'), type: 'number', label: t('unread_days'), help: t('unread_days_help'), min: 1, max: 365 }), 80)
    .setting(() => ({ setting: setting('guest_nudge'), type: 'boolean', label: t('guest_nudge'), help: t('guest_nudge_help') }), 70)
    .setting(() => ({ setting: setting('weather'), type: 'boolean', label: t('weather'), help: t('weather_help') }), 60)
    .setting(
      () => ({
        setting: setting('weather_unit'),
        type: 'select',
        label: t('weather_unit'),
        help: t('weather_unit_help'),
        options: { c: '°C', f: '°F' },
        default: 'c',
      }),
      50
    )
    .setting(() => ({ setting: setting('weather_cap'), type: 'number', label: t('weather_cap'), help: t('weather_cap_help'), min: 1, max: 600 }), 40),
];

app.initializers.add(EXT, () => {
  registerEditorWidgets(app, EXT, [
    { key: 'welcomeStrip', icon: 'fas fa-house-user', title: () => app.translator.trans(`${EXT}.lib.welcome_strip`), placement: 'top' },
    { key: 'statsCard', icon: 'fas fa-chart-simple', title: () => app.translator.trans(`${EXT}.lib.stats_card`) },
    { key: 'compactCard', icon: 'fas fa-id-card', title: () => app.translator.trans(`${EXT}.lib.compact_card`) },
  ]);
});
