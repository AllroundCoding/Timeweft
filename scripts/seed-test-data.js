'use strict';
const { getAccountsDb, getUserDb } = require('../src/db/connection');
const { dateToDecimal, DEFAULT_CALENDAR } = require('../src/server/calendar');

// ── Target ───────────────────────────────────────────────────────────────────
// Usage: node scripts/seed-test-data.js [target] [username]
//   target   = desired number of point nodes (default 40000)
//   username = user whose timeline to seed (default: first user in accounts.db)
//   Examples: 1000 (quick), 5000 (visuals), 40000 (default), 1000000 (stress)

const TARGET = parseInt(process.argv[2]) || 40000;
const usernameArg = process.argv[3] || null;

// Resolve user
const accountsDb = getAccountsDb();
const user = usernameArg
  ? accountsDb.prepare('SELECT id, username FROM users WHERE username = ? COLLATE NOCASE').get(usernameArg)
  : accountsDb.prepare('SELECT id, username FROM users ORDER BY created_at LIMIT 1').get();

if (!user) {
  console.error(usernameArg
    ? `Error: user "${usernameArg}" not found. Create an account first.`
    : 'Error: no users found. Create an account first.');
  process.exit(1);
}

console.log(`Seeding into ${user.username}'s timeline (${user.id})`);
const db = getUserDb(user.id);
console.log(`Target: ${TARGET} points`);

// ── Helpers ──────────────────────────────────────────────────────────────────

let _n = 0;
const uid = () => `nd_${++_n}`;
const pick  = arr => arr[Math.floor(Math.random() * arr.length)];
const randBetween = (a, b) => a + Math.floor(Math.random() * (b - a + 1));
const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));

const cal = DEFAULT_CALENDAR;
const MONTH_DAYS = cal.months.map(m => m.days);

function toReal(year, month, day) {
  return dateToDecimal(cal, year, month ?? 0, day ?? 0, 0, 0);
}

function randDate() {
  const month = randBetween(1, 12);
  const day   = randBetween(1, MONTH_DAYS[month - 1]);
  return { month, day };
}

const insertNode = db.prepare(`INSERT OR IGNORE INTO timeline_nodes
  (id, parent_id, type, title, start_date, end_date,
   start_year, start_month, start_day,
   end_year, end_month, end_day,
   description, color, opacity, importance, node_type, sort_order, metadata)
  VALUES (?,?,?,?,?,?, ?,?,?, ?,?,?, ?,?,?,?,?,?,?)`);

function addSpan({ parent_id, title, startY, startM, startD, endY, endM, endD,
                   description, color, opacity, importance, node_type, sort_order }) {
  const nid = uid();
  insertNode.run(
    nid, parent_id ?? null, 'span', title,
    toReal(startY, startM, startD), toReal(endY, endM, endD),
    startY, startM ?? null, startD ?? null,
    endY, endM ?? null, endD ?? null,
    description ?? null, color ?? '#5566bb', opacity ?? 0.75,
    importance ?? 'moderate', node_type ?? 'event', sort_order ?? 0, '{}');
  return nid;
}

function addPoint({ parent_id, title, year, month, day,
                    description, color, opacity, importance, node_type, sort_order }) {
  const nid = uid();
  insertNode.run(
    nid, parent_id ?? null, 'point', title,
    toReal(year, month, day), null,
    year, month ?? null, day ?? null,
    null, null, null,
    description ?? null, color ?? '#5566bb', opacity ?? 0.75,
    importance ?? 'moderate', node_type ?? 'event', sort_order ?? 0, '{}');
  return nid;
}

// ── Vocabulary ───────────────────────────────────────────────────────────────

const PLACES = [
  'Silverhold', 'Greymarch', 'Thornwall', 'Ashvale', 'Stormreach', 'Duskfall',
  'Ironvale', 'Brightwater', 'Shadowmere', 'Goldcrest', 'Frostpeak', 'Emberveil',
  'Windhollow', 'Deepwatch', 'Ravencross', 'Sunhaven', 'Blackmoor', 'Starfall',
  'Oakenshire', 'Crystalford', 'Cinderkeep', 'Mistral', 'Halcyon', 'Dawnspire',
  'Coralheim', 'Verdantia', 'Pyrewood', 'Stonehearth', 'Tidecrest', 'Veilharbour',
  'Wyrmrest', 'Thundarr', 'Glimmervale', 'Nightholme', 'Brassridge', 'Ashenport',
  'Willowmere', 'Falconhurst', 'Serpentine', 'Moonfall', 'Redspire', 'Icemark',
  'Thornfield', 'Greywatch', 'Ember Crossing', 'Skyhaven', 'Dunehollow', 'Lakewall',
];
const PEOPLE = [
  'Aldara', 'Varenthos', 'Ithiel', 'Drevak', 'Seraphel', 'Morkhan', 'Arandor',
  'Calista', 'Theron', 'Nyx', 'Oberon', 'Isolde', 'Kael', 'Lyreth', 'Zephyros',
  'Valkira', 'Dorian', 'Elara', 'Fenwick', 'Gorath', 'Helena', 'Jasper', 'Korra',
  'Lysander', 'Mordecai', 'Nerissa', 'Osric', 'Priya', 'Quinn', 'Ragnor',
  'Selene', 'Tiberius', 'Ursa', 'Vivienne', 'Wulfric', 'Xandria', 'Yael', 'Zorath',
  'Alaric', 'Brynn', 'Corvus', 'Delphine', 'Erasmus', 'Freya', 'Gideon', 'Hespera',
  'Dan-as',	'Ardshy',	'Seyeyg',	'Kiack', 'Ineumran', 'Phaon', 'Ryach',
  'Kimnen', 'Baran', 'Hutiasay', 'Rayelma', 'Rihyt', 'Tryb', 'Irech',
  'Nawac', 'Arkinight', 'Rothen', 'Ardraddan', 'Leritdra', 'Tintasran	Wormust',
  'Hatcerril', 'Imnkal', 'Lord', 'Eore', 'Ingquay', 'Acki', 'Slelt',
  'Shyand', 'Drytonenth', 'Woromkal', 'Eona', 'Nyrot', 'Dynang', 'Unysu',
  'Shyny', 'Cahin', 'Turoack', 'Tinust', 'Miworia', 'Rilad', 'Malesy',
  'Wim', 'Aughst', 'Jyjuyi', 'Whadenmor', 'Uskyther', 'Mar', 'Fisiec',
  'Peardshy', 'En-echi', 'Uskurnkal', 'Anad', 'Turiage', 'Alyee', 'Zhiemgar',
  'Onler', 'Chyath', 'Etagei', 'Murar', 'Snuroth', 'Rynoeld', 'Aleust',
  'Drachver', 'Vor-daro', 'Buhayss', 'Saymnal', 'Relun', 'Kocheris', 'Rodan',
  'Anroth', 'Quaont', 'Omktai', 'Rheydelm', 'Lasul', 'Untold', 'Neyota',
  'Pindpol', 'Uskuing', 'Nenob', 'Peledu', 'Athyril', 'Inas', 'Im-tur',
  'Yerell', 'Hinyshy', 'Nalvesver', 'Suisach', 'Aughisswar', 'Streigh', 'Perrila',
  'Hosvor', 'Ashat', 'Chrokimard', 'Vesnysingtur', 'Tholl', 'Issasaugh', 'Ensama',
  'Stives', 'Gisera', 'Banyrt', 'Locod', 'Buchmor', 'Alebur', 'Brerad',
  'Eldn', 'Chratan', 'Lorloro', 'Aldend', 'Que-rak-aust', 'Logew', 'As-usky',
  'Denm	Rilr', 'Ildcerage', 'Old-rak-aelt', 'Oshya', 'Unduc', 'Essfim',
];
const FACTIONS = [
  'the Silver Flame', 'the Iron Circle', 'the Dawn Compact', 'the Obsidian Court',
  'the Emerald Order', 'the Crimson Pact', 'the Azure League', 'the Shadow Covenant',
  'the Golden Tribunal', 'the Storm Council', 'the Veiled Hand', 'the Radiant Host',
  'the Black Thorn', 'the Star Seekers', 'the Ashen Brotherhood', 'the Coral Throne',
  'the Raven Guard', 'the Sapphire Conclave', 'the Flame Wardens', 'the Frost Sentinels',
];
const EVENTS_VERB = [
  'Battle', 'Siege', 'Fall', 'Rise', 'Founding', 'Conquest', 'Liberation',
  'Betrayal', 'Alliance', 'Treaty', 'Coronation', 'Abdication', 'Exile',
  'Expedition', 'Discovery', 'Plague', 'Famine', 'Eclipse', 'Miracle',
  'Revolt', 'Schism', 'Concord', 'Pact', 'Crusade', 'Pilgrimage',
  'Inquisition', 'Festival', 'Massacre', 'Voyage', 'Summoning',
  'Cataclysm', 'Restoration', 'Burning', 'Flooding', 'Awakening',
];
const ADJ = [
  'Great', 'First', 'Last', 'Final', 'Grand', 'Bitter', 'Glorious', 'Terrible',
  'Sacred', 'Crimson', 'Silent', 'Eternal', 'Fateful', 'Ruinous', 'Golden',
  'Forgotten', 'Ancient', 'Shadowed', 'Iron', 'Radiant', 'Cursed', 'Blessed',
  'Dread', 'Twilight', 'Hidden', 'Burning', 'Frozen', 'Scarlet', 'Emerald',
];
const SPAN_NAMES = [
  'Dynasty', 'Reign', 'Republic', 'Empire', 'Dominion', 'Kingdom', 'Confederation',
  'Campaign', 'Crusade', 'War', 'Conflict', 'Uprising', 'Revolution', 'Insurrection',
  'Renaissance', 'Reformation', 'Expansion', 'Decline', 'Migration', 'Exodus',
  'Golden Age', 'Dark Age', 'Interregnum', 'Occupation', 'Resistance', 'Unification',
];
const COLORS = [
  '#6a4c93', '#4a7c59', '#c44536', '#3a86a8', '#8b6b3d', '#7c3a5e',
  '#c8a04a', '#e0e0e0', '#e06040', '#6080e0', '#50a060', '#a05070',
  '#d4a843', '#5e8ca8', '#a86040', '#408070', '#8060a0', '#b07050',
  '#3d7a6b', '#9b5b3e', '#5b6e9b', '#7b9b3e', '#9b3e6b', '#3e9b8b',
  '#c07040', '#4070c0', '#70c040', '#c04070', '#40c070', '#7040c0',
];
const NODE_TYPES  = ['event', 'milestone', 'conflict', 'disaster', 'cultural', 'legend', 'religious'];
const SPAN_TYPES  = ['kingdom', 'region', 'conflict', 'cultural', 'religious', 'character'];
const IMPORTANCE  = ['critical', 'major', 'moderate', 'moderate', 'moderate', 'minor', 'minor'];

function genEventTitle() {
  const form = randBetween(0, 4);
  switch (form) {
    case 0: return `The ${pick(ADJ)} ${pick(EVENTS_VERB)} of ${pick(PLACES)}`;
    case 1: return `${pick(PEOPLE)}'s ${pick(EVENTS_VERB)}`;
    case 2: return `${pick(EVENTS_VERB)} of ${pick(FACTIONS)}`;
    case 3: return `The ${pick(EVENTS_VERB)} at ${pick(PLACES)}`;
    case 4: return `${pick(ADJ)} ${pick(EVENTS_VERB)} of ${pick(PEOPLE)}`;
  }
}
function genSpanTitle() {
  const form = randBetween(0, 4);
  switch (form) {
    case 0: return `The ${pick(ADJ)} ${pick(SPAN_NAMES)} of ${pick(PLACES)}`;
    case 1: return `${pick(PEOPLE)}'s ${pick(SPAN_NAMES)}`;
    case 2: return `${pick(SPAN_NAMES)} of ${pick(FACTIONS)}`;
    case 3: return `The ${pick(PLACES)} ${pick(SPAN_NAMES)}`;
    case 4: return `${pick(ADJ)} ${pick(SPAN_NAMES)}`;
  }
}
function genDesc(spanTitle) {
  const templates = [
    `A period of significant change during ${spanTitle}.`,
    `Events unfolded rapidly, reshaping the balance of power.`,
    `Scholars debate the true causes to this day.`,
    `The consequences would echo through generations.`,
    `Few records survive from this turbulent time.`,
    `A turning point that none could have predicted.`,
    `Chroniclers note both great triumphs and terrible losses.`,
    `Trade routes shifted, alliances broke, and new orders arose.`,
    `The common folk bore the heaviest burden of these changes.`,
    `Songs and legends preserve what histories have forgotten.`,
  ];
  return pick(templates);
}

// ── Generate random sub-spans within a date range ────────────────────────────

function generateSubSpans(parentId, startY, endY, count, depth) {
  const totalRange = endY - startY;
  const ids = [];

  // Divide the range into roughly equal slices with gaps
  const sliceSize = Math.floor(totalRange / (count + 1));
  let cursor = startY;

  for (let i = 0; i < count; i++) {
    const gap = randBetween(Math.max(1, Math.floor(sliceSize * 0.05)), Math.max(2, Math.floor(sliceSize * 0.2)));
    const sY  = cursor + gap;
    const dur = randBetween(Math.max(1, Math.floor(sliceSize * 0.5)), Math.max(2, Math.floor(sliceSize * 0.95)));
    const eY  = Math.min(sY + dur, endY - 1);
    if (sY >= eY) { cursor = eY + 1; continue; }

    const title = genSpanTitle();
    const id = addSpan({
      parent_id: parentId,
      title, startY: sY, endY: eY,
      description: genDesc(title),
      color: pick(COLORS),
      opacity: clamp(0.5 + depth * 0.05, 0.4, 0.8),
      importance: pick(IMPORTANCE),
      node_type: pick(SPAN_TYPES),
      sort_order: i,
    });
    ids.push({ id, startY: sY, endY: eY, title });
    cursor = eY;
  }
  return ids;
}

function scatterPoints(parentId, startY, endY, count, spanTitle, color) {
  for (let i = 0; i < count; i++) {
    const year = randBetween(startY, endY);
    const d = randDate();
    addPoint({
      parent_id: parentId,
      title: genEventTitle(),
      year, month: d.month, day: d.day,
      description: genDesc(spanTitle),
      importance: pick(IMPORTANCE),
      node_type: pick(NODE_TYPES),
      color: color ?? pick(COLORS),
    });
  }
}

// ── Clear existing data ──────────────────────────────────────────────────────

db.exec(`
  DELETE FROM document_tags;
  DELETE FROM documents;
  DELETE FROM timeline_nodes;
`);

// ── Build the tree ───────────────────────────────────────────────────────────

const tx = db.transaction(() => {

  // Phase 1: build span structure, collect scatter slots
  // A slot is { parentId, startY, endY, title, color, weight }
  // Weight determines what fraction of TARGET points each slot receives.
  // Deeper/narrower spans get lower weight so points cluster realistically.

  const slots = [];
  function slot(parentId, startY, endY, title, color, weight) {
    slots.push({ parentId, startY, endY, title, color, weight });
  }

  // ── Root eras ──────────────────────────────────────────────────────────────

  const eras = [
    { title: 'Age of Myth',           startY: -10000, endY: -5000,  desc: 'The primordial age when gods walked the world and shaped reality.', color: '#6a4c93', imp: 'critical' },
    { title: 'Age of Foundation',     startY: -5000,  endY: -2000,  desc: 'Civilizations rise, nations form, and the great orders are established.', color: '#4a7c59', imp: 'critical' },
    { title: 'Age of Empires',        startY: -2000,  endY: -500,   desc: 'Mighty empires expand, clash, and vie for dominion.', color: '#c44536', imp: 'critical' },
    { title: 'Age of Fracture',       startY: -500,   endY: 200,    desc: 'Old empires fall, wars rage, and new powers emerge from the chaos.', color: '#3a86a8', imp: 'critical' },
    { title: 'Age of Restoration',    startY: 200,    endY: 800,    desc: 'Peace returns, learning flourishes, alliances are forged.', color: '#8b6b3d', imp: 'major' },
    { title: 'Age of Expansion',      startY: 800,    endY: 1500,   desc: 'Explorers push beyond known borders, new continents are mapped.', color: '#7c3a5e', imp: 'major' },
    { title: 'Age of Innovation',     startY: 1500,   endY: 2500,   desc: 'The arcane and the mechanical converge, transforming society.', color: '#c8a04a', imp: 'major' },
    { title: 'Age of Reckoning',      startY: 2500,   endY: 4000,   desc: 'Old sins resurface and the world faces an existential crisis.', color: '#c44536', imp: 'critical' },
  ];

  for (let eraIdx = 0; eraIdx < eras.length; eraIdx++) {
    const era = eras[eraIdx];
    const eraId = addSpan({
      title: era.title, startY: era.startY, endY: era.endY,
      description: era.desc, color: era.color, opacity: 0.55,
      importance: era.imp, node_type: 'region', sort_order: eraIdx,
    });

    // Loose era-level points (weight 3)
    slot(eraId, era.startY, era.endY, era.title, era.color, 3);

    // ── Level 2: major sub-spans (8-15 per era) ─────────────────────────────

    const l2spans = generateSubSpans(eraId, era.startY, era.endY, randBetween(8, 15), 1);

    for (const l2 of l2spans) {
      slot(l2.id, l2.startY, l2.endY, l2.title, null, 2);

      // ── Level 3: sub-sub-spans (3-7 per L2) ──────────────────────────────

      const l2range = l2.endY - l2.startY;
      if (l2range < 5) continue;
      const l3spans = generateSubSpans(l2.id, l2.startY, l2.endY, randBetween(4, Math.min(9, Math.floor(l2range / 2))), 2);

      for (const l3 of l3spans) {
        slot(l3.id, l3.startY, l3.endY, l3.title, null, 1.5);

        // ── Level 4: deepest sub-spans (2-4 per L3 if range allows) ─────────

        const l3range = l3.endY - l3.startY;
        if (l3range < 3) continue;
        const l4spans = generateSubSpans(l3.id, l3.startY, l3.endY, randBetween(2, Math.min(4, Math.floor(l3range / 2))), 3);

        for (const l4 of l4spans) {
          slot(l4.id, l4.startY, l4.endY, l4.title, null, 1);
        }
      }
    }
  }

  // Root-level slots (outside eras)
  slot(null, -12000, -10000, 'Pre-history', null, 1);
  slot(null, 4000, 5000, 'The Far Future', null, 1);

  // Phase 2: distribute TARGET points across slots proportionally by weight
  const totalWeight = slots.reduce((s, sl) => s + sl.weight, 0);
  let remaining = TARGET - 5; // reserve 5 for standalone named events

  for (const sl of slots) {
    const count = Math.max(1, Math.round(remaining * (sl.weight / totalWeight)));
    scatterPoints(sl.parentId, sl.startY, sl.endY, count, sl.title, sl.color);
  }

  // ── Standalone root-level points (always exactly these) ────────────────────

  const standaloneEvents = [
    { title: 'Creation of the World',     year: -10000, importance: 'critical', node_type: 'legend',    desc: 'The very beginning of recorded history.' },
    { title: 'The First Dawn',            year: -9999,  importance: 'critical', node_type: 'legend',    desc: 'Light touches the world for the first time.' },
    { title: 'The Prophecy Spoken',       year: 500,    importance: 'major',    node_type: 'religious', desc: 'A seer speaks words that will echo through the ages.' },
    { title: 'The World Compass Invented',year: 1600,   importance: 'major',    node_type: 'milestone', desc: 'Navigation is forever changed.' },
    { title: 'Present Day',              year: 4000,   importance: 'critical', node_type: 'milestone', desc: 'The current moment in the world.' },
  ];
  for (const ev of standaloneEvents) {
    addPoint({
      title: ev.title, year: ev.year,
      description: ev.desc, importance: ev.importance, node_type: ev.node_type,
    });
  }

  // ── Sample documents ──────────────────────────────────────────────────────

  const insDocs = db.prepare('INSERT INTO documents (id, timeline_id, title, category, content, created_at, updated_at) VALUES (?,?,?,?,?,?,?)');
  const insTag  = db.prepare('INSERT OR IGNORE INTO document_tags (doc_id, tag) VALUES (?,?)');
  const now = new Date().toISOString();

  const docs = [
    { id: 'doc_lore_1', title: 'The Creation Myth', category: 'Lore', tags: ['mythology', 'creation'],
      content: '# The Creation Myth\n\nIn the beginning, the Titans forged the world from primordial chaos. Their war shaped the continents and filled the seas. When the dust settled, life crept forth from the cracks between worlds.' },
    { id: 'doc_lore_2', title: 'The Celestial Schism', category: 'Lore', tags: ['mythology', 'gods'],
      content: '# The Celestial Schism\n\nThe gods divided into two courts — those who wished to guide mortals, and those who demanded worship. Their conflict rippled through reality, creating the first magical ley lines.' },
    { id: 'doc_hist_1', title: 'Rise and Fall of Aetheria', category: 'History', tags: ['aetheria', 'empires'],
      content: '# Rise and Fall of Aetheria\n\nAetheria began as a small city-state founded in the Age of Foundation. Through shrewd diplomacy and superior arcane knowledge, it grew to dominate the central continent for over two millennia before internal corruption brought it low.' },
    { id: 'doc_hist_2', title: 'The Trade Wars', category: 'History', tags: ['conflict', 'economics'],
      content: '# The Trade Wars\n\nWhen the great empires controlled all major trade routes, the merchant guilds banded together in a series of violent conflicts that reshaped the economic landscape of the known world.' },
    { id: 'doc_hist_3', title: 'The Drevak Invasions', category: 'History', tags: ['conflict', 'drevak'],
      content: '# The Drevak Invasions\n\nComing from beyond the northern wastes, the Drevak hordes swept south in three great waves. Each invasion tested the alliances of the civilized nations to breaking point.' },
    { id: 'doc_char_1', title: 'Queen Aldara', category: 'Characters', tags: ['royalty', 'aetheria'],
      content: "# Queen Aldara\n\nRuled during the Age of Fracture. Known for the Edict of Open Roads, which established free passage through all Aetherian territories. Her Silver Pact with Tharados held the peace for decades." },
    { id: 'doc_char_2', title: 'Varenthos the Wanderer', category: 'Characters', tags: ['explorer', 'legend'],
      content: '# Varenthos the Wanderer\n\nA figure half-myth, half-history. Varenthos is credited with mapping the eastern archipelago and making first contact with the deep-sea civilizations. His journals remain the primary source for pre-Expansion geography.' },
    { id: 'doc_char_3', title: 'Arandor the Rebuilder', category: 'Characters', tags: ['royalty', 'restoration'],
      content: "# Arandor the Rebuilder\n\nFollowing the devastation of the Drevak Invasions, Arandor united the fractured kingdoms under a single banner. His reign marked the transition from the Age of Fracture to the Age of Restoration." },
    { id: 'doc_geo_1', title: 'The Central Continent', category: 'Geography', tags: ['geography', 'continents'],
      content: '# The Central Continent\n\nThe largest landmass, home to most major civilizations. Divided by the Spine mountains into eastern and western halves, each with distinct cultures and climates.' },
    { id: 'doc_geo_2', title: 'The Eastern Archipelago', category: 'Geography', tags: ['geography', 'islands'],
      content: '# The Eastern Archipelago\n\nThousands of islands stretching across the warm eastern seas. Home to the coral-building Tidefolk and ancient ruins predating all known civilizations.' },
  ];

  for (const d of docs) {
    insDocs.run(d.id, 'default', d.title, d.category, d.content, now, now);
    for (const t of d.tags) insTag.run(d.id, t);
  }
});

tx();

// ── Report ───────────────────────────────────────────────────────────────────

const total  = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes').get().c;
const spans  = db.prepare("SELECT COUNT(*) AS c FROM timeline_nodes WHERE type='span'").get().c;
const points = db.prepare("SELECT COUNT(*) AS c FROM timeline_nodes WHERE type='point'").get().c;
const roots  = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes WHERE parent_id IS NULL').get().c;
const docs   = db.prepare('SELECT COUNT(*) AS c FROM documents').get().c;
const maxDepth = db.prepare(`
  WITH RECURSIVE d(id, depth) AS (
    SELECT id, 0 FROM timeline_nodes WHERE parent_id IS NULL
    UNION ALL
    SELECT n.id, d.depth + 1 FROM timeline_nodes n JOIN d ON n.parent_id = d.id
  )
  SELECT MAX(depth) FROM d
`).get()['MAX(depth)'] ?? 0;

console.log(`Seeded ${total} nodes (${spans} spans, ${points} points), ${roots} root nodes, max depth ${maxDepth}, and ${docs} documents.`);
