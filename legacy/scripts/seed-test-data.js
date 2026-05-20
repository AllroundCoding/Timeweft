'use strict';
const { getAccountsDb, getTimelineDb } = require('../src/db/connection');
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

// Get the user's first timeline
const timeline = accountsDb.prepare('SELECT id, name FROM timelines WHERE owner_id = ? ORDER BY created_at LIMIT 1').get(user.id);
if (!timeline) {
  console.error(`Error: user "${user.username}" has no timelines. Log in first to create one.`);
  process.exit(1);
}

console.log(`Seeding into ${user.username}'s timeline "${timeline.name}" (${timeline.id})`);
const db = getTimelineDb(user.id, timeline.id);
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
  DELETE FROM arc_entity_links;
  DELETE FROM arc_node_links;
  DELETE FROM story_arcs;
  DELETE FROM entity_relationships;
  DELETE FROM entity_node_links;
  DELETE FROM entity_doc_links;
  DELETE FROM doc_node_links;
  DELETE FROM entities;
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

  // ── Entities ────────────────────────────────────────────────────────────────

  const insEntity = db.prepare(`INSERT OR IGNORE INTO entities
    (id, timeline_id, name, entity_type, description, color, metadata, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?)`);
  const insEntNodeLink = db.prepare('INSERT OR IGNORE INTO entity_node_links (entity_id, node_id, role) VALUES (?,?,?)');
  const insEntDocLink  = db.prepare('INSERT OR IGNORE INTO entity_doc_links (entity_id, doc_id, role) VALUES (?,?,?)');

  const ENTITY_COLORS = {
    character: '#7c6bff', faction: '#e05c5c', location: '#4a9b6f',
    item: '#c8a04a', creature: '#8b6b3d', concept: '#3a86a8',
  };

  const entities = [
    // ── Characters (with family connections) ──────────────────────────────────
    { id: 'ent_aldara',      name: 'Queen Aldara',        type: 'character', desc: 'Wise ruler during the Age of Fracture. Architect of the Silver Pact.' },
    { id: 'ent_varenthos',   name: 'Varenthos',           type: 'character', desc: 'Legendary explorer who mapped the eastern archipelago.' },
    { id: 'ent_arandor',     name: 'Arandor the Rebuilder', type: 'character', desc: 'United the fractured kingdoms after the Drevak Invasions.' },
    { id: 'ent_drevak',      name: 'Warlord Drevak',      type: 'character', desc: 'Led the northern hordes in three devastating invasion waves.' },
    { id: 'ent_seraphel',    name: 'Seraphel',            type: 'character', desc: 'High priestess who spoke the great prophecy in the Age of Restoration.' },
    { id: 'ent_theron',      name: 'Theron Aldaris',      type: 'character', desc: 'Son of Queen Aldara. Led the defense of Silverhold.' },
    { id: 'ent_isolde',      name: 'Isolde Aldaris',      type: 'character', desc: 'Daughter of Queen Aldara. Diplomat who brokered the Dawn Compact alliance.' },
    { id: 'ent_kael',        name: 'Kael Aldaris',        type: 'character', desc: 'Grandson of Theron. Rose to become a general during the Restoration.' },
    { id: 'ent_elara',       name: 'Elara Windhollow',    type: 'character', desc: 'Scholar and inventor during the Age of Innovation.' },
    { id: 'ent_gorath',      name: 'Gorath the Undying',  type: 'character', desc: 'Ancient warlord cursed with immortality. Witnessed multiple ages.' },
    { id: 'ent_nerissa',     name: 'Nerissa Tideborn',    type: 'character', desc: 'Tidefolk ambassador who established ties between the archipelago and the mainland.' },
    { id: 'ent_lysander',    name: 'Lysander Brightforge', type: 'character', desc: 'Master artificer who created the World Compass.' },
    { id: 'ent_corvus',      name: 'Corvus Drevaki',      type: 'character', desc: 'Son of Warlord Drevak. Defected to ally with Arandor.' },
    { id: 'ent_freya',       name: 'Freya Stormcaller',   type: 'character', desc: 'Legendary mage who turned the tide at the Battle of Stormreach.' },
    { id: 'ent_morkhan',     name: 'Morkhan the Elder',   type: 'character', desc: 'Patriarch of the Aldaris dynasty. Father of Aldara.' },

    // ── Factions ──────────────────────────────────────────────────────────────
    { id: 'ent_silver_flame', name: 'The Silver Flame',    type: 'faction', desc: 'Holy order dedicated to protecting the realm from dark forces.' },
    { id: 'ent_iron_circle',  name: 'The Iron Circle',     type: 'faction', desc: 'Military alliance of the northern kingdoms.' },
    { id: 'ent_dawn_compact', name: 'The Dawn Compact',    type: 'faction', desc: 'Alliance of free cities formed during the Age of Fracture.' },
    { id: 'ent_obsidian',     name: 'The Obsidian Court',  type: 'faction', desc: 'Secretive cabal of shadow mages seeking forbidden knowledge.' },
    { id: 'ent_emerald',      name: 'The Emerald Order',   type: 'faction', desc: 'Druidic circle protecting the ancient forests.' },
    { id: 'ent_tidefolk',     name: 'The Tidefolk',        type: 'faction', desc: 'Coral-building civilization of the eastern archipelago.' },

    // ── Locations ─────────────────────────────────────────────────────────────
    { id: 'ent_silverhold',  name: 'Silverhold',           type: 'location', desc: 'Capital city of the Aldaris dynasty. Seat of power for centuries.' },
    { id: 'ent_stormreach',  name: 'Stormreach',           type: 'location', desc: 'Coastal fortress where the decisive battle against Drevak was fought.' },
    { id: 'ent_ashvale',     name: 'Ashvale',              type: 'location', desc: 'Volcanic region rich in arcane minerals. Home to master artificers.' },
    { id: 'ent_archipelago',  name: 'The Eastern Archipelago', type: 'location', desc: 'Vast island chain stretching across the warm eastern seas.' },
    { id: 'ent_spine',       name: 'The Spine Mountains',  type: 'location', desc: 'Continental divide separating east from west.' },

    // ── Items ─────────────────────────────────────────────────────────────────
    { id: 'ent_world_compass', name: 'The World Compass',  type: 'item', desc: 'Legendary navigational artifact crafted by Lysander Brightforge.' },
    { id: 'ent_silver_pact',   name: 'The Silver Pact',    type: 'item', desc: 'Treaty scroll binding the Dawn Compact nations to mutual defense.' },
  ];

  for (const e of entities) {
    insEntity.run(e.id, 'default', e.name, e.type, e.desc,
      ENTITY_COLORS[e.type] || '#7c6bff', '{}', now, now);
  }

  // ── Entity ↔ Node links (tie some entities to existing timeline events) ───

  // Grab some node IDs to link. We'll pick from the standalone events and a few era spans.
  const someNodes = db.prepare("SELECT id, title FROM timeline_nodes WHERE parent_id IS NULL AND type = 'span' LIMIT 8").all();
  const somePoints = db.prepare("SELECT id, title FROM timeline_nodes WHERE parent_id IS NULL AND type = 'point'").all();

  // Link characters to relevant events
  const entityNodePairs = [
    ['ent_aldara',    someNodes[3]?.id,  'ruler'],       // Age of Fracture
    ['ent_arandor',   someNodes[4]?.id,  'founder'],     // Age of Restoration
    ['ent_drevak',    someNodes[3]?.id,  'antagonist'],  // Age of Fracture
    ['ent_varenthos', someNodes[5]?.id,  'explorer'],    // Age of Expansion
    ['ent_seraphel',  somePoints[2]?.id, 'speaker'],     // The Prophecy Spoken
    ['ent_lysander',  somePoints[3]?.id, 'inventor'],    // World Compass Invented
    ['ent_elara',     someNodes[6]?.id,  'innovator'],   // Age of Innovation
    ['ent_gorath',    someNodes[0]?.id,  'witness'],     // Age of Myth
    ['ent_nerissa',   someNodes[5]?.id,  'ambassador'],  // Age of Expansion
    ['ent_freya',     someNodes[3]?.id,  'defender'],    // Age of Fracture
    // Factions
    ['ent_silver_flame', someNodes[4]?.id, 'protector'],
    ['ent_iron_circle',  someNodes[2]?.id, 'military'],
    ['ent_dawn_compact', someNodes[3]?.id, 'alliance'],
    ['ent_tidefolk',     someNodes[5]?.id, 'civilization'],
    // Locations
    ['ent_silverhold',  someNodes[3]?.id, 'capital'],
    ['ent_stormreach',  someNodes[3]?.id, 'battlefield'],
  ];
  for (const [entId, nodeId, role] of entityNodePairs) {
    if (nodeId) insEntNodeLink.run(entId, nodeId, role);
  }

  // ── Entity ↔ Doc links ──────────────────────────────────────────────────────

  const entityDocPairs = [
    ['ent_aldara',    'doc_char_1', 'subject'],
    ['ent_varenthos', 'doc_char_2', 'subject'],
    ['ent_arandor',   'doc_char_3', 'subject'],
    ['ent_drevak',    'doc_hist_3', 'antagonist'],
    ['ent_silverhold','doc_hist_1', 'location'],
    ['ent_archipelago','doc_geo_2', 'subject'],
    ['ent_spine',     'doc_geo_1',  'feature'],
  ];
  for (const [entId, docId, role] of entityDocPairs) {
    insEntDocLink.run(entId, docId, role);
  }

  // ── Entity Relationships ──────────────────────────────────────────────────

  const insRel = db.prepare(`INSERT OR IGNORE INTO entity_relationships
    (id, source_id, target_id, relationship, description, start_node_id, end_node_id, metadata, created_at)
    VALUES (?,?,?,?,?,?,?,?,?)`);

  let _relN = 0;
  const relId = () => `rel_${++_relN}`;

  const relationships = [
    // ── Aldaris Family Tree ───────────────────────────────────────────────────
    { src: 'ent_morkhan',  tgt: 'ent_aldara',    rel: 'parent_of',  desc: 'Father of Queen Aldara' },
    { src: 'ent_aldara',   tgt: 'ent_theron',    rel: 'parent_of',  desc: 'Theron is the eldest son of Aldara' },
    { src: 'ent_aldara',   tgt: 'ent_isolde',    rel: 'parent_of',  desc: 'Isolde is Aldara\'s daughter' },
    { src: 'ent_theron',   tgt: 'ent_kael',      rel: 'parent_of',  desc: 'Kael is the grandson of Aldara through Theron' },
    { src: 'ent_theron',   tgt: 'ent_isolde',    rel: 'sibling_of', desc: 'Brother and sister' },

    // ── Drevak family ─────────────────────────────────────────────────────────
    { src: 'ent_drevak',   tgt: 'ent_corvus',    rel: 'parent_of',  desc: 'Corvus defected from his father\'s horde' },

    // ── Political / alliances ─────────────────────────────────────────────────
    { src: 'ent_aldara',   tgt: 'ent_arandor',   rel: 'ally_of',    desc: 'Allied during the Age of Fracture to restore order' },
    { src: 'ent_corvus',   tgt: 'ent_arandor',   rel: 'ally_of',    desc: 'Corvus defected to join Arandor\'s cause' },
    { src: 'ent_drevak',   tgt: 'ent_arandor',   rel: 'enemy_of',   desc: 'Bitter enemies during the Drevak Invasions' },
    { src: 'ent_drevak',   tgt: 'ent_aldara',    rel: 'enemy_of',   desc: 'Aldara\'s kingdom was a primary target of Drevak\'s invasions' },
    { src: 'ent_gorath',   tgt: 'ent_drevak',    rel: 'rival_of',   desc: 'Ancient rivals competing for dominance of the north' },

    // ── Organizational ────────────────────────────────────────────────────────
    { src: 'ent_seraphel', tgt: 'ent_silver_flame', rel: 'leads',     desc: 'High priestess of the Silver Flame' },
    { src: 'ent_freya',    tgt: 'ent_iron_circle',  rel: 'member_of', desc: 'Battle mage serving the Iron Circle' },
    { src: 'ent_isolde',   tgt: 'ent_dawn_compact', rel: 'leads',     desc: 'Chief diplomat of the Dawn Compact' },
    { src: 'ent_nerissa',  tgt: 'ent_tidefolk',     rel: 'member_of', desc: 'Ambassador of the Tidefolk' },
    { src: 'ent_elara',    tgt: 'ent_emerald',      rel: 'member_of', desc: 'Scholar affiliated with the Emerald Order' },

    // ── Faction alliances/rivalries ───────────────────────────────────────────
    { src: 'ent_silver_flame', tgt: 'ent_dawn_compact', rel: 'ally_of',  desc: 'United front against the Obsidian Court' },
    { src: 'ent_silver_flame', tgt: 'ent_obsidian',     rel: 'enemy_of', desc: 'Eternal enemies: light vs shadow' },
    { src: 'ent_iron_circle',  tgt: 'ent_dawn_compact', rel: 'ally_of',  desc: 'Military backing for the free cities' },

    // ── Spatial ───────────────────────────────────────────────────────────────
    { src: 'ent_silverhold',   tgt: 'ent_spine',        rel: 'located_in', desc: 'Silverhold sits at the base of the Spine Mountains' },
    { src: 'ent_stormreach',   tgt: 'ent_archipelago',  rel: 'located_in', desc: 'Coastal fortress near the archipelago' },

    // ── Possession / creation ─────────────────────────────────────────────────
    { src: 'ent_lysander',     tgt: 'ent_world_compass', rel: 'created_by', desc: 'Lysander invented the World Compass' },
    { src: 'ent_aldara',       tgt: 'ent_silver_pact',   rel: 'owns',       desc: 'Aldara authored and holds the Silver Pact' },
  ];

  for (const r of relationships) {
    insRel.run(relId(), r.src, r.tgt, r.rel, r.desc, null, null, '{}', now);
  }

  // ── Story Arcs ────────────────────────────────────────────────────────────

  const insArc = db.prepare(`INSERT OR IGNORE INTO story_arcs
    (id, name, description, color, status, sort_order, metadata, created_at, updated_at)
    VALUES (?,?,?,?,?,?,?,?,?)`);
  const insArcNode   = db.prepare('INSERT OR IGNORE INTO arc_node_links (arc_id, node_id, position, arc_label) VALUES (?,?,?,?)');
  const insArcEntity = db.prepare('INSERT OR IGNORE INTO arc_entity_links (arc_id, entity_id, role) VALUES (?,?,?)');

  // Fetch some deeper nodes to attach to arcs
  const fracNodes = db.prepare("SELECT id, title, start_date FROM timeline_nodes WHERE parent_id IS NOT NULL ORDER BY start_date LIMIT 60").all();

  const arcs = [
    {
      id: 'arc_drevak_wars', name: 'The Drevak Invasions',
      desc: 'Three devastating waves of invasion from the northern wastes that tested every alliance to the breaking point.',
      color: '#c44536', status: 'resolved', sort: 0,
      entities: [
        ['ent_drevak', 'antagonist'], ['ent_arandor', 'protagonist'],
        ['ent_corvus', 'turncoat'], ['ent_freya', 'defender'],
        ['ent_iron_circle', 'military'], ['ent_stormreach', 'battlefield'],
      ],
      nodeSlice: [0, 8], // indices into fracNodes
      labels: ['First signs', 'First wave strikes', 'Fall of outer forts', 'Corvus defects',
               'Alliance forms', 'Battle of Stormreach', 'The rout', 'Peace declared'],
    },
    {
      id: 'arc_aldaris_dynasty', name: 'Rise of House Aldaris',
      desc: 'From minor nobility to rulers of the largest kingdom — the Aldaris family shaped an era.',
      color: '#6a4c93', status: 'resolved', sort: 1,
      entities: [
        ['ent_morkhan', 'founder'], ['ent_aldara', 'protagonist'],
        ['ent_theron', 'heir'], ['ent_isolde', 'diplomat'],
        ['ent_kael', 'legacy'], ['ent_silverhold', 'seat of power'],
      ],
      nodeSlice: [8, 16],
      labels: ['Morkhan takes the throne', 'Aldara crowned', 'Silver Pact signed', 'Theron born',
               'Isolde\'s diplomacy', 'Dawn Compact formed', 'Theron\'s defense', 'Kael rises'],
    },
    {
      id: 'arc_shadow_war', name: 'The Shadow War',
      desc: 'The hidden conflict between the Silver Flame and the Obsidian Court, fought in secret across centuries.',
      color: '#2a2a4a', status: 'active', sort: 2,
      entities: [
        ['ent_seraphel', 'protagonist'], ['ent_obsidian', 'antagonist'],
        ['ent_silver_flame', 'protagonist'], ['ent_gorath', 'wild card'],
      ],
      nodeSlice: [16, 22],
      labels: ['First infiltration', 'Obsidian reveals power', 'Silver Flame mobilizes',
               'Gorath intervenes', 'Shadow siege', 'Uneasy truce'],
    },
    {
      id: 'arc_tidefolk_contact', name: 'First Contact with the Tidefolk',
      desc: 'The discovery and integration of the coral-building Tidefolk civilization into the wider world.',
      color: '#3a86a8', status: 'resolved', sort: 3,
      entities: [
        ['ent_varenthos', 'explorer'], ['ent_nerissa', 'ambassador'],
        ['ent_tidefolk', 'civilization'], ['ent_archipelago', 'location'],
      ],
      nodeSlice: [22, 28],
      labels: ['Varenthos departs', 'Archipelago sighted', 'First contact', 'Trade established',
               'Nerissa\'s embassy', 'Full alliance'],
    },
    {
      id: 'arc_innovation', name: 'The Arcane Revolution',
      desc: 'A wave of magical-mechanical innovation that transforms society, led by visionary artificers.',
      color: '#c8a04a', status: 'active', sort: 4,
      entities: [
        ['ent_elara', 'protagonist'], ['ent_lysander', 'inventor'],
        ['ent_world_compass', 'artifact'], ['ent_emerald', 'faction'],
        ['ent_ashvale', 'location'],
      ],
      nodeSlice: [28, 35],
      labels: ['First arcane forge', 'Ashvale mines opened', 'Elara\'s breakthrough',
               'World Compass created', 'Emerald Order protests', 'Regulation debates', 'New age dawns'],
    },
    {
      id: 'arc_reckoning', name: 'Seeds of the Reckoning',
      desc: 'Ancient sins begin to resurface. Omens and disasters foretell a coming crisis.',
      color: '#8b2020', status: 'planned', sort: 5,
      entities: [
        ['ent_gorath', 'harbinger'], ['ent_seraphel', 'prophet'],
      ],
      nodeSlice: [35, 40],
      labels: ['First omen', 'Gorath\'s warning', 'Earthquakes begin', 'Ancient seal cracks', 'The gathering'],
    },
  ];

  for (const arc of arcs) {
    insArc.run(arc.id, arc.name, arc.desc, arc.color, arc.status, arc.sort, '{}', now, now);

    // Link entities
    for (const [entId, role] of arc.entities) {
      insArcEntity.run(arc.id, entId, role);
    }

    // Link timeline nodes with labels
    const arcNodes = fracNodes.slice(arc.nodeSlice[0], arc.nodeSlice[1]);
    arcNodes.forEach((n, i) => {
      insArcNode.run(arc.id, n.id, i, arc.labels[i] || null);
    });
  }
});

tx();

// ── Report ───────────────────────────────────────────────────────────────────

const total  = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes').get().c;
const spans  = db.prepare("SELECT COUNT(*) AS c FROM timeline_nodes WHERE type='span'").get().c;
const points = db.prepare("SELECT COUNT(*) AS c FROM timeline_nodes WHERE type='point'").get().c;
const roots  = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes WHERE parent_id IS NULL').get().c;
const docCount = db.prepare('SELECT COUNT(*) AS c FROM documents').get().c;
const entCount = db.prepare('SELECT COUNT(*) AS c FROM entities').get().c;
const relCount = db.prepare('SELECT COUNT(*) AS c FROM entity_relationships').get().c;
const arcCount = db.prepare('SELECT COUNT(*) AS c FROM story_arcs').get().c;
const arcNodeCount = db.prepare('SELECT COUNT(*) AS c FROM arc_node_links').get().c;
const maxDepth = db.prepare(`
  WITH RECURSIVE d(id, depth) AS (
    SELECT id, 0 FROM timeline_nodes WHERE parent_id IS NULL
    UNION ALL
    SELECT n.id, d.depth + 1 FROM timeline_nodes n JOIN d ON n.parent_id = d.id
  )
  SELECT MAX(depth) FROM d
`).get()['MAX(depth)'] ?? 0;

console.log(`Seeded ${total} nodes (${spans} spans, ${points} points), ${roots} root nodes, max depth ${maxDepth}`);
console.log(`  ${docCount} documents, ${entCount} entities, ${relCount} relationships`);
console.log(`  ${arcCount} story arcs with ${arcNodeCount} arc-node links`);
