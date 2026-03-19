'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Configurable calendar system
// Shared by app.js (client) — loaded via <script> before app.js.
// ══════════════════════════════════════════════════════════════════════════════

// Default Gregorian calendar; overridden by settings.calendar at boot.
// eslint-disable-next-line no-unused-vars
let CALENDAR = {
  months: [
    { name: 'Jan', days: 31 }, { name: 'Feb', days: 28 }, { name: 'Mar', days: 31 },
    { name: 'Apr', days: 30 }, { name: 'May', days: 31 }, { name: 'Jun', days: 30 },
    { name: 'Jul', days: 31 }, { name: 'Aug', days: 31 }, { name: 'Sep', days: 30 },
    { name: 'Oct', days: 31 }, { name: 'Nov', days: 30 }, { name: 'Dec', days: 31 },
  ],
  hours_per_day: 24,
  minutes_per_hour: 60,
  weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
  epoch_weekday: 0,
  weekdays_reset_each_month: false,
  eras: [],                  // [{name, abbreviation, direction:'forward'|'backward', start_year}]
  zero_year_exists: false,
  moons: [{name: 'Moon', cycle_minutes: '42524.0463', phase_offset: '15238', color: '#fff'}],                 // [{name, cycle_minutes, phase_offset, color}]
  date_format: 'D MMMM YYYY',
  time_format: '24h',        // '24h' or '12h'
};

// ── Calendar helpers ─────────────────────────────────────────────────────────

function calDaysInYear()   { return CALENDAR.months.reduce((s, m) => s + m.days, 0); }
function calDaysInMonth(m) { return CALENDAR.months[m - 1]?.days ?? 30; }
function calMonthCount()   { return CALENDAR.months.length; }
function calMinutesPerDay() { return CALENDAR.hours_per_day * CALENDAR.minutes_per_hour; }

function dayOfYear(month, day) {
  let d = 0;
  for (let m = 1; m < month; m++) d += calDaysInMonth(m);
  return d + day;
}

// ── Weekday calculation ──────────────────────────────────────────────────────

function weekdayOf(year, month, day) {
  const wd = CALENDAR.weekdays;
  if (!wd || !wd.length) return null;
  if (CALENDAR.weekdays_reset_each_month) {
    return wd[((day - 1) + (CALENDAR.epoch_weekday || 0)) % wd.length];
  }
  // Total days from epoch (Year 1, Day 1) to this date
  const diy = calDaysInYear();
  const totalDays = (year - 1) * diy + dayOfYear(month || 1, day || 1) - 1;
  const idx = (((totalDays % wd.length) + wd.length) + (CALENDAR.epoch_weekday || 0)) % wd.length;
  return wd[idx];
}

function weekdayIndex(year, month, day) {
  const wd = CALENDAR.weekdays;
  if (!wd || !wd.length) return -1;
  if (CALENDAR.weekdays_reset_each_month) {
    return ((day - 1) + (CALENDAR.epoch_weekday || 0)) % wd.length;
  }
  const diy = calDaysInYear();
  const totalDays = (year - 1) * diy + dayOfYear(month || 1, day || 1) - 1;
  return (((totalDays % wd.length) + wd.length) + (CALENDAR.epoch_weekday || 0)) % wd.length;
}

// ── Era calculation ──────────────────────────────────────────────────────────

// Convert an absolute year to an era-relative display year + era info.
// Returns { displayYear, era } where era = { name, abbreviation, direction }.
// If no eras configured, returns { displayYear: absoluteYear, era: null }.
function yearToEra(absoluteYear) {
  const eras = CALENDAR.eras;
  if (!eras || !eras.length) return { displayYear: absoluteYear, era: null };

  // Find the matching era: last forward era whose start_year <= absoluteYear,
  // or the backward era if absoluteYear < all forward eras.
  const forward = eras.filter(e => e.direction === 'forward').sort((a, b) => b.start_year - a.start_year);
  const backward = eras.find(e => e.direction === 'backward');

  for (const era of forward) {
    if (absoluteYear >= era.start_year) {
      const offset = CALENDAR.zero_year_exists ? 0 : 1;
      return { displayYear: absoluteYear - era.start_year + offset, era };
    }
  }

  if (backward) {
    const offset = CALENDAR.zero_year_exists ? 0 : 1;
    const refYear = forward.length ? forward[forward.length - 1].start_year : 1;
    return { displayYear: refYear - absoluteYear + (1 - offset), era: backward };
  }

  return { displayYear: absoluteYear, era: null };
}

// Format a year number with era suffix if configured.
function formatYearEra(y) {
  const { displayYear, era } = yearToEra(y);
  const yStr = formatYear(displayYear);
  if (!era) return formatYear(y);
  return `${yStr} ${era.abbreviation}`;
}

// ── Moon phase calculation ───────────────────────────────────────────────────

// Total minutes from epoch (Year 1, Day 1, 00:00) to the given date.
function totalMinutesFromEpoch(year, month, day, hour, minute) {
  const diy = calDaysInYear();
  const mpd = calMinutesPerDay();
  const totalDays = (year - 1) * diy + dayOfYear(month || 1, day || 1) - 1;
  return totalDays * mpd + (hour || 0) * (CALENDAR.minutes_per_hour) + (minute || 0);
}

// Moon phase at a given date. Returns 0..1 (0 = new moon, 0.5 = full moon).
function moonPhase(moon, year, month, day, hour, minute) {
  const totalMin = totalMinutesFromEpoch(year, month, day, hour, minute);
  const adjusted = totalMin - (moon.phase_offset || 0);
  const cycle = moon.cycle_minutes || 1;
  return ((adjusted % cycle) + cycle) % cycle / cycle;
}

// Moon phase emoji for display
function moonPhaseEmoji(phase) {
  if (phase < 0.0625) return '\u{1F311}'; // new
  if (phase < 0.1875) return '\u{1F312}'; // waxing crescent
  if (phase < 0.3125) return '\u{1F313}'; // first quarter
  if (phase < 0.4375) return '\u{1F314}'; // waxing gibbous
  if (phase < 0.5625) return '\u{1F315}'; // full
  if (phase < 0.6875) return '\u{1F316}'; // waning gibbous
  if (phase < 0.8125) return '\u{1F317}'; // last quarter
  if (phase < 0.9375) return '\u{1F318}'; // waning crescent
  return '\u{1F311}'; // new
}

// ── Date ↔ decimal conversion ────────────────────────────────────────────────

function dateToDecimal(year, month, day, hour, minute) {
  month  = month  ?? 0;
  day    = day    ?? 0;
  hour   = hour   ?? 0;
  minute = minute ?? 0;
  if (!month && !day && !hour && !minute) return year;
  const m = month || 1, d = day || 1;
  const doy = dayOfYear(m, d);
  const diy = calDaysInYear();
  const hpd = CALENDAR.hours_per_day;
  const mph = CALENDAR.minutes_per_hour;
  return year + (doy - 1 + hour / hpd + minute / (hpd * mph)) / diy;
}

function decimalToDate(v) {
  if (v == null) return { year: 0, month: 0, day: 0, hour: 0, minute: 0 };
  const year = Math.floor(v);
  const frac = v - year;
  if (Math.abs(frac) < 1e-9) return { year, month: 0, day: 0, hour: 0, minute: 0 };
  const diy = calDaysInYear();
  const mpd = calMinutesPerDay();
  let remaining = Math.round(frac * diy * mpd) / mpd; // snap to nearest minute
  let doy = Math.floor(remaining) + 1;
  let dayFrac = remaining - Math.floor(remaining);
  if (dayFrac > (1 - 1 / mpd)) { doy++; dayFrac = 0; }
  let month = 1;
  const mc = calMonthCount();
  for (let m = 1; m <= mc; m++) {
    const dim = calDaysInMonth(m);
    if (doy <= dim) { month = m; break; }
    doy -= dim;
  }
  const hpd = CALENDAR.hours_per_day;
  const mph = CALENDAR.minutes_per_hour;
  let hour   = Math.floor(dayFrac * hpd);
  let minute = Math.round((dayFrac * hpd - hour) * mph);
  // Roll over floating-point rounding artifacts (e.g. minute=60, hour=24)
  if (minute >= mph) { minute = 0; hour++; }
  if (hour >= hpd)   { hour = 0; doy++; }
  return { year, month, day: doy, hour, minute };
}

// ── Formatting ───────────────────────────────────────────────────────────────

function formatYear(y) {
  const abs = Math.abs(y);
  if (abs >= 1_000_000_000) return (y / 1_000_000_000).toFixed(1).replace(/\.0$/, '') + 'B';
  if (abs >= 1_000_000)     return (y / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (abs >= 10_000)        return (y / 1_000).toFixed(1).replace(/\.0$/, '') + 'k';
  return String(y);
}

// ── Format string parser ─────────────────────────────────────────────────────

function ordinalSuffix(n) {
  const s = ['th','st','nd','rd'];
  const v = n % 100;
  return s[(v - 20) % 10] || s[v] || s[0];
}

// Format a date using CALENDAR.date_format tokens.
// Accepts a decimal value OR a {year, month, day, hour, minute} object.
function formatDateString(d, fmt) {
  const { displayYear, era } = yearToEra(d.year);
  const mObj = CALENDAR.months[d.month - 1];
  const mName = mObj?.name ?? `M${d.month}`;
  const mAbbr = mName.substring(0, 3);

  const is12h = (CALENDAR.time_format === '12h');
  const h24 = d.hour || 0;
  const h12 = h24 % 12 || 12;
  const ampm = h24 < 12 ? 'AM' : 'PM';

  // Process [literal] blocks first — replace with placeholders
  const literals = [];
  let fmtClean = fmt.replace(/\[([^\]]*)\]/g, (_, txt) => {
    literals.push(txt);
    return `\x00${literals.length - 1}\x00`;
  });

  // Replace tokens from longest to shortest to avoid partial matches.
  // Each substituted value is stored as a placeholder to prevent later tokens
  // from corrupting already-replaced text (e.g. 'M' matching inside "May").
  const subs = [];
  function ph(val) { subs.push(val); return `\x01${subs.length - 1}\x01`; }

  const tokens = [
    ['MMMM', () => ph(mName)],
    ['MMM',  () => ph(mAbbr)],
    ['MM',   () => ph(String(d.month).padStart(2, '0'))],
    ['M',    () => ph(String(d.month))],
    ['DD',   () => ph(String(d.day || 1).padStart(2, '0'))],
    ['D',    () => ph(String(d.day || 1))],
    ['YBIG', () => ph(formatYear(displayYear))],
    ['SYYYY',() => ph((d.year < 0 ? '-' : '') + String(Math.abs(displayYear)))],
    ['YYYY', () => ph(era ? String(Math.abs(displayYear)) : formatYear(d.year))],
    ['YY',   () => ph(String(Math.abs(displayYear) % 100).padStart(2, '0'))],
    ['EE',   () => ph(era?.name ?? '')],
    ['E',    () => ph(era?.abbreviation ?? '')],
    ['HH',   () => ph(String(is12h ? h12 : h24).padStart(2, '0'))],
    ['H',    () => ph(String(is12h ? h12 : h24))],
    ['hh',   () => ph(String(h12).padStart(2, '0'))],
    ['h',    () => ph(String(h12))],
    ['mm',   () => ph(String(d.minute || 0).padStart(2, '0'))],
    ['A',    () => ph(ampm)],
    ['a',    () => ph(ampm.toLowerCase())],
  ];

  for (const [tok, valFn] of tokens) {
    if (fmtClean.includes(tok)) fmtClean = fmtClean.split(tok).join(valFn());
  }

  // Restore placeholders
  fmtClean = fmtClean.replace(/\x01(\d+)\x01/g, (_, i) => subs[parseInt(i, 10)]);

  // Handle ^ (ordinal suffix for the number immediately before it)
  fmtClean = fmtClean.replace(/(\d+)\^/g, (_, num) => num + ordinalSuffix(parseInt(num, 10)));

  // Restore literals
  fmtClean = fmtClean.replace(/\x00(\d+)\x00/g, (_, i) => literals[parseInt(i, 10)]);

  return fmtClean.trim();
}

// High-level formatDate: uses format string when date has sub-year precision,
// falls back to year-only display for year-only dates.
function formatDate(v) {
  if (v == null) return '';
  const d = (typeof v === 'object') ? v : decimalToDate(v);
  if (!d.month) return formatYearEra(d.year);
  const fmt = CALENDAR.date_format || 'D MMMM YYYY';
  const hasTime = d.hour || d.minute;
  const fullFmt = hasTime ? fmt + ' HH:mm' : fmt;
  return formatDateString(d, fullFmt);
}

// Format a node's start or end date using stored components (preferred) or REAL fallback.
function formatNodeDate(node, which = 'start') {
  const y = node[`${which}_year`];
  if (y != null) {
    return formatDate({ year: y, month: node[`${which}_month`] || 0,
      day: node[`${which}_day`] || 0, hour: node[`${which}_hour`] || 0,
      minute: node[`${which}_minute`] || 0 });
  }
  return formatDate(node[`${which}_date`]);
}

function formatRulerTick(decVal, step) {
  if (step >= 1) return formatYearEra(decVal);
  const d = decimalToDate(decVal);
  const mc = calMonthCount();
  const diy = calDaysInYear();
  const yStr = formatYearEra(d.year);
  const mName = CALENDAR.months[d.month - 1]?.name ?? `M${d.month}`;
  if (step >= 1/mc)  return `${mName} ${yStr}`;
  if (step >= 1/diy) return `${d.day} ${mName} ${yStr}`;
  const hh = String(d.hour).padStart(2, '0');
  const mm = String(d.minute).padStart(2, '0');
  return `${d.day} ${mName} ${hh}:${mm}`;
}

// ── Ruler step picking ───────────────────────────────────────────────────────

function subYearSteps() {
  const diy = calDaysInYear();
  const mc  = calMonthCount();
  const mpd = calMinutesPerDay();
  const hpd = CALENDAR.hours_per_day;
  const totalMin = diy * mpd;
  return [
    1 / totalMin, 5 / totalMin, 15 / totalMin,         // 1, 5, 15 minutes
    1 / (diy * hpd), 6 / (diy * hpd),                  // 1, 6 hours
    1 / diy, 7 / diy,                                   // 1 day, 1 week
    1 / mc, 3 / mc, mc > 4 ? 6 / mc : null,            // 1, 3, 6 months
  ].filter(Boolean);
}

function pickStep(spanYears, ppy) {
  for (const s of subYearSteps())
    if (s * ppy >= 90) return s;
  for (const s of [1,2,5,10,25,50,100,200,500,1000,2000,5000,10000,50000,100000,
                   200000,500000,1000000,5000000,10000000,100000000,500000000])
    if (s * ppy >= 90) return s;
  return Math.pow(10, Math.ceil(Math.log10(Math.max(1, spanYears) / 10)));
}

// Return the next "whole" interval above the current step, for grid emphasis.
// minutes → hours, hours → days, days → months, months → years, years → next power-of-10.
function parentStep(step) {
  const diy = calDaysInYear();
  const mc  = calMonthCount();
  const hpd = CALENDAR.hours_per_day;
  const eps = 1e-12;
  const hourFrac  = 1 / (diy * hpd);
  const dayFrac   = 1 / diy;
  const monthFrac = 1 / mc;
  if (step < hourFrac - eps)  return hourFrac;
  if (step < dayFrac - eps)   return dayFrac;
  if (step < monthFrac - eps) return monthFrac;
  if (step < 1 - eps)         return 1;
  return Math.pow(10, Math.floor(Math.log10(step + eps)) + 1);
}

// ── Date field helpers (for the node modal form) ─────────────────────────────

// Populate a month <select> with options from CALENDAR.months.
function populateMonthSelect(selectEl) {
  const prev = selectEl.value;
  selectEl.innerHTML = '<option value="">—</option>';
  CALENDAR.months.forEach((m, i) => {
    const opt = document.createElement('option');
    opt.value = i + 1;
    opt.textContent = m.name;
    selectEl.appendChild(opt);
  });
  selectEl.value = prev;
}

// Update the day input's max attribute based on the selected month.
function syncDayMax(prefix) {
  const mVal = parseInt(document.getElementById(`${prefix}-month`).value, 10);
  const dayEl = document.getElementById(`${prefix}-day`);
  const max = mVal ? calDaysInMonth(mVal) : 31;
  dayEl.max = max;
  if (parseInt(dayEl.value, 10) > max) dayEl.value = max;
}

// Wire up month→day sync for a date field group.
function initDateFieldSync(prefix) {
  const monthEl = document.getElementById(`${prefix}-month`);
  populateMonthSelect(monthEl);
  monthEl.addEventListener('change', () => syncDayMax(prefix));
  // Also update hour max from calendar
  const hourEl = document.getElementById(`${prefix}-hour`);
  if (hourEl) hourEl.max = CALENDAR.hours_per_day - 1;
  const minEl = document.getElementById(`${prefix}-minute`);
  if (minEl) minEl.max = CALENDAR.minutes_per_hour - 1;
}

function setDateFields(prefix, decimal) {
  const d = decimalToDate(decimal);
  populateMonthSelect(document.getElementById(`${prefix}-month`));
  document.getElementById(`${prefix}-year`).value   = d.year;
  document.getElementById(`${prefix}-month`).value  = d.month || '';
  document.getElementById(`${prefix}-day`).value    = d.day   || '';
  document.getElementById(`${prefix}-hour`).value   = d.hour  || '';
  document.getElementById(`${prefix}-minute`).value = d.minute || '';
  syncDayMax(prefix);
}

function clearDateFields(prefix) {
  populateMonthSelect(document.getElementById(`${prefix}-month`));
  document.getElementById(`${prefix}-year`).value = '';
  document.getElementById(`${prefix}-month`).value = '';
  document.getElementById(`${prefix}-day`).value = '';
  document.getElementById(`${prefix}-hour`).value = '';
  document.getElementById(`${prefix}-minute`).value = '';
}

function readDateFields(prefix) {
  const y = document.getElementById(`${prefix}-year`).value;
  if (y === '') return NaN;
  return dateToDecimal(
    parseInt(y, 10),
    parseInt(document.getElementById(`${prefix}-month`).value, 10) || 0,
    parseInt(document.getElementById(`${prefix}-day`).value, 10)   || 0,
    parseInt(document.getElementById(`${prefix}-hour`).value, 10)  || 0,
    parseInt(document.getElementById(`${prefix}-minute`).value, 10) || 0
  );
}
