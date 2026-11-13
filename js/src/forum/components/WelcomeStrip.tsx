import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import { dashboard } from '../store';
import { Counts, PillBadge, WeatherLine } from './Bits';
import { canStart, firstName, greeting, links, pill, startDiscussion, t } from './util';

/** Greeting, name, rank, unread and notifications, and the two buttons, in one row that wraps on narrow screens. */
export default class WelcomeStrip extends Component<{ host: string }> {
  view() {
    const user = app.session.user!;
    const d = dashboard();
    const h = new Date().getHours();
    const level = d?.progress?.source === 'renown' ? d.progress.level : null;

    return (
      <section
        className={`Homecoming Homecoming-strip Homecoming--${this.attrs.host}`}
        aria-label={String(t('welcome_back', { name: firstName(user) }))}
      >
        <div className="Homecoming-hello">
          <Avatar user={user} className="Homecoming-avatar" />
          <div className="Homecoming-helloText">
            <div className="Homecoming-greeting">
              <Icon name={h >= 6 && h < 19 ? 'fas fa-sun' : 'fas fa-moon'} className="Homecoming-greetingIcon" />
              <span>{greeting()}</span>
              {WeatherLine(d)}
            </div>
            <h2 className="Homecoming-title">{t('welcome_back', { name: firstName(user) })}</h2>
            <div className="Homecoming-meta">
              {PillBadge(pill(user, d?.progress ?? null))}
              {level ? <span className="Homecoming-muted">{t('level', { level })}</span> : null}
            </div>
          </div>
        </div>
        {Counts(d, 'tiles')}
        <div className="Homecoming-actions">
          {canStart() ? (
            <button type="button" className="Button Button--primary" onclick={startDiscussion}>
              <Icon name="fas fa-pen" className="Button-icon" />
              <span className="Button-label">{t('start_discussion')}</span>
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
