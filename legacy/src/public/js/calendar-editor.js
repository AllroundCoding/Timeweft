'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Time System Editor — tabbed calendar configuration UI
// Loaded via <script> after calendar.js and before app.js.
// ══════════════════════════════════════════════════════════════════════════════

let _ceWorkingConfig = null;  // working copy of calendar config (saved on Save)
let _cePresets = [];          // cached calendar presets
let _ceActiveTab = 'overview';

// ── Open / Close ─────────────────────────────────────────────────────────────

async function openCalendarEditor() {
  _cePresets = await apiFetch(`${API}/calendars`).then(r => r.json());
  const active = _cePresets.find(c => c.is_active) || _cePresets[0];
  _ceWorkingConfig = structuredClone(active?.config || CALENDAR);

  // Populate preset dropdown
  const sel = document.getElementById('ce-preset-select');
  sel.innerHTML = '';
  for (const c of _cePresets) {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name + (c.is_active ? ' ●' : '');
    sel.appendChild(opt);
  }
  sel.value = active?.id || '';
  document.getElementById('ce-preset-name').value = active?.name || '';

  ceRenderTab(_ceActiveTab);
  document.getElementById('ce-overlay').classList.add('open');
}

function closeCalendarEditor() {
  document.getElementById('ce-overlay').classList.remove('open');
}

// ── Tab rendering ────────────────────────────────────────────────────────────

function ceRenderTab(tab) {
  _ceActiveTab = tab;
  document.querySelectorAll('.ce-tab').forEach(t =>
    t.classList.toggle('active', t.dataset.tab === tab));
  const body = document.getElementById('ce-tab-body');
  body.innerHTML = '';
  switch (tab) {
    case 'overview': ceRenderOverview(body); break;
    case 'months':   ceRenderMonths(body);   break;
    case 'weeks':    ceRenderWeeks(body);    break;
    case 'days':     ceRenderDays(body);      break;
    case 'eras':     ceRenderEras(body);      break;
    case 'moons':    ceRenderMoons(body);     break;
    case 'display':  ceRenderDisplay(body);   break;
  }
}

// ── Overview tab ─────────────────────────────────────────────────────────────

function ceRenderOverview(container) {
  const c = _ceWorkingConfig;
  const diy = c.months.reduce((s, m) => s + m.days, 0);
  const mc = c.months.length;
  const hpd = c.hours_per_day;
  const mph = c.minutes_per_hour;
  const mpd = hpd * mph;
  const avgDpm = mc > 0 ? (diy / mc).toFixed(1) : '—';

  const wdc = (c.weekdays || []).length;
  const stats = [
    ['Months per year', mc],
    ['Days per year', diy],
    ['Avg days per month', avgDpm],
    ['Days per week', wdc || '—'],
    ['Hours per day', hpd],
    ['Minutes per hour', mph],
    ['Minutes per day', mpd],
    ['Hours per year', diy * hpd],
  ];

  const grid = document.createElement('div');
  grid.className = 'ce-stat-grid';
  for (const [label, value] of stats) {
    const card = document.createElement('div');
    card.className = 'ce-stat-card';
    card.innerHTML = `<div class="ce-stat-label">${label}</div><div class="ce-stat-value">${value}</div>`;
    grid.appendChild(card);
  }
  container.appendChild(grid);
}

// ── Months tab ───────────────────────────────────────────────────────────────

function ceRenderMonths(container) {
  const c = _ceWorkingConfig;
  const list = document.createElement('div');
  list.className = 'ce-month-list';

  // Header
  const hdr = document.createElement('div');
  hdr.className = 'ce-month-row ce-month-hdr';
  hdr.innerHTML = '<span class="ce-month-idx">#</span><span class="ce-month-name">Month</span><span class="ce-month-days">Days</span><span class="ce-month-actions"></span>';
  list.appendChild(hdr);

  c.months.forEach((m, i) => {
    const row = document.createElement('div');
    row.className = 'ce-month-row';
    row.draggable = true;
    row.dataset.idx = i;

    const idx = document.createElement('span');
    idx.className = 'ce-month-idx';
    idx.textContent = i + 1;

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'ce-month-name';
    nameInput.value = m.name;
    nameInput.addEventListener('change', () => { c.months[i].name = nameInput.value; });

    const daysInput = document.createElement('input');
    daysInput.type = 'number';
    daysInput.className = 'ce-month-days';
    daysInput.min = '1';
    daysInput.value = m.days;
    daysInput.addEventListener('change', () => {
      c.months[i].days = parseInt(daysInput.value, 10) || 1;
    });

    const actions = document.createElement('span');
    actions.className = 'ce-month-actions';

    if (i > 0) {
      const upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'ce-btn-icon';
      upBtn.textContent = '▲';
      upBtn.title = 'Move up';
      upBtn.addEventListener('click', () => {
        [c.months[i - 1], c.months[i]] = [c.months[i], c.months[i - 1]];
        ceRenderTab('months');
      });
      actions.appendChild(upBtn);
    }

    if (i < c.months.length - 1) {
      const downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'ce-btn-icon';
      downBtn.textContent = '▼';
      downBtn.title = 'Move down';
      downBtn.addEventListener('click', () => {
        [c.months[i], c.months[i + 1]] = [c.months[i + 1], c.months[i]];
        ceRenderTab('months');
      });
      actions.appendChild(downBtn);
    }

    if (c.months.length > 1) {
      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'ce-btn-icon ce-btn-danger';
      delBtn.textContent = '✕';
      delBtn.title = 'Remove month';
      delBtn.addEventListener('click', () => {
        c.months.splice(i, 1);
        ceRenderTab('months');
      });
      actions.appendChild(delBtn);
    }

    row.append(idx, nameInput, daysInput, actions);
    list.appendChild(row);
  });

  container.appendChild(list);

  // Add month button
  const addBtn = document.createElement('button');
  addBtn.type = 'button';
  addBtn.className = 'btn btn-accent';
  addBtn.textContent = '+ Add Month';
  addBtn.style.marginTop = '12px';
  addBtn.addEventListener('click', () => {
    c.months.push({ name: `Month ${c.months.length + 1}`, days: 30 });
    ceRenderTab('months');
  });
  container.appendChild(addBtn);

  // Summary
  const diy = c.months.reduce((s, m) => s + m.days, 0);
  const summary = document.createElement('div');
  summary.className = 'ce-summary';
  summary.textContent = `${c.months.length} months, ${diy} days per year`;
  container.appendChild(summary);
}

// ── Weeks tab ────────────────────────────────────────────────────────────────

function ceRenderWeeks(container) {
  const c = _ceWorkingConfig;
  if (!c.weekdays) c.weekdays = [];
  if (c.epoch_weekday == null) c.epoch_weekday = 0;
  if (c.weekdays_reset_each_month == null) c.weekdays_reset_each_month = false;

  // Weekday list
  const list = document.createElement('div');
  list.className = 'ce-month-list'; // reuse month list styling

  const hdr = document.createElement('div');
  hdr.className = 'ce-month-row ce-month-hdr';
  hdr.innerHTML = '<span class="ce-month-idx">#</span><span class="ce-month-name">Weekday</span><span class="ce-month-days"></span><span class="ce-month-actions"></span>';
  list.appendChild(hdr);

  c.weekdays.forEach((name, i) => {
    const row = document.createElement('div');
    row.className = 'ce-month-row';

    const idx = document.createElement('span');
    idx.className = 'ce-month-idx';
    idx.textContent = i + 1;

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'ce-month-name';
    nameInput.value = name;
    nameInput.addEventListener('change', () => { c.weekdays[i] = nameInput.value; });

    const spacer = document.createElement('span');
    spacer.className = 'ce-month-days';

    const actions = document.createElement('span');
    actions.className = 'ce-month-actions';

    if (i > 0) {
      const upBtn = document.createElement('button');
      upBtn.type = 'button'; upBtn.className = 'ce-btn-icon'; upBtn.textContent = '▲'; upBtn.title = 'Move up';
      upBtn.addEventListener('click', () => { [c.weekdays[i-1], c.weekdays[i]] = [c.weekdays[i], c.weekdays[i-1]]; ceRenderTab('weeks'); });
      actions.appendChild(upBtn);
    }
    if (i < c.weekdays.length - 1) {
      const downBtn = document.createElement('button');
      downBtn.type = 'button'; downBtn.className = 'ce-btn-icon'; downBtn.textContent = '▼'; downBtn.title = 'Move down';
      downBtn.addEventListener('click', () => { [c.weekdays[i], c.weekdays[i+1]] = [c.weekdays[i+1], c.weekdays[i]]; ceRenderTab('weeks'); });
      actions.appendChild(downBtn);
    }
    const delBtn = document.createElement('button');
    delBtn.type = 'button'; delBtn.className = 'ce-btn-icon ce-btn-danger'; delBtn.textContent = '✕'; delBtn.title = 'Remove';
    delBtn.addEventListener('click', () => { c.weekdays.splice(i, 1); if (c.epoch_weekday >= c.weekdays.length) c.epoch_weekday = 0; ceRenderTab('weeks'); });
    actions.appendChild(delBtn);

    row.append(idx, nameInput, spacer, actions);
    list.appendChild(row);
  });

  container.appendChild(list);

  // Add day button
  const addBtn = document.createElement('button');
  addBtn.type = 'button'; addBtn.className = 'btn btn-accent'; addBtn.textContent = '+ Add Day'; addBtn.style.marginTop = '12px';
  addBtn.addEventListener('click', () => { c.weekdays.push(`Day ${c.weekdays.length + 1}`); ceRenderTab('weeks'); });
  container.appendChild(addBtn);

  // Epoch weekday
  if (c.weekdays.length > 0) {
    const epGroup = document.createElement('div');
    epGroup.className = 'ce-field-group';
    epGroup.style.marginTop = '18px';
    const epLabel = document.createElement('label');
    epLabel.textContent = 'Epoch weekday';
    const epDesc = document.createElement('div');
    epDesc.className = 'ce-summary';
    epDesc.style.marginTop = '0';
    epDesc.textContent = 'What day of the week is Year 1, Day 1?';
    const epSelect = document.createElement('select');
    epSelect.style.cssText = 'background:var(--bg-card);border:1px solid var(--border);border-radius:4px;color:var(--text-primary);padding:5px 8px;font-size:0.78rem;margin-top:4px;';
    c.weekdays.forEach((name, i) => {
      const opt = document.createElement('option');
      opt.value = i; opt.textContent = name;
      epSelect.appendChild(opt);
    });
    epSelect.value = c.epoch_weekday || 0;
    epSelect.addEventListener('change', () => { c.epoch_weekday = parseInt(epSelect.value, 10); });
    epGroup.append(epLabel, epDesc, epSelect);
    container.appendChild(epGroup);

    // Reset toggle
    const toggleGroup = document.createElement('div');
    toggleGroup.className = 'ce-field-group';
    toggleGroup.style.marginTop = '14px';
    const toggleLabel = document.createElement('label');
    toggleLabel.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;';
    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.checked = !!c.weekdays_reset_each_month;
    checkbox.addEventListener('change', () => { c.weekdays_reset_each_month = checkbox.checked; });
    const toggleText = document.createElement('span');
    toggleText.textContent = 'Weekdays reset each month';
    toggleLabel.append(checkbox, toggleText);
    const toggleDesc = document.createElement('div');
    toggleDesc.className = 'ce-summary';
    toggleDesc.style.marginTop = '2px';
    toggleDesc.textContent = 'Weekday numbering resets at the start of each month';
    toggleGroup.append(toggleLabel, toggleDesc);
    container.appendChild(toggleGroup);
  }
}

// ── Days tab ─────────────────────────────────────────────────────────────────

function ceRenderDays(container) {
  const c = _ceWorkingConfig;
  const grid = document.createElement('div');
  grid.className = 'ce-days-grid';

  // Hours per day
  const hGroup = document.createElement('div');
  hGroup.className = 'ce-field-group';
  const hLabel = document.createElement('label');
  hLabel.textContent = 'Hours per day';
  const hInput = document.createElement('input');
  hInput.type = 'number';
  hInput.min = '1';
  hInput.value = c.hours_per_day;
  hInput.addEventListener('change', () => {
    c.hours_per_day = parseInt(hInput.value, 10) || 24;
    updateDaySummary();
  });
  hGroup.append(hLabel, hInput);

  // Minutes per hour
  const mGroup = document.createElement('div');
  mGroup.className = 'ce-field-group';
  const mLabel = document.createElement('label');
  mLabel.textContent = 'Minutes per hour';
  const mInput = document.createElement('input');
  mInput.type = 'number';
  mInput.min = '1';
  mInput.value = c.minutes_per_hour;
  mInput.addEventListener('change', () => {
    c.minutes_per_hour = parseInt(mInput.value, 10) || 60;
    updateDaySummary();
  });
  mGroup.append(mLabel, mInput);

  grid.append(hGroup, mGroup);
  container.appendChild(grid);

  // Summary
  const summary = document.createElement('div');
  summary.className = 'ce-summary';
  summary.id = 'ce-day-summary';
  container.appendChild(summary);

  function updateDaySummary() {
    const mpd = c.hours_per_day * c.minutes_per_hour;
    summary.textContent = `${mpd} minutes per day`;
  }
  updateDaySummary();
}

// ── Eras tab ─────────────────────────────────────────────────────────────────

function ceRenderEras(container) {
  const c = _ceWorkingConfig;
  if (!c.eras) c.eras = [];
  if (c.zero_year_exists == null) c.zero_year_exists = false;

  const backward = c.eras.filter(e => e.direction === 'backward');
  const forward = c.eras.filter(e => e.direction === 'forward');

  // Backward era
  const bwSection = document.createElement('div');
  bwSection.className = 'ce-era-section';
  const bwTitle = document.createElement('div');
  bwTitle.className = 'ce-era-section-title';
  bwTitle.textContent = 'Backward Era (optional)';
  bwSection.appendChild(bwTitle);

  if (backward.length === 0) {
    const addBw = document.createElement('button');
    addBw.type = 'button'; addBw.className = 'btn'; addBw.textContent = '+ Add Backward Era';
    addBw.addEventListener('click', () => {
      c.eras.push({ name: 'Before Era', abbreviation: 'BE', direction: 'backward', start_year: 0 });
      ceRenderTab('eras');
    });
    bwSection.appendChild(addBw);
  } else {
    const bw = backward[0];
    const bwIdx = c.eras.indexOf(bw);
    bwSection.appendChild(ceEraRow(bw, bwIdx, c));
  }
  container.appendChild(bwSection);

  // Forward eras
  const fwSection = document.createElement('div');
  fwSection.className = 'ce-era-section';
  fwSection.style.marginTop = '18px';
  const fwTitle = document.createElement('div');
  fwTitle.className = 'ce-era-section-title';
  fwTitle.textContent = 'Forward Eras';
  fwSection.appendChild(fwTitle);

  forward.sort((a, b) => a.start_year - b.start_year).forEach(era => {
    const idx = c.eras.indexOf(era);
    fwSection.appendChild(ceEraRow(era, idx, c));
  });

  const addFw = document.createElement('button');
  addFw.type = 'button'; addFw.className = 'btn'; addFw.textContent = '+ Add Era'; addFw.style.marginTop = '8px';
  addFw.addEventListener('click', () => {
    c.eras.push({ name: 'New Era', abbreviation: 'NE', direction: 'forward', start_year: 1 });
    ceRenderTab('eras');
  });
  fwSection.appendChild(addFw);
  container.appendChild(fwSection);

  // Zero year toggle
  const zGroup = document.createElement('div');
  zGroup.className = 'ce-field-group';
  zGroup.style.marginTop = '18px';
  const zLabel = document.createElement('label');
  zLabel.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;';
  const zCheck = document.createElement('input');
  zCheck.type = 'checkbox';
  zCheck.checked = !!c.zero_year_exists;
  zCheck.addEventListener('change', () => { c.zero_year_exists = zCheck.checked; });
  const zText = document.createElement('span');
  zText.textContent = 'Year zero exists';
  zLabel.append(zCheck, zText);
  const zDesc = document.createElement('div');
  zDesc.className = 'ce-summary';
  zDesc.style.marginTop = '2px';
  zDesc.textContent = 'When enabled, the first year of each era will be 0 instead of 1';
  zGroup.append(zLabel, zDesc);
  container.appendChild(zGroup);
}

function ceEraRow(era, idx, c) {
  const row = document.createElement('div');
  row.className = 'ce-era-row';

  const abbrInput = document.createElement('input');
  abbrInput.type = 'text'; abbrInput.className = 'ce-era-abbr'; abbrInput.value = era.abbreviation || '';
  abbrInput.placeholder = 'Abbr'; abbrInput.title = 'Abbreviation';
  abbrInput.addEventListener('change', () => { era.abbreviation = abbrInput.value; });

  const nameInput = document.createElement('input');
  nameInput.type = 'text'; nameInput.className = 'ce-era-name'; nameInput.value = era.name || '';
  nameInput.placeholder = 'Era name';
  nameInput.addEventListener('change', () => { era.name = nameInput.value; });

  const startLabel = document.createElement('span');
  startLabel.className = 'ce-era-start-label';
  startLabel.textContent = 'from year';

  const startInput = document.createElement('input');
  startInput.type = 'number'; startInput.className = 'ce-era-start'; startInput.value = era.start_year ?? 0;
  startInput.addEventListener('change', () => { era.start_year = parseInt(startInput.value, 10) || 0; });

  const delBtn = document.createElement('button');
  delBtn.type = 'button'; delBtn.className = 'ce-btn-icon ce-btn-danger'; delBtn.textContent = '✕';
  delBtn.addEventListener('click', () => { c.eras.splice(idx, 1); ceRenderTab('eras'); });

  row.append(abbrInput, nameInput, startLabel, startInput, delBtn);
  return row;
}

// ── Moons tab ────────────────────────────────────────────────────────────────

function ceRenderMoons(container) {
  const c = _ceWorkingConfig;
  if (!c.moons) c.moons = [];

  // Header
  const hdr = document.createElement('div');
  hdr.className = 'ce-moon-row ce-month-hdr';
  hdr.innerHTML = '<span class="ce-moon-color"></span><span class="ce-moon-name">Moon</span><span class="ce-moon-cycle">Cycle (min)</span><span class="ce-moon-offset">Phase offset</span><span class="ce-month-actions"></span>';
  container.appendChild(hdr);

  // Moon rows
  c.moons.forEach((moon, i) => {
    const row = document.createElement('div');
    row.className = 'ce-moon-row';

    const colorInput = document.createElement('input');
    colorInput.type = 'color';
    colorInput.className = 'ce-moon-color';
    colorInput.value = moon.color || '#c0c0c0';
    colorInput.addEventListener('change', () => { moon.color = colorInput.value; });

    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.className = 'ce-moon-name';
    nameInput.value = moon.name || '';
    nameInput.placeholder = 'Moon name';
    nameInput.addEventListener('change', () => { moon.name = nameInput.value; });

    const cycleInput = document.createElement('input');
    cycleInput.type = 'number';
    cycleInput.className = 'ce-moon-cycle';
    cycleInput.value = moon.cycle_minutes || 0;
    cycleInput.min = '1';
    cycleInput.addEventListener('change', () => { moon.cycle_minutes = parseInt(cycleInput.value, 10) || 1; updateMoonPreview(); });

    const offsetInput = document.createElement('input');
    offsetInput.type = 'number';
    offsetInput.className = 'ce-moon-offset';
    offsetInput.value = moon.phase_offset || 0;
    offsetInput.addEventListener('change', () => { moon.phase_offset = parseInt(offsetInput.value, 10) || 0; updateMoonPreview(); });

    const actions = document.createElement('span');
    actions.className = 'ce-month-actions';
    const delBtn = document.createElement('button');
    delBtn.type = 'button'; delBtn.className = 'ce-btn-icon ce-btn-danger'; delBtn.textContent = '✕';
    delBtn.addEventListener('click', () => { c.moons.splice(i, 1); ceRenderTab('moons'); });
    actions.appendChild(delBtn);

    row.append(colorInput, nameInput, cycleInput, offsetInput, actions);
    container.appendChild(row);
  });

  // Add moon button
  const addBtn = document.createElement('button');
  addBtn.type = 'button'; addBtn.className = 'btn btn-accent'; addBtn.textContent = '+ Add Moon'; addBtn.style.marginTop = '12px';
  addBtn.addEventListener('click', () => {
    c.moons.push({ name: `Moon ${c.moons.length + 1}`, cycle_minutes: 42523, phase_offset: 0, color: '#c0c0c0' });
    ceRenderTab('moons');
  });
  container.appendChild(addBtn);

  // Phase preview for a sample date
  if (c.moons.length > 0) {
    const previewSection = document.createElement('div');
    previewSection.style.marginTop = '18px';
    const previewTitle = document.createElement('div');
    previewTitle.className = 'ce-era-section-title';
    previewTitle.textContent = 'Phase Preview (Year 1, Day 1)';
    previewSection.appendChild(previewTitle);

    const previewRow = document.createElement('div');
    previewRow.id = 'ce-moon-preview';
    previewRow.className = 'ce-moon-preview';
    previewSection.appendChild(previewRow);
    container.appendChild(previewSection);
    updateMoonPreview();
  }

  function updateMoonPreview() {
    const prev = document.getElementById('ce-moon-preview');
    if (!prev) return;
    // Temporarily apply working config for phase calculation
    const saved = CALENDAR;
    CALENDAR = c;
    prev.innerHTML = '';
    for (const moon of c.moons) {
      const phase = moonPhase(moon, 1, 1, 1, 0, 0);
      const emoji = moonPhaseEmoji(phase);
      const chip = document.createElement('span');
      chip.className = 'ce-moon-chip';
      chip.style.borderColor = moon.color || '#c0c0c0';
      chip.innerHTML = `<span class="ce-moon-emoji">${emoji}</span> ${moon.name} <span class="ce-moon-phase-pct">${(phase * 100).toFixed(0)}%</span>`;
      prev.appendChild(chip);
    }
    CALENDAR = saved;
  }
}

// ── Display tab ──────────────────────────────────────────────────────────────

const FORMAT_TOKENS = [
  ['MMMM', 'Full month name'],
  ['MMM',  'Month abbreviation (3 chars)'],
  ['MM',   'Month number (2 digits)'],
  ['M',    'Month number'],
  ['DD',   'Day (2 digits)'],
  ['D',    'Day'],
  ['YYYY', 'Year'],
  ['YY',   'Year (2 digits)'],
  ['SYYYY','Year with sign (negative for backward eras)'],
  ['YBIG', 'Year (big number format: 1.2M, 500k)'],
  ['EE',   'Era full name'],
  ['E',    'Era abbreviation'],
  ['HH',   'Hour (2 digits, 24h or 12h per setting)'],
  ['H',    'Hour'],
  ['hh',   'Hour (2 digits, always 12h)'],
  ['h',    'Hour (always 12h)'],
  ['mm',   'Minute (2 digits)'],
  ['A',    'AM/PM'],
  ['a',    'am/pm'],
  ['^',    'Ordinal suffix (after a number: 1st, 2nd)'],
  ['[text]','Literal text'],
];

function ceRenderDisplay(container) {
  const c = _ceWorkingConfig;
  if (!c.date_format) c.date_format = 'D MMMM YYYY';
  if (!c.time_format) c.time_format = '24h';

  const wrapper = document.createElement('div');
  wrapper.className = 'ce-display-wrapper';

  // Format input
  const fmtGroup = document.createElement('div');
  fmtGroup.className = 'ce-field-group';
  const fmtLabel = document.createElement('label');
  fmtLabel.textContent = 'Date format';
  const fmtInput = document.createElement('input');
  fmtInput.type = 'text';
  fmtInput.value = c.date_format;
  fmtInput.style.fontFamily = 'monospace';
  fmtInput.addEventListener('input', () => {
    c.date_format = fmtInput.value;
    updatePreview();
  });
  fmtGroup.append(fmtLabel, fmtInput);
  wrapper.appendChild(fmtGroup);

  // Preview
  const previewBox = document.createElement('div');
  previewBox.className = 'ce-display-preview';
  previewBox.id = 'ce-display-preview';
  wrapper.appendChild(previewBox);

  // Time format radio
  const timeGroup = document.createElement('div');
  timeGroup.className = 'ce-field-group';
  timeGroup.style.marginTop = '14px';
  const timeLabel = document.createElement('label');
  timeLabel.textContent = 'Time format';
  const radioRow = document.createElement('div');
  radioRow.style.cssText = 'display:flex;gap:16px;margin-top:4px;';
  for (const [val, label] of [['24h', '24 hour'], ['12h', '12 hour (AM/PM)']]) {
    const lbl = document.createElement('label');
    lbl.style.cssText = 'display:flex;align-items:center;gap:5px;cursor:pointer;font-size:0.78rem;color:var(--text-primary);';
    const radio = document.createElement('input');
    radio.type = 'radio'; radio.name = 'ce-time-format'; radio.value = val;
    radio.checked = c.time_format === val;
    radio.addEventListener('change', () => { c.time_format = val; updatePreview(); });
    lbl.append(radio, document.createTextNode(label));
    radioRow.appendChild(lbl);
  }
  timeGroup.append(timeLabel, radioRow);
  wrapper.appendChild(timeGroup);

  // Token reference
  const refSection = document.createElement('div');
  refSection.style.marginTop = '18px';
  const refTitle = document.createElement('div');
  refTitle.className = 'ce-era-section-title';
  refTitle.textContent = 'Format Tokens';
  refSection.appendChild(refTitle);

  const refGrid = document.createElement('div');
  refGrid.className = 'ce-token-grid';
  for (const [token, desc] of FORMAT_TOKENS) {
    const row = document.createElement('div');
    row.className = 'ce-token-row';
    const code = document.createElement('code');
    code.className = 'ce-token-code';
    code.textContent = token;
    code.addEventListener('click', () => {
      fmtInput.value += token;
      c.date_format = fmtInput.value;
      updatePreview();
      fmtInput.focus();
    });
    const descEl = document.createElement('span');
    descEl.className = 'ce-token-desc';
    descEl.textContent = desc;
    row.append(code, descEl);
    refGrid.appendChild(row);
  }
  refSection.appendChild(refGrid);
  wrapper.appendChild(refSection);

  container.appendChild(wrapper);
  updatePreview();

  function updatePreview() {
    const prev = document.getElementById('ce-display-preview');
    if (!prev) return;
    // Sample date: use first month, day 15, year 1247, 14:30
    const saved = CALENDAR;
    CALENDAR = c;
    const sampleMonth = c.months[0]?.name ? 1 : 0;
    const sample = { year: 1247, month: sampleMonth || 1, day: 15, hour: 14, minute: 30 };
    const fmt = c.date_format || 'D MMMM YYYY';
    const fullFmt = fmt + ' HH:mm';
    const dateOnly = formatDateString(sample, fmt);
    const withTime = formatDateString(sample, fullFmt);
    prev.innerHTML = `<div class="ce-preview-label">Date only</div><div class="ce-preview-value">${dateOnly}</div>` +
      `<div class="ce-preview-label">With time</div><div class="ce-preview-value">${withTime}</div>`;
    CALENDAR = saved;
  }
}

// ── Save ─────────────────────────────────────────────────────────────────────

async function ceHandleSave() {
  const calId = document.getElementById('ce-preset-select').value;
  const calName = document.getElementById('ce-preset-name').value.trim();
  if (!calId) return;

  // Update the calendar preset
  await apiFetch(`${API}/calendars/${calId}`, {
    method: 'PUT', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: calName || undefined, config: _ceWorkingConfig })
  });

  // Activate it (also batch-recomputes REAL values)
  const result = await apiFetch(`${API}/calendars/${calId}/activate`, { method: 'PUT' }).then(r => r.json());

  // Apply locally
  CALENDAR = _ceWorkingConfig;
  ACTIVE_CALENDAR_ID = calId;

  // Reload all cached nodes (REAL values changed)
  TL.childCache = {};
  TL.nodeById = {};
  TL.levelPPY['root'] = null;
  TL.scrollLeft['root'] = null;

  closeCalendarEditor();
  await tlFetch(null);
  renderWorld();
}

// ── Preset management ────────────────────────────────────────────────────────

async function ceNewPreset() {
  const name = prompt('New calendar name:');
  if (!name) return;
  const created = await apiFetch(`${API}/calendars`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, config: _ceWorkingConfig })
  }).then(r => r.json());
  _cePresets.push(created);
  const sel = document.getElementById('ce-preset-select');
  const opt = document.createElement('option');
  opt.value = created.id;
  opt.textContent = created.name;
  sel.appendChild(opt);
  sel.value = created.id;
  document.getElementById('ce-preset-name').value = created.name;
}

async function ceDeletePreset() {
  const sel = document.getElementById('ce-preset-select');
  const id = sel.value;
  const cal = _cePresets.find(c => c.id === id);
  if (!cal) return;
  if (cal.is_active) { alert('Cannot delete the active calendar.'); return; }
  if (!confirm(`Delete "${cal.name}"?`)) return;
  await apiFetch(`${API}/calendars/${id}`, { method: 'DELETE' });
  _cePresets = _cePresets.filter(c => c.id !== id);
  sel.querySelector(`option[value="${id}"]`)?.remove();
  // Switch to active preset
  const active = _cePresets.find(c => c.is_active);
  if (active) {
    sel.value = active.id;
    _ceWorkingConfig = structuredClone(active.config);
    document.getElementById('ce-preset-name').value = active.name;
    ceRenderTab(_ceActiveTab);
  }
}

function ceSwitchPreset(id) {
  const cal = _cePresets.find(c => c.id === id);
  if (!cal) return;
  _ceWorkingConfig = structuredClone(cal.config);
  document.getElementById('ce-preset-name').value = cal.name;
  ceRenderTab(_ceActiveTab);
}

// ── Init (called once from setupListeners in app.js) ─────────────────────────

function setupCalendarEditor() {
  // Tabs
  document.querySelectorAll('.ce-tab').forEach(t =>
    t.addEventListener('click', () => ceRenderTab(t.dataset.tab)));

  // Preset controls
  document.getElementById('ce-preset-select')?.addEventListener('change', e => ceSwitchPreset(e.target.value));
  document.getElementById('ce-preset-new')?.addEventListener('click', ceNewPreset);
  document.getElementById('ce-preset-delete')?.addEventListener('click', ceDeletePreset);

  // Save / Cancel
  document.getElementById('ce-save')?.addEventListener('click', ceHandleSave);
  document.getElementById('ce-cancel')?.addEventListener('click', closeCalendarEditor);

  // Close on overlay click
  document.getElementById('ce-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'ce-overlay') closeCalendarEditor();
  });
}
