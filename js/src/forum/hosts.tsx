import app from 'flarum/forum/app';
import { fof, hasFofWidgets, placed } from '../common/fofWidgets';
import Piece, { hasContent, type PieceName } from './Piece';

/*
 * Offers the three pieces to every widget host that is present. None is a
 * build-time import; each is found at runtime, so the bundle is the same with
 * all of them installed or none. Registering is not placing: the stock strip
 * stands down only for a mounted copy (placement.ts).
 *
 * Bespoke reads widgets-core's registrations itself (its adapter), so there is
 * no Bespoke-only path. 🚨 Never also push an old-style `{type, label}` Bespoke
 * def: Bespoke would then treat us as handling it ourselves and skip these.
 */

const EXT = 'ernestdefoe-homecoming';

interface Def {
  key: string;
  piece: PieceName;
  icon: string;
  titleKey: string;
  placement: string;
  zones?: string[];
}

export const PIECES: Def[] = [
  {
    key: 'welcomeStrip',
    piece: 'strip',
    icon: 'fas fa-house-user',
    titleKey: `${EXT}.lib.welcome_strip`,
    placement: 'top',
    // Full width only: it is a row, not a sidebar card.
    zones: ['header', 'top', 'above-list', 'below-list', 'footer'],
  },
  { key: 'statsCard', piece: 'stats', icon: 'fas fa-chart-simple', titleKey: `${EXT}.lib.stats_card`, placement: 'end' },
  { key: 'compactCard', piece: 'compact', icon: 'fas fa-id-card', titleKey: `${EXT}.lib.compact_card`, placement: 'end' },
];

export function registerHosts(): void {
  for (const register of [registerWidgets, registerPageBuilder]) {
    try {
      register();
    } catch (e) {
      // A host whose internals moved must not take the forum's JS down with it.
      console.warn('[homecoming] widget host registration failed', e);
    }
  }
}

/** widgets-core, and through it Bespoke. Without widgets-core, Bespoke gets the same descriptors from its queue. */
function registerWidgets(): void {
  const descriptor = (def: Def, component: any) => ({
    key: def.key,
    component,
    title: () => app.translator.trans(def.titleKey),
    icon: def.icon,
    // widgets-core: only where the admin placed it (opt-in). Bespoke: whenever it has something to show.
    isDisabled: (ctx?: { host?: string }) =>
      ctx?.host === 'bespoke' ? !hasContent(def.piece) : !placed(app, EXT, def.key) || !hasContent(def.piece),
    isUnique: true,
    placement: def.placement,
    position: 0,
    ...(def.zones ? { zones: def.zones } : {}),
  });

  if (hasFofWidgets()) {
    const Widgets = fof('common/extend/Widgets');
    const WidgetBase = fof('common/components/Widget');
    if (!Widgets || !WidgetBase || !(app as any).widgets) return;

    const add = new Widgets();
    PIECES.forEach((def) => {
      class HomecomingWidget extends WidgetBase {
        className() {
          return `HomecomingWidget HomecomingWidget--${def.piece}`;
        }
        icon() {
          return def.icon;
        }
        title() {
          return null;
        }
        content() {
          return <Piece piece={def.piece} host={this.attrs?.host === 'bespoke' ? 'bespoke' : 'fof'} />;
        }
      }
      add.add(descriptor(def, HomecomingWidget));
    });
    add.extend(app, EXT);
    return;
  }

  const flarum = (globalThis as any).flarum;
  if (!flarum?.extensions || !('ernestdefoe-bespoke' in flarum.extensions)) return;

  // Same descriptors, plain components; the id stays `ext:key` so a saved Bespoke layout
  // survives widgets-core being added or removed. Bespoke 2.8.6 and older ignore these.
  const queue = ((globalThis as any).BespokeWidgetQueue = (globalThis as any).BespokeWidgetQueue || []);
  PIECES.forEach((def) => {
    queue.push({
      ...descriptor(def, { view: () => <Piece piece={def.piece} host="bespoke" /> }),
      extension: EXT,
    });
  });
}

function registerPageBuilder(): void {
  const w = window as any;
  PIECES.forEach((def) => {
    // Matches the server block's type(): homecoming-welcome-strip, -stats-card, -compact-card.
    const type = 'homecoming-' + def.titleKey.split('.').pop()!.replace(/_/g, '-');
    const block = { view: () => <Piece piece={def.piece} host="page-builder" /> };

    // Page Builder 3.10+ drains this queue whenever it looks a block up, so the block is there
    // whichever extension initialises first. An earlier type wins.
    (w.PageBuilderBlockQueue = w.PageBuilderBlockQueue || []).push({ type, component: block });

    // Older Page Builders never read the queue: register directly when it is already here
    // (we run in app.mount, after every initializer). On 3.10+ the queued copy is then skipped.
    (app as any).pageBuilder?.registerBlock?.(type, block);
  });
}
