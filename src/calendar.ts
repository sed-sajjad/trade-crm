import { isValidJalaaliDate, toGregorian, toJalaali } from 'jalaali-js';

export function westernDigits(value: string): string {
  return value.replace(/[۰-۹٠-٩]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩'.indexOf(digit) % 10));
}

export function jalaliDateFromUtc(value?: string | null): { date: string; time: string } {
  if (!value) return { date: '', time: '10:00' };
  const parsed = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
  if (Number.isNaN(parsed.getTime())) return { date: '', time: '10:00' };
  const parts = new Intl.DateTimeFormat('en-US', { timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(parsed);
  const p = Object.fromEntries(parts.map((part) => [part.type, part.value]));
  const j = toJalaali(Number(p.year), Number(p.month), Number(p.day));
  return { date: `${j.jy}/${String(j.jm).padStart(2, '0')}/${String(j.jd).padStart(2, '0')}`, time: `${p.hour}:${p.minute}` };
}

export function gregorianInputFromJalali(dateText: string): string {
  const match = westernDigits(dateText).trim().match(/^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$/);
  if (!match) return '';
  const [, jyText, jmText, jdText] = match;
  const jy = Number(jyText); const jm = Number(jmText); const jd = Number(jdText);
  if (!isValidJalaaliDate(jy, jm, jd)) return '';
  const date = toGregorian(jy, jm, jd);
  return `${date.gy}-${String(date.gm).padStart(2, '0')}-${String(date.gd).padStart(2, '0')}`;
}

export function jalaliDateFromGregorianInput(value: string): string {
  const match = westernDigits(value).trim().match(/^(\d{4})-(\d{2})-(\d{2})$/);
  if (!match) return '';
  const [, yearText, monthText, dayText] = match;
  const gy = Number(yearText); const gm = Number(monthText); const gd = Number(dayText);
  const check = new Date(Date.UTC(gy, gm - 1, gd));
  if (check.getUTCFullYear() !== gy || check.getUTCMonth() !== gm - 1 || check.getUTCDate() !== gd) return '';
  const date = toJalaali(gy, gm, gd);
  return `${date.jy}/${String(date.jm).padStart(2, '0')}/${String(date.jd).padStart(2, '0')}`;
}

export function isoFromJalali(dateText: string, timeText: string): string | null {
  const [jy, jm, jd] = westernDigits(dateText).split(/[/-]/).map(Number);
  const [hour, minute] = westernDigits(timeText || '10:00').split(':').map(Number);
  if (jy === undefined || jm === undefined || jd === undefined || hour === undefined || minute === undefined || !isValidJalaaliDate(jy, jm, jd) || !Number.isInteger(hour) || !Number.isInteger(minute) || hour > 23 || minute > 59) return null;
  const gregorian = toGregorian(jy, jm, jd);
  return new Date(Date.UTC(gregorian.gy, gregorian.gm - 1, gregorian.gd, hour, minute) - 210 * 60_000).toISOString();
}

export function formatDate(value?: string | null, includeTime = false): string {
  if (!value) return '—';
  const date = new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`);
  if (Number.isNaN(date.getTime())) return '—';
  return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { timeZone: 'Asia/Tehran', year: 'numeric', month: 'short', day: 'numeric', ...(includeTime ? { hour: '2-digit', minute: '2-digit' } : {}) }).format(date);
}

export function isOverdue(value?: string | null): boolean {
  return !!value && new Date(value.includes('T') ? value : `${value.replace(' ', 'T')}Z`).getTime() < Date.now();
}
