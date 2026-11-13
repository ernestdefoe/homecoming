import app from 'flarum/forum/app';
import Component from 'flarum/common/Component';
import FieldSet from 'flarum/common/components/FieldSet';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { dashboard, refresh } from '../store';
import { t, tText } from './util';

interface Place {
  label: string;
  lat: number;
  lng: number;
}

/** In the member's settings: pick a town for the weather, and °C or °F. */
export default class WeatherSettings extends Component {
  query = '';
  results: Place[] | null = null;
  error: string | null = null;
  busy = false;

  view(): Mithril.Children {
    const place = dashboard()?.place ?? null;
    const own = app.session.user!.preferences()?.homecomingUnit ?? '';

    return (
      <FieldSet className="Settings-homecoming Homecoming-settings" label={t('settings.heading')}>
        <p className="helpText">{t('settings.help')}</p>
        <p className="Homecoming-settingsNow">
          {place
            ? t(place.source === 'own' ? 'settings.using_own' : 'settings.using_map', { place: <strong>{place.label}</strong> })
            : t('settings.none')}
        </p>
        <div className="Form-group">
          <label for="homecoming-town">{t('settings.search_label')}</label>
          <div className="Homecoming-search">
            <input
              id="homecoming-town"
              className="FormControl"
              type="search"
              maxlength={80}
              placeholder={tText('settings.search_placeholder')}
              value={this.query}
              oninput={(e: InputEvent) => {
                this.query = (e.target as HTMLInputElement).value;
              }}
              onkeydown={(e: KeyboardEvent) => {
                // A block, never `e.key === 'Enter' && …`: a handler returning false swallows every other key.
                if (e.key === 'Enter') {
                  e.preventDefault();
                  this.search();
                }
              }}
            />
            <Button className="Button" loading={this.busy} disabled={this.query.trim().length < 2} onclick={() => this.search()}>
              {t('settings.search')}
            </Button>
          </div>
          {this.error ? <p className="Homecoming-error">{this.error}</p> : null}
          {this.results ? (
            this.results.length ? (
              <ul className="Homecoming-results">
                {this.results.map((p) => (
                  <li key={`${p.lat},${p.lng}`}>
                    <button type="button" className="Button Button--text Homecoming-result" onclick={() => this.pick(p)}>
                      {p.label}
                    </button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="helpText">{t('settings.no_results')}</p>
            )
          ) : null}
          {place?.source === 'own' ? (
            <Button className="Button Button--link" onclick={() => this.remove()}>
              {t('settings.remove')}
            </Button>
          ) : null}
        </div>
        <div className="Form-group">
          <label for="homecoming-unit">{t('settings.unit')}</label>
          <select id="homecoming-unit" className="FormControl" value={own} onchange={(e: Event) => this.unit((e.target as HTMLSelectElement).value)}>
            <option value="">{tText('settings.unit_default')}</option>
            <option value="c">{tText('settings.celsius')}</option>
            <option value="f">{tText('settings.fahrenheit')}</option>
          </select>
        </div>
      </FieldSet>
    );
  }

  search(): void {
    const q = this.query.trim();
    if (q.length < 2 || this.busy) return;
    this.busy = true;
    this.error = null;
    app
      .request<{ places: Place[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/homecoming/places',
        params: { q },
        errorHandler: () => {},
      })
      .then((r) => {
        this.results = r.places || [];
      })
      .catch(() => {
        this.results = null;
        this.error = tText('settings.unavailable');
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  pick(p: Place): void {
    app.request({ method: 'POST', url: app.forum.attribute('apiUrl') + '/homecoming/place', body: p }).then(() => {
      this.results = null;
      this.query = '';
      refresh();
    });
  }

  remove(): void {
    app.request({ method: 'DELETE', url: app.forum.attribute('apiUrl') + '/homecoming/place' }).then(() => refresh());
  }

  unit(value: string): void {
    app.session.user!.savePreferences({ homecomingUnit: value === 'c' || value === 'f' ? value : null }).then(() => m.redraw());
  }
}
