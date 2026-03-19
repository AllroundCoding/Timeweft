'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// TL — Recursive timeline state
// ══════════════════════════════════════════════════════════════════════════════

const TL = {
  childCache:     {},      // 'root' | nodeId → [node, ...]
  nodeById:       {},      // nodeId → node
  expanded:       new Set(),
  levelPPY:       {},      // 'root' | nodeId → pixels-per-year (null = auto-fit)
  loading:        new Set(),
  scrollLeft:     {},      // 'root' | nodeId → saved horizontal scroll position
  ganttScrollTop: 0,       // saved vertical scroll of the gantt timeline
  yearMin:        null,    // last rendered yearMin (for zoom anchor calculation)
  viewAnchor:     null,    // { year, vpOffset } set by zoomRoot for scroll restoration
  cursorYear:     null,    // decimal year under the mouse cursor on the timeline
};

// Layout constants
const SPAN_H   = 34;   // px height of one packed span row
const SPAN_GAP = 3;    // px gap between stacked span rows
const POINT_H  = 32;   // px height of the point-events zone
const CANVAS_PAD = 28; // px horizontal padding inside canvas

// Gantt layout constants
const GANTT_SIDEBAR_W = 200;  // px width of the left labels column

// Reuse SYMBOLS (defined in timeline-helpers.js) for node type icons
function nodeTypeIcon(nodeType) {
  return SYMBOLS[nodeType] || SYMBOLS.event;
}

function nodeTooltipText(n) {
  let tip = (n.node_type || 'event');
  if (n.start_year != null || n.start_date != null) {
    tip += ' · ' + formatNodeDate(n, 'start');
    if (n.end_date != null) tip += ' → ' + formatNodeDate(n, 'end');
  }
  if (n.importance && n.importance !== 'moderate') tip += ' · ' + n.importance;
  return tip;
}

// World bounds and default view — loaded from /api/settings at boot.
// world_start/end: canvas never extends beyond these (how old the world is).
// default_view_start/end: initial zoom range; null = fit to data.
let WORLD_YEAR_MIN = -1000000000;
let WORLD_YEAR_MAX =  2000;
let DEFAULT_VIEW_START = null;
let DEFAULT_VIEW_END   = null;

// Scroll-to-zoom mode: 'scroll' = bare scroll zooms, 'ctrl' = requires ctrl/meta
let SCROLL_ZOOM_MODE = localStorage.getItem('tl-scroll-zoom') || 'scroll';

// ══════════════════════════════════════════════════════════════════════════════
// SplitPane — split-screen layout state
// ══════════════════════════════════════════════════════════════════════════════

const SplitPane = {
  secondaryModule: null,                                             // null = full timeline
  position: localStorage.getItem('tl-split-position') || 'right',   // where secondary pane sits
  ratio: parseFloat(localStorage.getItem('tl-split-ratio')) || 0.5,  // fraction for secondary
  isDragging: false,
  minRatio: 0.15,
  maxRatio: 0.85,
};

const API = '/api';

// ── Auth state ──────────────────────────────────────────────────────────────

let AUTH_USER = null;  // { id, username, display_name, role }

// ── Shared timeline state ──────────────────────────────────────────────────
// When viewing another user's timeline:
// { owner_id, owner_username, owner_display_name, preset,
//   perms: { timeline, docs, entities, settings } }
let VIEWING_TIMELINE = null;

function isOwnTimeline() {
  return !VIEWING_TIMELINE;
}

function canEdit(area) {
  if (!VIEWING_TIMELINE) return true;
  const level = VIEWING_TIMELINE.perms[area];
  return level === 'edit' || level === 'delete' || level === 'review';
}

function canDelete(area) {
  if (!VIEWING_TIMELINE) return true;
  const level = VIEWING_TIMELINE.perms[area];
  return level === 'delete' || level === 'review';
}

function getAuthToken() {
  return localStorage.getItem('tl_token');
}

function setAuthToken(token) {
  if (token) localStorage.setItem('tl_token', token);
  else localStorage.removeItem('tl_token');
}

// Authenticated fetch wrapper — injects Authorization header + X-Timeline-Owner, handles 401
async function apiFetch(url, options = {}) {
  const token = getAuthToken();
  if (token) {
    if (!options.headers) options.headers = {};
    if (typeof options.headers === 'object' && !(options.headers instanceof Headers)) {
      options.headers['Authorization'] = `Bearer ${token}`;
      if (VIEWING_TIMELINE) {
        options.headers['X-Timeline-Owner'] = VIEWING_TIMELINE.owner_id;
      }
    }
  }
  const res = await fetch(url, options);
  if (res.status === 401) {
    setAuthToken(null);
    AUTH_USER = null;
    showAuthOverlay();
    throw new Error('Session expired');
  }
  return res;
}
