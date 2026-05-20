# Timeweft Feature Roadmap

Phased plan for evolving Timeweft into a full worldbuilding/campaign management platform.

**Phase 1 (Cross-Linking + Folders) is complete.** The phases below are pending.

## Dependency Graph

```
Phase 1: Cross-Linking + Folders ──────────────── DONE
    │
    ├── Phase 2: Relationships + Story Arcs ──── (Needs links)
    │       │
    │       └── Phase 3: Sessions + Inventory ── (Needs arcs)
    │
    ├── Phase 4: Glossary + Bookmarks ────────── (Needs links)
    │
    └── Phase 5: Interactive Maps ────────────── (Needs links)
            │
            └── Phase 6: Advanced Features ───── (Independent)
```

## Remaining Phase 1 Polish

- **Folder tree sidebar** for docs and entities — `FolderState`, `loadFolders()`, `buildFolderTree()`, and `renderFolderDropdown()` exist in `folders-module.js` but the collapsible tree view in the doc/entity sidebars is not yet wired up. Add a folder tree above the item list with drag-to-move, and an "Unfiled" section for items without `folder_id`.

---

## Phase 2: Entity Relationships + Story Arcs (L)

Relationship graphs (family trees, alliances) and narrative threading. Both build on Phase 1's linking system.

### Schema

```sql
CREATE TABLE entity_relationships (
  id           TEXT PRIMARY KEY,
  source_id    TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
  target_id    TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
  relationship TEXT NOT NULL,       -- 'parent_of','ally','member_of','rival','married_to','serves'
  description  TEXT,
  start_node_id TEXT REFERENCES timeline_nodes(id),  -- when it began
  end_node_id   TEXT REFERENCES timeline_nodes(id),  -- when it ended (null=ongoing)
  metadata     TEXT DEFAULT '{}',
  created_at   TEXT DEFAULT(datetime('now')),
  UNIQUE(source_id, target_id, relationship)
);

CREATE TABLE story_arcs (
  id          TEXT PRIMARY KEY,
  name        TEXT NOT NULL,
  description TEXT,
  color       TEXT DEFAULT '#c97b2a',
  status      TEXT DEFAULT 'active' CHECK(status IN ('planned','active','resolved','abandoned')),
  sort_order  INTEGER DEFAULT 0,
  metadata    TEXT DEFAULT '{}',
  created_at  TEXT DEFAULT(datetime('now')),
  updated_at  TEXT DEFAULT(datetime('now'))
);

CREATE TABLE arc_node_links (
  arc_id    TEXT NOT NULL REFERENCES story_arcs(id) ON DELETE CASCADE,
  node_id   TEXT NOT NULL REFERENCES timeline_nodes(id) ON DELETE CASCADE,
  position  INTEGER DEFAULT 0,
  arc_label TEXT,                   -- 'inciting incident','climax','resolution'
  PRIMARY KEY (arc_id, node_id)
);

CREATE TABLE arc_entity_links (
  arc_id    TEXT NOT NULL REFERENCES story_arcs(id) ON DELETE CASCADE,
  entity_id TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
  role      TEXT DEFAULT '',        -- 'protagonist','antagonist','supporting'
  PRIMARY KEY (arc_id, entity_id)
);
```

### API endpoints (~14)

- `GET/POST/PUT/DELETE /api/entities/:id/relationships`
- `GET /api/relationship-graph?entity_ids=...&depth=1` — subgraph for visualization
- `GET/POST/PUT/DELETE /api/arcs`
- `POST/DELETE /api/arcs/:id/nodes`, `POST/DELETE /api/arcs/:id/entities`

### Frontend

- **Entity viewer**: "Relationships" section — list with type/direction, "Add Relationship" with entity search
- **Relationship mini-graph**: SVG force-directed layout in entity viewer (vanilla JS, no library)
- **Arcs panel**: new nav button "Arcs" in sidebar. List with status badges, expandable event sequence
- **Gantt overlay**: colored connecting lines between arc events on the timeline
- **Node detail**: show which arcs this event belongs to

### MCP tools (9)

`add_relationship`, `remove_relationship`, `get_relationships`, `list_arcs`, `get_arc`, `create_arc`, `update_arc`, `add_arc_event`, `remove_arc_event`

### Key files

- Same pattern as Phase 1 for schema/API/MCP
- New: `src/public/js/arcs-module.js`, `src/public/js/relationship-graph.js`
- Modify: `src/public/js/gantt.js` — arc overlay rendering

---

## Phase 3: Session Logs + Campaign Management (M)

D&D/campaign features. Session logs link to arcs (Phase 2) and entities. Equipment/inventory is structured per-character.

### Schema

```sql
CREATE TABLE sessions (
  id             TEXT PRIMARY KEY,
  session_number INTEGER NOT NULL,
  title          TEXT,
  date_played    TEXT,               -- real-world date
  summary        TEXT,               -- markdown
  start_node_id  TEXT REFERENCES timeline_nodes(id),
  end_node_id    TEXT REFERENCES timeline_nodes(id),
  status         TEXT DEFAULT 'completed' CHECK(status IN ('planned','in_progress','completed')),
  metadata       TEXT DEFAULT '{}',
  created_at     TEXT DEFAULT(datetime('now')),
  updated_at     TEXT DEFAULT(datetime('now'))
);

CREATE TABLE session_entity_links (
  session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
  entity_id  TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
  role       TEXT DEFAULT '',         -- 'player','npc','mentioned'
  PRIMARY KEY (session_id, entity_id)
);

CREATE TABLE session_arc_links (
  session_id TEXT NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
  arc_id     TEXT NOT NULL REFERENCES story_arcs(id) ON DELETE CASCADE,
  notes      TEXT,
  PRIMARY KEY (session_id, arc_id)
);

CREATE TABLE inventory_items (
  id          TEXT PRIMARY KEY,
  entity_id   TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  item_type   TEXT DEFAULT 'item' CHECK(item_type IN ('weapon','armor','potion','tool','currency','magic_item','mundane','other')),
  quantity    INTEGER DEFAULT 1,
  weight      REAL,
  value       TEXT,                   -- "50 gp"
  description TEXT,
  properties  TEXT DEFAULT '{}',     -- { damage:"1d8", rarity:"rare" }
  equipped    INTEGER DEFAULT 0,
  sort_order  INTEGER DEFAULT 0,
  created_at  TEXT DEFAULT(datetime('now')),
  updated_at  TEXT DEFAULT(datetime('now'))
);
```

### Frontend

- **Sessions panel**: new nav button. List by session number, detail with summary/participants/arcs
- **Entity viewer**: "Inventory" section for character-type entities (equipped indicators, weight total)
- **Campaign dashboard**: optional landing showing latest session, active arcs, character roster

### Permissions

- Sessions → `timeline` permission area; Inventory → `entities` permission area
- Campaign sync works naturally via existing `timeline_shares`

---

## Phase 4: Glossary + Bookmarks (S)

`index.html` already stubs a "Glossary" nav button. Lightweight phase: searchable terminology + quick navigation.

### Schema

```sql
-- Per-timeline DB
CREATE TABLE glossary (
  id         TEXT PRIMARY KEY,
  term       TEXT NOT NULL,
  definition TEXT,
  category   TEXT DEFAULT 'General',
  entity_id  TEXT REFERENCES entities(id) ON DELETE SET NULL,
  metadata   TEXT DEFAULT '{}',
  created_at TEXT DEFAULT(datetime('now')),
  updated_at TEXT DEFAULT(datetime('now'))
);

-- Accounts DB (bookmarks are per-user)
CREATE TABLE bookmarks (
  id            TEXT PRIMARY KEY,
  user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  timeline_id   TEXT NOT NULL REFERENCES timelines(id) ON DELETE CASCADE,
  resource_type TEXT NOT NULL CHECK(resource_type IN ('node','entity','doc','arc','session','glossary')),
  resource_id   TEXT NOT NULL,
  label         TEXT,
  created_at    TEXT DEFAULT(datetime('now')),
  UNIQUE(user_id, timeline_id, resource_type, resource_id)
);
```

### Frontend

- Activate existing Glossary nav button stub
- Bookmark button in all detail views, bookmark bar at top of panels

---

## Phase 5: Interactive Maps (XL)

Largest phase — new rendering engine, image handling, spatial data.

### Schema

```sql
CREATE TABLE maps (
  id        TEXT PRIMARY KEY,
  parent_id TEXT REFERENCES maps(id) ON DELETE CASCADE,
  name      TEXT NOT NULL,
  description TEXT,
  image_path TEXT,
  width     INTEGER,
  height    INTEGER,
  parent_x  REAL, parent_y REAL,     -- normalized 0-1 coords on parent map
  parent_w  REAL, parent_h REAL,
  metadata  TEXT DEFAULT '{}',
  sort_order INTEGER DEFAULT 0,
  created_at TEXT DEFAULT(datetime('now')),
  updated_at TEXT DEFAULT(datetime('now'))
);

CREATE TABLE map_pins (
  id        TEXT PRIMARY KEY,
  map_id    TEXT NOT NULL REFERENCES maps(id) ON DELETE CASCADE,
  x REAL NOT NULL, y REAL NOT NULL,  -- normalized 0-1
  label     TEXT,
  pin_type  TEXT DEFAULT 'point' CHECK(pin_type IN ('point','area','path')),
  icon      TEXT DEFAULT 'default',
  color     TEXT DEFAULT '#e05c5c',
  entity_id TEXT REFERENCES entities(id) ON DELETE SET NULL,
  node_id   TEXT REFERENCES timeline_nodes(id) ON DELETE SET NULL,
  doc_id    TEXT REFERENCES documents(id) ON DELETE SET NULL,
  visible_from REAL,                 -- decimal year for temporal filtering
  visible_until REAL,
  metadata  TEXT DEFAULT '{}',
  created_at TEXT DEFAULT(datetime('now'))
);

CREATE TABLE map_territories (
  id           TEXT PRIMARY KEY,
  map_id       TEXT NOT NULL REFERENCES maps(id) ON DELETE CASCADE,
  entity_id    TEXT REFERENCES entities(id) ON DELETE CASCADE,
  name         TEXT,
  color        TEXT DEFAULT '#5566bb44',
  border_color TEXT DEFAULT '#5566bb',
  polygon      TEXT NOT NULL,        -- JSON [[x,y],...]
  valid_from   REAL,
  valid_until  REAL,
  metadata     TEXT DEFAULT '{}',
  created_at   TEXT DEFAULT(datetime('now'))
);
```

### Frontend

- Activate existing Maps nav button stub
- Pan/zoom map viewer (CSS transform, no library)
- Pins as positioned markers, territories as SVG polygon overlays
- Hierarchical drill-down (breadcrumb like timeline zoom)
- Timeline-map sync: date scrubber filters visible pins/territories
- Pin editor modal, polygon drawing tool

### New permission area

- Add `perm_maps` column to `timeline_shares` table
- Update `PRESET_PERMS` and `updateShareBodyClasses`

### File storage

- Map images at `data/users/{userId}/timelines/{timelineId}/maps/{mapId}.{ext}`
- Express static serving route

---

## Phase 6: Advanced Features (L, individually S-M)

Independent features that can be interleaved after Phase 1:

### 6A: Cause-and-Effect Chains (S)
- `node_causal_links` table (cause_id, effect_id, link_type: caused/enabled/prevented/triggered)
- "Caused by" / "Led to" sections in node detail; optional causal arrows on Gantt

### 6B: Random Generators (S)
- No schema — stateless utilities (name, encounter, loot generators)
- Configurable via settings or special documents
- Particularly useful via MCP for LLM interaction

### 6C: Collaborative Presence (S)
- In-memory tracking (no schema), polling-based
- Avatar indicators in header showing online users

### 6D: Timeline Comparison (M)
- Overlay events from two timelines as ghost bars on Gantt
- Requires read access to both timelines

### 6E: Weather/Climate (S)
- `climate_zones` table linked to location entities
- Procedural weather generation based on calendar date + zone

---

## Implementation Pattern (all phases)

Every feature follows the same file pattern:

| Layer | File | Action |
|-------|------|--------|
| Schema | `src/db/connection.js` | `_initTimelineSchema` for new tables, `_migrateSchema` for ALTERs, update `TIMELINE_TABLES` |
| API | `src/server/api.js` | New routes with `requirePerm()` / `handleDelete()` |
| MCP | `src/mcp/server.js` | Tool definitions + handler functions |
| Frontend | `src/public/js/<feature>-module.js` | New module following existing patterns |
| HTML | `src/public/index.html` | Panel markup |
| CSS | `src/public/css/style.css` | Styling |
| Permissions | `src/public/css/style.css` | `.share-no-*` rules for new UI elements |

## Verification (after each phase)

1. Start server (`npm run dev`), create test timeline
2. Test all new CRUD operations via UI
3. Test with shared timeline (read-only user should not see edit controls)
4. Test MCP tools via Claude Desktop or direct stdin
5. Test import/export still works (new tables included in `TIMELINE_TABLES`)
