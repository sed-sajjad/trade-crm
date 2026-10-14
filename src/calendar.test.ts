import { describe, expect, it } from 'vitest';
import { formatDate, gregorianInputFromJalali, isoFromJalali, jalaliDateFromGregorianInput, jalaliDateFromUtc, westernDigits } from './calendar';

describe('Persian date and input helpers', () => {
  it('normalizes Persian and Arabic digits together', () => {
    expect(westernDigits('۰۹۱۲٣٤٥٦٧٨٩')).toBe('09123456789');
  });

  it('round-trips Jalali date and Tehran clock time through UTC', () => {
    const utc = isoFromJalali('۱۴۰۵/۰۷/۲۱', '09:45');
    expect(utc).toBe('2026-10-13T06:15:00.000Z');
    expect(jalaliDateFromUtc(utc)).toEqual({ date: '1405/07/21', time: '09:45' });
  });

  it('rejects invalid Jalali days and invalid clock values', () => {
    expect(isoFromJalali('1405/12/30', '12:00')).toBeNull();
    expect(isoFromJalali('1405/07/21', '25:00')).toBeNull();
  });

  it('converts between the selectable date input and Jalali CRM value', () => {
    expect(gregorianInputFromJalali('۱۴۰۵/۰۷/۲۱')).toBe('2026-10-13');
    expect(jalaliDateFromGregorianInput('2026-10-13')).toBe('1405/07/21');
    expect(gregorianInputFromJalali('1405/12/30')).toBe('');
    expect(jalaliDateFromGregorianInput('2026-02-30')).toBe('');
  });

  it('shows ISO timestamps using the Jalali calendar and Tehran timezone', () => {
    expect(formatDate('2026-10-13 06:15:00', true)).toContain('۱۴۰۵');
    expect(formatDate(null)).toBe('—');
  });
});
