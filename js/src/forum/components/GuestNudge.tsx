import Component from 'flarum/common/Component';
import { showModal, t } from './util';

/** For guests, only when the admin turned it on. Drawn from no data. */
export default class GuestNudge extends Component<{ host: string }> {
  view() {
    return (
      <section className={`Homecoming Homecoming-strip Homecoming-guest Homecoming--${this.attrs.host}`}>
        <div className="Homecoming-guestText">
          <strong className="Homecoming-name">{t('guest.title')}</strong>
          <span className="Homecoming-muted">{t('guest.body')}</span>
        </div>
        <div className="Homecoming-actions">
          <button type="button" className="Button Button--primary" onclick={() => showModal('SignUpModal')}>
            {t('guest.sign_up')}
          </button>
          <button type="button" className="Button" onclick={() => showModal('LogInModal')}>
            {t('guest.log_in')}
          </button>
        </div>
      </section>
    );
  }
}
