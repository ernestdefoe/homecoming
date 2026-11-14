import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { dashboard } from '../store';
import { Counts, LocateButton, PillBadge, shortPlace } from './Bits';
import {
  canStart,
  climb,
  condition,
  count,
  degrees,
  greeting,
  links,
  pill,
  startDiscussion,
  statLabel,
  statValue,
  t,
  tText,
  weatherIcon,
} from './util';

const R = 30;
const C = 2 * Math.PI * R;

/** Everything in one small block for a sidebar: the rank progress is a ring around the avatar. */
export default class CompactCard extends Component<{ host: string }> {
  view() {
    const user = app.session.user!;
    const d = dashboard();
    const c = climb(d?.progress ?? null);
    const w = d?.weather;

    return (
      <section className={`Homecoming Homecoming-card Homecoming-compact Homecoming--${this.attrs.host}`} aria-label={greeting()}>
        <div className="Homecoming-who">
          <span className="Homecoming-ringWrap">
            {c ? (
              <svg
                className="Homecoming-ring"
                viewBox="0 0 64 64"
                role="img"
                aria-label={c.next ? tText('progress_label', { rank: c.next }) : tText('top_rank')}
              >
                <circle className="Homecoming-ringTrack" cx="32" cy="32" r={R} />
                <circle className="Homecoming-ringFill" cx="32" cy="32" r={R} style={{ strokeDasharray: `${(c.pct / 100) * C} ${C}` }} />
              </svg>
            ) : null}
            <Avatar user={user} className="Homecoming-avatar" />
          </span>
          <div className="Homecoming-whoText">
            <span className="Homecoming-muted">{greeting()},</span>
            <strong className="Homecoming-name">{user.displayName()}</strong>
            <span className="Homecoming-meta">
              {PillBadge(pill(user, d?.progress ?? null), true)}
              {c?.next ? <span className="Homecoming-small">{t('to_next_short', { count: count(c.remaining, Infinity), rank: c.next })}</span> : null}
            </span>
          </div>
        </div>
        {w && d?.place ? (
          <div className="Homecoming-wx">
            <Icon name={weatherIcon(w.code, w.day)} className="Homecoming-wxIcon" />
            <div className="Homecoming-wxText">
              <span>
                <strong>{degrees(w.temp)}</strong> <span className="Homecoming-muted">{tText(`weather.${condition(w.code)}`)}</span>
              </span>
              <span className="Homecoming-small">
                {shortPlace(d.place.label)}
                {w.high !== null && w.low !== null
                  ? ' · ' + tText('weather.high_low', { high: degrees(w.high, false), low: degrees(w.low, false) })
                  : ''}
              </span>
            </div>
            {LocateButton(d)}
          </div>
        ) : (
          LocateButton(d)
        )}
        {Counts(d, 'rows')}
        {d?.stats?.length ? (
          <dl className="Homecoming-grid Homecoming-grid--mini">
            {d.stats.map((s) => (
              <div className="Homecoming-stat" key={s.key}>
                <dt>{statLabel(s)}</dt>
                <dd>{statValue(s)}</dd>
              </div>
            ))}
          </dl>
        ) : null}
        <div className="Homecoming-actions Homecoming-actions--compact">
          {canStart() ? (
            <button type="button" className="Button Button--primary" onclick={startDiscussion}>
              {t('start_discussion')}
            </button>
          ) : null}
          <Link className="Button" href={links.newPosts()}>
            {t('new_posts')}
          </Link>
        </div>
      </section>
    );
  }
}
