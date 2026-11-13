import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import { dashboard } from '../store';
import { ClimbBar, PillBadge } from './Bits';
import { count, pill, statLabel, statValue, t } from './util';

/** Rank, points and the bar to the next rank (with a points extension), then four stats. */
export default class StatsCard extends Component<{ host: string }> {
  view() {
    const user = app.session.user!;
    const d = dashboard();
    const p = d?.progress ?? null;

    return (
      <section className={`Homecoming Homecoming-card Homecoming-stats Homecoming--${this.attrs.host}`} aria-label={String(t('your_stats'))}>
        <h3 className="Homecoming-heading">{t('your_stats')}</h3>
        {p ? (
          <div className="Homecoming-rank">
            <div className="Homecoming-rankRow">
              {PillBadge(pill(user, p))}
              {p.source === 'renown' && p.level ? <span className="Homecoming-muted">{t('level', { level: p.level })}</span> : null}
              <span className="Homecoming-points">{t('points', { count: count(p.points, Infinity) })}</span>
            </div>
            {ClimbBar(d)}
          </div>
        ) : null}
        <dl className="Homecoming-grid">
          {(d?.stats || []).map((s) => (
            <div className="Homecoming-stat" key={s.key}>
              <dt>{statLabel(s)}</dt>
              <dd>{statValue(s)}</dd>
            </div>
          ))}
        </dl>
      </section>
    );
  }
}
