import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import Piece from './Piece';
import { registerHosts } from './hosts';
import { refreshStale } from './store';

export { default as HomecomingPiece } from './Piece';

const EXT = 'ernestdefoe-homecoming';

app.initializers.add(EXT, () => {
  // String form: IndexPage and SettingsPage are lazy chunks; extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    if (app.forum.attribute('homecomingPlacement') !== 'top') return;
    items.add('homecoming', <Piece piece="strip" host="stock-top" />, 120);
  });

  extend('flarum/forum/components/SettingsPage', 'settingsItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('homecomingWeather')) return;
    items.add('homecoming', <Piece piece="settings" host="settings" />, 5);
  });

  // After every initializer (Page Builder exposes its registry in its own), before the first draw.
  override(app, 'mount', function (original: (...args: any[]) => unknown, ...args: any[]) {
    registerHosts();
    document.addEventListener('visibilitychange', refreshStale);
    return original(...args);
  });
});
