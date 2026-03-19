'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Server-side calendar math (CommonJS)
// Used to compute REAL positioning values from date components.
// ══════════════════════════════════════════════════════════════════════════════

const DEFAULT_CALENDAR = {
  months: [
    { name: 'Jan', days: 31 }, { name: 'Feb', days: 28 }, { name: 'Mar', days: 31 },
    { name: 'Apr', days: 30 }, { name: 'May', days: 31 }, { name: 'Jun', days: 30 },
    { name: 'Jul', days: 31 }, { name: 'Aug', days: 31 }, { name: 'Sep', days: 30 },
    { name: 'Oct', days: 31 }, { name: 'Nov', days: 30 }, { name: 'Dec', days: 31 },
  ],
  hours_per_day: 24,
  minutes_per_hour: 60,
};

function calDaysInYear(cal)   { return cal.months.reduce((s, m) => s + m.days, 0); }
function calDaysInMonth(cal, m) { return cal.months[m - 1]?.days ?? 30; }

function dayOfYear(cal, month, day) {
  let d = 0;
  for (let m = 1; m < month; m++) d += calDaysInMonth(cal, m);
  return d + day;
}

function dateToDecimal(cal, year, month, day, hour, minute) {
  month  = month  ?? 0;
  day    = day    ?? 0;
  hour   = hour   ?? 0;
  minute = minute ?? 0;
  if (!month && !day && !hour && !minute) return year;
  const m = month || 1, d = day || 1;
  const doy = dayOfYear(cal, m, d);
  const diy = calDaysInYear(cal);
  const hpd = cal.hours_per_day;
  const mph = cal.minutes_per_hour;
  return year + (doy - 1 + hour / hpd + minute / (hpd * mph)) / diy;
}

function decimalToDate(cal, v) {
  if (v == null) return { year: 0, month: 0, day: 0, hour: 0, minute: 0 };
  const year = Math.floor(v);
  const frac = v - year;
  if (Math.abs(frac) < 1e-9) return { year, month: 0, day: 0, hour: 0, minute: 0 };
  const diy = calDaysInYear(cal);
  const mpd = cal.hours_per_day * cal.minutes_per_hour;
  let remaining = Math.round(frac * diy * mpd) / mpd;
  let doy = Math.floor(remaining) + 1;
  let dayFrac = remaining - Math.floor(remaining);
  if (dayFrac > (1 - 1 / mpd)) { doy++; dayFrac = 0; }
  let month = 1;
  const mc = cal.months.length;
  for (let m = 1; m <= mc; m++) {
    const dim = calDaysInMonth(cal, m);
    if (doy <= dim) { month = m; break; }
    doy -= dim;
  }
  const hpd = cal.hours_per_day;
  const mph = cal.minutes_per_hour;
  const hour   = Math.floor(dayFrac * hpd);
  const minute = Math.round((dayFrac * hpd - hour) * mph);
  return { year, month, day: doy, hour, minute };
}

module.exports = { DEFAULT_CALENDAR, dateToDecimal, decimalToDate, calDaysInYear, calDaysInMonth, dayOfYear };
