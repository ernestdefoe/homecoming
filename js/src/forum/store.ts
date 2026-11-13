import app from 'flarum/forum/app';

/*
 * The member's dashboard from GET /api/homecoming, shared by every piece on
 * the page (strip, cards, every host). Module state, never component state:
 * Bespoke remounts its zones on redraw, and a fetch in oninit would loop.
 * Asked once, then again after two minutes when something is drawn or the
 * tab comes back.
 */

export interface Stat {
  key: string;
  value: number | string | null;
}

export interface Progress {
  source: 'renown' | 'ladder' | 'gamification';
  points: number;
  min: number;
  max: number | null;
  level?: number;
  rank?: number;
  nextRank?: number | null;
  name?: string | null;
  color?: string | null;
  nextName?: string | null;
  metric?: string | null;
}

export interface Weather {
  temp: number;
  code: number;
  day: boolean;
  high: number | null;
  low: number | null;
}

export interface Dashboard {
  unread: number;
  stats: Stat[];
  progress: Progress | null;
  place: { label: string; source: 'own' | 'member-map' } | null;
  weather: Weather | null;
}

const TTL = 120_000;

let data: Dashboard | null = null;
let at = 0;
let loading = false;
let userId: string | null = null;

function load(): void {
  loading = true;
  app
    .request<Dashboard>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/homecoming' })
    .then((d) => {
      data = d;
    })
    .catch(() => {
      // Keep what we had; the pieces draw without the numbers.
    })
    .finally(() => {
      loading = false;
      at = Date.now();
      m.redraw();
    });
}

/** The dashboard, asking for it when it is missing or old. Null for guests and until it arrives. */
export function dashboard(): Dashboard | null {
  const user = app.session.user;
  if (!user) return null;
  if (userId !== user.id()) {
    userId = user.id() as string;
    data = null;
    at = 0;
  }
  if (!loading && Date.now() - at > TTL) load();
  return data;
}

/** After the member changed something we show (their town), ask now. */
export function refresh(): void {
  at = 0;
  if (app.session.user && !loading) load();
}

export function refreshStale(): void {
  if (document.visibilityState === 'visible' && data && !loading && Date.now() - at > TTL) load();
}
