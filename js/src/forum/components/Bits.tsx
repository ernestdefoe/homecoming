import app from 'flarum/forum/app';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import type Mithril from 'mithril';
import type { Dashboard } from '../store';
import { climb, count, degrees, links, t, tText, weatherIcon, condition, type Pill } from './util';

/** The rank or group pill, in the group's colour when it has one. */
export function PillBadge(p: Pill | null, small = false): Mithril.Children {
  if (!p) return null;
  return (
    <span className={`Homecoming-pill${small ? ' Homecoming-pill--small' : ''}`} style={p.color ? { '--homecoming-pill': p.color } : undefined}>
      {p.icon ? <Icon name={p.icon} /> : null}
      <span>{p.name}</span>
    </span>
  );
}

/** "64°F, partly cloudy in Portland", or nothing. */
export function WeatherLine(d: Dashboard | null): Mithril.Children {
  if (!d?.weather || !d.place) return null;
  const w = d.weather;
  return (
    <span className="Homecoming-weather">
      <Icon name={weatherIcon(w.code, w.day)} />
      <span>{t('weather.line', { temp: degrees(w.temp), condition: tText(`weather.${condition(w.code)}`), place: shortPlace(d.place.label) })}</span>
    </span>
  );
}

export function shortPlace(label: string): string {
  return label.split(',')[0].trim();
}

/** Unread discussions and notifications, as links. */
export function Counts(d: Dashboard | null, variant: 'tiles' | 'rows'): Mithril.Children {
  const user = app.session.user!;
  const unread = d ? d.unread : null;
  const notes = Number((user as any).unreadNotificationCount?.() ?? 0);

  if (variant === 'rows') {
    return (
      <nav className="Homecoming-rows" aria-label={tText('unread_short')}>
        <Link className="Homecoming-row" href={links.unread()}>
          <Icon name="far fa-comments" />
          <span className="Homecoming-rowLabel">{t('unread_short')}</span>
          <span className="Homecoming-badge">{unread === null ? '–' : count(unread)}</span>
        </Link>
        <Link className="Homecoming-row" href={links.notifications()}>
          <Icon name="far fa-bell" />
          <span className="Homecoming-rowLabel">{t('notifications_short')}</span>
          <span className="Homecoming-badge">{count(notes)}</span>
        </Link>
      </nav>
    );
  }

  return (
    <nav className="Homecoming-tiles" aria-label={tText('unread_short')}>
      <Link className="Homecoming-tile" href={links.unread()}>
        <span className="Homecoming-tileCount">
          <span className="Homecoming-dot" aria-hidden="true" />
          {unread === null ? '–' : count(unread)}
        </span>
        <span className="Homecoming-tileLabel">{t('unread', { count: unread ?? 0 })}</span>
      </Link>
      <Link className="Homecoming-tile" href={links.notifications()}>
        <span className="Homecoming-tileCount">
          <Icon name="far fa-bell" />
          {count(notes)}
        </span>
        <span className="Homecoming-tileLabel">{t('notifications', { count: notes })}</span>
      </Link>
    </nav>
  );
}

/** The bar and "160 more to Veteran". Nothing without a points extension. */
export function ClimbBar(d: Dashboard | null): Mithril.Children {
  const c = climb(d?.progress ?? null);
  if (!c) return null;
  return (
    <div className="Homecoming-climb">
      <div
        className="Homecoming-bar"
        role="progressbar"
        aria-valuemin={0}
        aria-valuemax={100}
        aria-valuenow={c.pct}
        aria-label={c.next ? tText('progress_label', { rank: c.next }) : tText('top_rank')}
      >
        <div className="Homecoming-barFill" style={{ width: `${c.pct}%` }} />
      </div>
      <div className="Homecoming-climbText">
        {c.next ? t('to_next', { count: count(c.remaining, Infinity), rank: <strong>{c.next}</strong> }) : t('top_rank')}
      </div>
    </div>
  );
}
