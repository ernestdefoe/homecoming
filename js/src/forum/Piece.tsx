import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
import { claim, hostedElsewhere, isStock, release } from './placement';

/*
 * The one every-page component: it loads the pieces' chunk the first time one
 * is drawn and then draws the piece. Everything else (the strip, the cards,
 * weather, the settings section) lives in that lazy chunk.
 */

export type PieceName = 'strip' | 'stats' | 'compact' | 'settings';

let ui: any = null;
let pending: Promise<void> | null = null;
let failed = false;

function loadUi(): void {
  // One try per page: a missing chunk must not turn every redraw into a request.
  if (ui || pending || failed) return;
  pending = import('./components')
    .then((mod) => {
      ui = mod;
    })
    .catch((e) => {
      failed = true;
      console.warn('[homecoming] could not load its pieces', e);
    })
    .finally(() => {
      pending = null;
      m.redraw();
    });
}

/** Whether a piece has anything to show this viewer (FoF draws a box for any widget it is given). */
export function hasContent(piece: PieceName): boolean {
  if (app.session.user) return true;
  return piece === 'strip' && !!app.forum.attribute('homecomingGuestNudge');
}

interface Attrs {
  piece: PieceName;
  host: string;
  settings?: Record<string, unknown>;
}

export default class Piece extends Component<Attrs> {
  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.oncreate(vnode);
    if (this.attrs.piece !== 'settings') claim(this.attrs.host);
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    if (this.attrs.piece !== 'settings') release(this.attrs.host);
  }

  view() {
    const { piece, host } = this.attrs;
    if (!hasContent(piece)) return null;
    // A stock copy steps aside while a widget host shows any Homecoming piece.
    if (isStock(host) && hostedElsewhere()) return null;

    loadUi();
    if (!ui) return null;

    if (!app.session.user) return <ui.GuestNudge host={host} />;

    switch (piece) {
      case 'strip':
        return <ui.WelcomeStrip host={host} />;
      case 'stats':
        return <ui.StatsCard host={host} />;
      case 'compact':
        return <ui.CompactCard host={host} />;
      case 'settings':
        return <ui.WeatherSettings />;
    }
  }
}
