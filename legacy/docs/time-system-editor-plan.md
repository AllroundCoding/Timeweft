# Time System Editor — Implementation Plan

A comprehensive calendar/time system editor for Vaeris Timeline, inspired by many similar time Systems.

## Current State

- Dates stored as fractional REAL years in `timeline_nodes.start_date` / `end_date`
- Global `CALENDAR` object: `months: [{name, days}]`, `hours_per_day`, `minutes_per_hour`
- `dateToDecimal()` / `decimalToDate()` convert between components and fractional years
- Calendar config stored as JSON in `settings` table under key `calendar`
- Simple settings modal with textarea for months + hours/minutes inputs

## Target Feature

A full tabbed Time System Editor with: multiple calendar presets, overview stats, month management with leap days, weekdays, day/hour/minute config, eras (BCE/CE style), moons with phase calculation, and configurable date format strings.

---

## Architectural Decisions

### Store Calendar as JSON Per Preset

All calendar config (months, weekdays, eras, moons, leap rules, format strings) lives as a single JSON blob per calendar preset in a `calendars` table. Rationale:
- Calendar data is always loaded and used as a unit
- Complex nested structures (leap rules, eras) map poorly to relational tables
- Already using this pattern via `settings.calendar`

### Date Storage: Components as Source of Truth

**Critical change**: move from fractional-year storage to component storage.

- Add columns: `start_year`, `start_month`, `start_day`, `start_hour`, `start_minute` (same for `end_*`)
- Keep `start_date` / `end_date` REAL as a **computed positioning cache**
- On calendar change → recompute all REAL values from components via server-side batch update
- On node create/update → server computes REAL from components using active calendar

This allows changing calendars without corrupting date meaning.

### Calendar Config JSON Schema (Complete)

```json
{
  "months": [
    { "name": "Frostmoon", "days": 30 },
    { "name": "Snowfall", "days": 31 }
  ],
  "hours_per_day": 24,
  "minutes_per_hour": 60,
  "weekdays": ["Moonday", "Towerday", "Windsday", "Thunderday", "Fireday", "Starday", "Sunday"],
  "epoch_weekday": 0,
  "weekdays_reset_each_month": false,
  "leap_days": [
    { "after_month": 1, "cycles": [4, -100, 400] }
  ],
  "eras": [
    { "name": "Before Dawn", "abbreviation": "BD", "direction": "backward", "start_year": 0 },
    { "name": "Age of Light", "abbreviation": "AL", "direction": "forward", "start_year": 1 }
  ],
  "zero_year_exists": false,
  "world_start": -10000,
  "world_end": 2000,
  "moons": [
    { "name": "Luna", "cycle_minutes": 42523, "phase_offset": 0, "color": "#c0c0c0" }
  ],
  "date_format": "D MMMM YYYY EE",
  "time_format": "24h"
}
```

---

## Phased Implementation

### Phase 0: Preparatory Refactoring

**Goal**: Extract calendar logic into standalone modules. App stays working.

- Create `public/calendar.js` — all pure calendar functions (`CALENDAR`, `dateToDecimal`, `decimalToDate`, `formatDate`, `formatYear`, `formatRulerTick`, `calDaysInYear`, etc.)
- Create `server/calendar.js` — CommonJS port of `dateToDecimal`/`decimalToDate` for server-side REAL computation
- Remove calendar functions from `app.js`, add `<script src="calendar.js">` before `app.js`
- Fix any stale references to removed constants (e.g., `MONTH_NAMES`)

### Phase 1: Component Date Storage + Schema Migration

**Goal**: Dates stored as components; REAL values are computed cache. App stays working.

**Schema changes** (in `db/connection.js` `_migrateSchema()`):
```sql
ALTER TABLE timeline_nodes ADD COLUMN start_year   INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN start_month  INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN start_day    INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN start_hour   INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN start_minute INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN end_year     INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN end_month    INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN end_day      INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN end_hour     INTEGER;
ALTER TABLE timeline_nodes ADD COLUMN end_minute   INTEGER;

CREATE TABLE IF NOT EXISTS calendars (
  id          TEXT PRIMARY KEY,
  timeline_id TEXT NOT NULL DEFAULT 'default',
  name        TEXT NOT NULL,
  is_active   INTEGER NOT NULL DEFAULT 0,
  config      TEXT NOT NULL DEFAULT '{}',
  created_at  TEXT DEFAULT(datetime('now')),
  updated_at  TEXT DEFAULT(datetime('now'))
);
```

**Data migration**: For rows where `start_year IS NULL`, decompose existing REAL values into components using current calendar config. Seed `calendars` table with current config.

**API changes**:
- `POST/PUT /api/nodes` accept component fields, server computes REAL
- `GET /api/nodes` returns both components and REAL values
- Frontend sends raw components instead of pre-computed decimals

### Phase 2: Multiple Calendar Presets

**Goal**: Create, name, save, load, and switch between calendar presets.

**New API endpoints**:
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/calendars` | List presets |
| GET | `/api/calendars/:id` | Get one |
| POST | `/api/calendars` | Create |
| PUT | `/api/calendars/:id` | Update config |
| DELETE | `/api/calendars/:id` | Delete |
| PUT | `/api/calendars/:id/activate` | Switch active; batch recompute all REAL values |

**Activation** is the critical operation: set active flag, load config, recompute every node's `start_date`/`end_date` from stored components in a transaction.

**Frontend**: Calendar preset dropdown with New/Duplicate/Delete/Rename.

Remove `calendar` key from `settings` table (now in `calendars` table).

### Phase 3: Time System Editor UI — Core Tabs

**Goal**: Full tabbed editor modal replacing the simple settings textarea.

**New files**: `public/calendar-editor.js`

**HTML**: New `#calendar-editor-overlay` with tabbed structure.

#### Overview Tab (read-only stats)
- Hours/day, minutes/hour, days/year, months/year, days/week
- Average days/month, hours/week, hours/month
- World span, earliest/latest node dates

#### Months Tab
- Draggable list: each row has drag handle, name input, day count input, delete button
- "Add Month" button
- **Leap days**: "Add intercalary day" → rules like "1 day after [month] every [N] years, except every [M], except every [P]" → `{ after_month, cycles: [4, -100, 400] }`
- Calendar preview grid showing selected month

#### Days Tab
- Hours per day input
- Minutes per hour input
- Computed "Total minutes per day" display

### Phase 4: Weeks Tab

**Calendar config additions**: `weekdays: [names]`, `epoch_weekday: 0`, `weekdays_reset_each_month: false`

**UI**:
- Weekday name list with drag handles, add/remove
- "Epoch weekday" dropdown (what day is Year 1 Day 1?)
- "Weekdays reset each month" toggle

**New function**: `weekdayOf(year, month, day)` → compute from total days since epoch + offset.

Calendar preview grid gains weekday headers with correct alignment.

### Phase 5: Years / Eras Tab

**Calendar config additions**: `eras: [{name, abbreviation, direction, start_year}]`, `zero_year_exists: false`

**UI**:
- Backward era (optional): name + abbreviation
- Forward eras list: each with name, abbreviation, start year. Add/remove.
- Visual era timeline bar
- "Year zero exists" toggle
- World start/end inputs (moved from old settings)

**New function**: `yearToEra(absoluteYear)` → `{displayYear, era}`

**Impact**: `formatDate()` and `formatYear()` gain era-aware display. All existing call sites update automatically.

Move `world_start`/`world_end` from `settings` table into calendar config.

### Phase 6: Moons Tab

**Calendar config additions**: `moons: [{name, cycle_minutes, phase_offset, color}]`

**UI**:
- Moon list: name, cycle length (minutes), phase offset, color picker, delete
- "Add Moon" button
- Phase preview for a user-selected date

**New functions**:
```js
totalMinutesFromEpoch(year, month, day, hour, minute) → total minutes
moonPhase(moon, totalMinutes) → 0..1 (0 = new, 0.5 = full)
```

Optional: moon phase indicator in tooltips/detail panel.

### Phase 7: Display / Format Tab

**Calendar config additions**: `date_format: "D MMMM YYYY EE"`, `time_format: "24h"`

**Format tokens**:
| Token | Meaning |
|-------|---------|
| `MMMM` | Full month name |
| `MMM` | Abbreviated (3 chars) |
| `MM` | Zero-padded month number |
| `M` | Month number |
| `DD` | Zero-padded day |
| `D` | Day |
| `YYYY` | Full year |
| `YY` | Last 2 digits |
| `SYYYY` | Signed year |
| `YBIG` | Big number format (1.2M, 500k) |
| `EE` | Era full name |
| `E` | Era abbreviation |
| `HH` | 24h hour (padded) |
| `hh` | 12h hour (padded) |
| `mm` | Minutes (padded) |
| `A` / `a` | AM/PM |
| `^` | Ordinal suffix (st, nd, rd, th) |
| `[text]` | Literal text |

**UI**:
- Format string input
- Token reference list
- Preset format dropdown
- Live preview with sample date
- 12h / 24h radio toggle

**Implementation**: Replace hardcoded `formatDate()` with `formatDateString(components, CALENDAR.date_format)`.

### Phase 8: Polish & Integration

- Simplify old settings modal (remove calendar fields, add "Open Time System Editor" button)
- Node modal: month dropdown with calendar names, day validation per month
- Leap year awareness in day count validation
- Edge cases: calendar change with mismatched month indices (clamp + warning)
- Backward compat: fallback to `decimalToDate()` if component columns are NULL
- Performance: benchmark batch recompute for large datasets

---

## Dependency Graph

```
Phase 0  (extract calendar module)
   │
Phase 1  (component columns + server-side REAL computation)
   │
Phase 2  (multiple calendar presets)
   │
Phase 3  (editor UI: Overview, Months, Days tabs)
   │
   ├── Phase 4  (Weeks tab)
   ├── Phase 5  (Eras tab)
   ├── Phase 6  (Moons tab)
   │
   └── Phase 7  (Display/Format tab) ← depends on Phase 5 for era tokens
   │
Phase 8  (polish, edge cases, integration)
```

Phases 4, 5, 6 are independent and can be built in any order after Phase 3.

---

## New Files

| File | Purpose |
|------|---------|
| `public/calendar.js` | All calendar math (shared globals) |
| `public/calendar-editor.js` | Time System Editor UI |
| `server/calendar.js` | Server-side calendar math (CommonJS) |

---

## Risks & Mitigations

1. **Data corruption during calendar switch** → Wrap batch recompute in SQLite transaction; rollback on error
2. **Float precision in migration** → `decimalToDate` already snaps to nearest minute; verify sample dates after migration
3. **Calendar change with invalid months** → Clamp out-of-range month indices to last valid month + show warning
4. **Growing app.js** → Calendar logic extracted to separate files; editor is standalone
5. **No build system** → Use global functions with `cal` prefix convention to avoid collisions
