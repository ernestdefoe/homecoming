# Homecoming: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Every change goes to the Command Center session for review first. Deploy (Ernest's decision, 2026-11-13): after his test on dev, ALL his production forums (ernestdefoe.online, fbsfb, wowcraft, mailwright) plus dev and the showcase; sites without Bespoke need fof/forum-widgets-core unless their theme has widget zones.

## Design rules that exist for a reason

- **The page never waits on us.** forum.js (~8 KB) only registers hosts and draws a `Piece` wrapper; the strip, cards, guest nudge and weather settings are one lazy chunk (`js/dist/forum/components.js`, declared with `jsDirectory` or it 404s). Greeting, name, avatar, group and notification count come from the session user; the rest is ONE `GET /api/homecoming` per member, kept in a module store (`store.ts`) for two minutes, never in component state (Bespoke remounts zones on redraw). A chunk that fails to load is not retried on every redraw.
- **Nothing for guests** unless the nudge is on (drawn from no data, no request).
- **`Dashboard`** (unread + stats + progress) is cached per member id for 120 s with the member's `marked_all_as_read_at` stored beside it, so "mark all as read" rebuilds with no event; reading a discussion or posting forgets it. One key per member (file caches keep a file per key forever).
- **Unread** = core's rule (`getReadIdsQuery`, `marked_all_as_read_at`) + `whereVisibleTo`, never hidden or private, minus flarum/subscriptions "ignore", within `unread_days`; counted up to 100 (`99+`).
- **Progress** order: Renown (columns on the user row; bar spans the member's RANK, 5 levels), Ladder (`Ladder::standing`), FoF Gamification (`users.votes` + `ranks`, cached 10 min). Renown rank names are drawn client-side (admin's custom name or the language pack's).
- 🚨 **Weather is read from the cache only** (Command Center rule). `Forecasts::for` returns the cached forecast or null; a miss queues ONE `FetchForecast` per area (cache `add` marker, 120 s), capped per minute forum-wide (one key), 3 s timeout, failures remembered 10 min. Areas are a tenth of a degree. On a SyncQueue (no worker, looked up through core's RoutingQueue) the fetch runs in a shutdown function after `fastcgi_finish_request`, never while the member waits. Only rounded coordinates go to Open-Meteo.
- **Places**: the member's own town (`homecoming_places`, picked from a server-side geocoder search, 10/min per member) wins over their Member Map place.
- **Hosts** (`hosts.tsx`): widgets-core with the Bespoke contract — `isDisabled: !placed() || !hasContent()` (Bespoke marks the widget placed while it asks, so no `ctx` branch); the strip's `zones` are full-width only. Without widgets-core, the same descriptors go on `BespokeWidgetQueue` with `extension`. 🚨 Never push an old native `{type,label}` Bespoke def (Bespoke would skip our widgets-core ones). Page Builder: server blocks (`Widget\*Block`, resolve returns nothing) + `PageBuilderBlockQueue` + direct register for <3.10. The stock strip stands down while ANY host has a piece mounted (`placement.ts`).
- **Looks**: one box only, the outer card (Ernest, 2026-11-13: no box-in-a-box). Bespoke draws its own card, so `.Homecoming--bespoke` drops ours. Colours only from Flarum 2 CSS properties; link rules start with `html:not(#x-hi)`.
- extend.php uses string literals for setting keys.

## Testing

- `composer test:setup && composer test` (SQLite: `touch` the DB file first; MySQL: `DB_DRIVER=mysql DB_PREFIX=dev_`, check `vendor/flarum/testing/src/integration/tmp/config.php`), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum `~/Herd/hcdev` (https://hcdev.test, prefix `lr_`, cloned from lrdev: Bespoke, Page Builder, FoF Widgets, Likes, Renown). Shots, measure (queries), settings typing test, guest and admin checks in `~/github/scopes/homecoming`.
