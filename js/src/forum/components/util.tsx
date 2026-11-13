import app from 'flarum/forum/app';
import type User from 'flarum/common/models/User';
import type Group from 'flarum/common/models/Group';
import type { Progress, Stat } from '../store';

export const EXT = 'ernestdefoe-homecoming';

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`${EXT}.forum.${key}`, params);
export const tText = (key: string, params: Record<string, unknown> = {}) => String(app.translator.trans(`${EXT}.forum.${key}`, params, true));

/** By the member's own clock. */
export function greeting(): string {
  const h = new Date().getHours();
  return tText(`greeting.${h >= 5 && h < 12 ? 'morning' : h >= 12 && h < 17 ? 'afternoon' : 'evening'}`);
}

export function firstName(user: User): string {
  return String(user.displayName() || user.username() || '').split(/\s+/)[0];
}

const format = (n: number) => new Intl.NumberFormat(app.translator.getLocale?.() || undefined).format(n);

export const count = (n: number, cap = 100) => (n >= cap ? `${cap - 1}+` : format(n));

/** Renown's rank name: the admin's, else the language pack's. */
function renownRankName(rank: number): string {
  const custom = app.forum.attribute<any>('renown')?.ranks?.[rank - 1]?.name;
  return custom || String(app.translator.trans(`ernestdefoe-renown.lib.ranks.${rank}`, {}, true));
}

/** The member's most visible group: the first one that isn't "Member" or hidden. */
function topGroup(user: User): Group | null {
  const groups = (user.groups() || []) as Group[];
  return groups.find((g) => g && g.id() !== '3' && !(g as any).isHidden?.()) || null;
}

export interface Pill {
  name: string;
  color: string | null;
  icon: string | null;
}

/** A rank from the points extension, else the top group, else nothing. */
export function pill(user: User, progress: Progress | null): Pill | null {
  if (progress?.source === 'renown' && progress.rank) return { name: renownRankName(progress.rank), color: null, icon: 'fas fa-star' };
  if (progress?.name) return { name: progress.name, color: progress.color || null, icon: 'fas fa-star' };
  const group = topGroup(user);
  return group ? { name: group.nameSingular(), color: group.color() || null, icon: group.icon() || null } : null;
}

export interface Climb {
  pct: number;
  remaining: number;
  next: string | null;
}

export function climb(p: Progress | null): Climb | null {
  if (!p) return null;
  const next = p.source === 'renown' ? (p.nextRank ? renownRankName(p.nextRank) : null) : p.nextName || null;
  if (p.max === null || p.max === undefined || !next) return { pct: 100, remaining: 0, next: null };
  const span = Math.max(1, p.max - p.min);
  return {
    pct: Math.max(0, Math.min(100, Math.round(((p.points - p.min) / span) * 100))),
    remaining: Math.max(0, p.max - p.points),
    next,
  };
}

export function statLabel(stat: Stat) {
  return t(`stats.${stat.key}`);
}

export function statValue(stat: Stat): string {
  if (stat.value === null || stat.value === undefined) return '–';
  if (stat.key === 'joined') return dayjs(String(stat.value)).format('MMM YYYY');
  return format(Number(stat.value));
}

/** WMO weather codes, as Open-Meteo sends them. */
export function condition(code: number): string {
  if (code === 0) return 'clear';
  if (code === 1) return 'mostly_clear';
  if (code === 2) return 'partly_cloudy';
  if (code === 3) return 'cloudy';
  if (code === 45 || code === 48) return 'fog';
  if (code >= 51 && code <= 57) return 'drizzle';
  if (code === 66 || code === 67) return 'freezing_rain';
  if (code >= 61 && code <= 65) return 'rain';
  if (code >= 71 && code <= 77) return 'snow';
  if (code >= 80 && code <= 82) return 'showers';
  if (code === 85 || code === 86) return 'snow_showers';
  if (code >= 95) return 'thunderstorm';
  return 'cloudy';
}

export function weatherIcon(code: number, day: boolean): string {
  const c = condition(code);
  if (c === 'clear' || c === 'mostly_clear') return day ? 'fas fa-sun' : 'fas fa-moon';
  if (c === 'partly_cloudy') return day ? 'fas fa-cloud-sun' : 'fas fa-cloud-moon';
  if (c === 'fog') return 'fas fa-smog';
  if (c === 'drizzle' || c === 'rain' || c === 'freezing_rain') return 'fas fa-cloud-rain';
  if (c === 'showers') return 'fas fa-cloud-showers-heavy';
  if (c === 'snow' || c === 'snow_showers') return 'fas fa-snowflake';
  if (c === 'thunderstorm') return 'fas fa-cloud-bolt';
  return 'fas fa-cloud';
}

export function unit(): 'c' | 'f' {
  const own = app.session.user?.preferences()?.homecomingUnit;
  if (own === 'c' || own === 'f') return own;
  return app.forum.attribute('homecomingUnit') === 'f' ? 'f' : 'c';
}

/** °C in, the member's unit out. */
export function degrees(c: number | null, withUnit = true): string {
  if (c === null || c === undefined) return '';
  const u = unit();
  const n = Math.round(u === 'f' ? (c * 9) / 5 + 32 : c);
  return withUnit ? `${n}°${u === 'f' ? 'F' : 'C'}` : `${n}°`;
}

export const links = {
  unread: () => app.route('index', { q: 'is:unread' }),
  notifications: () => app.route('notifications'),
  newPosts: () => app.route('index', { sort: 'latest' }),
};

export const canStart = () => !!app.forum.attribute('canStartDiscussion');

export function startDiscussion(): void {
  if (!app.session.user) return;
  app.composer
    .load(() => (flarum as any).reg.asyncModuleImport('flarum/forum/components/DiscussionComposer'), { user: app.session.user })
    .then(() => app.composer.show());
}

export function showModal(name: 'LogInModal' | 'SignUpModal'): void {
  app.modal.show(() => (flarum as any).reg.asyncModuleImport(`flarum/forum/components/${name}`));
}
