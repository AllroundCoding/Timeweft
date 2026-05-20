# World Timelines

**[Try the live demo](https://demo.timeweft.app/)** — login with `demo` / `demo`

A local web app + MCP server for building and managing timelines for creative worldbuilding projects. Track events, characters, factions, locations, and documents — all connected through a hierarchical, calendar-aware timeline.

## Screenshots

![Timeline View](docs/screenshots/timeline.png)
![Entities & Relationships](docs/screenshots/entities.png)
![Story Arcs](docs/screenshots/arcs.png)
![Documents](docs/screenshots/docs.png)

## Features

- **Interactive Timeline** — Gantt-style horizontal timeline with hierarchical nodes, zoom, filters, and a detail panel
- **Custom Calendars** — Define your own time systems with custom eras, months, and date formats
- **Entities & Relationships** — Manage characters, factions, locations, and items with family trees and relationship graphs
- **Story Arcs** — Weave narrative threads through timeline events with arc overlays on the gantt
- **Documents** — Write and organize markdown documents with tags, categories, and @mention support
- **REST API** — Express server backed by SQLite
- **MCP Server** — Connect to any LLM to build and query your timeline through conversation

## Quick Start

```bash
npm install
node index.js
```

Open **http://localhost:3000**

For development with auto-reload:

```bash
npm run dev
```

## Using the App

| View | Description |
|------|-------------|
| **Timeline** | Drag to scroll, zoom with +/− or Ctrl+scroll. Click nodes to view details. Right-click for context menu. |
| **Entities** | Browse and edit characters, factions, locations, and items. Link them to timeline events. |
| **Docs** | Create markdown documents with tags and @mentions to entities and events. |
| **Settings** | Configure world bounds, default view range, split-pane layout, and calendar systems. |

### Working with Nodes

Timeline events are organized as a **tree** — nodes can contain child nodes to any depth (e.g. an era contains events, which contain sub-events). Each node can have:

- A title and description
- Start/end dates (using the active calendar system)
- A type and importance level
- A color
- Linked entities

## MCP Server — LLM Integration

The MCP server lets you interact with your timeline from llm's with mcp support.

### Claude Setup example

Add this to your Claude Desktop config:

- **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
- **Linux:** `~/.claude.json`

```json
{
  "mcpServers": {
    "world-timelines": {
      "command": "node",
      "args": ["/FULL/PATH/TO/timeline/src/mcp/server.js"]
    }
  }
}
```

Then restart Claude Desktop.

---
### MCP Tools

| Tool | Description |
|------|-------------|
| `list_nodes` | List children of a parent node (or root nodes) |
| `get_node` | Get full details of a single node |
| `get_tree` | Get a node and all its descendants as a nested tree |
| `search_nodes` | Search nodes by text, type, importance, or date range |
| `add_node` | Create a new timeline node |
| `update_node` | Modify an existing node |
| `delete_node` | Remove a node and its descendants |
| `move_node` | Rearrange nodes within the tree |
| `get_stats` | Overview stats — total nodes, depth, date range, distribution |

### Example prompts

- *"Add a new era called 'The Golden Age' spanning years 400 to 800"*
- *"What events happened between year 100 and 500?"*
- *"Create a character named Aldric and link them to the Battle of Ironhold"*
- *"Show me the full tree under the Age of Expansion"*
- *"Give me an overview of the timeline stats"*

## Authentication & Users

The app supports multi-user access with registration, login, and role-based permissions.

- **First user** to register is automatically assigned the **admin** role
- Admins can create, update, deactivate, and delete other users
- Authentication uses **JWT tokens** (24-hour expiry) or **API keys** for programmatic access
- API keys use the format `tl_...` and are returned once on creation — stored as SHA256 hashes

### Routes

| Route | Description |
|-------|-------------|
| `POST /api/auth/register` | Create a new account |
| `POST /api/auth/login` | Log in and receive a JWT |
| `GET /api/auth/me` | Get current user profile |
| `PUT /user/profile` | Update display name or password |

## Data & Per-User Isolation

The app uses a **hybrid database model** with two layers of SQLite storage:

### Accounts Database (`data/accounts.db`)

A single shared database for authentication and cross-user concerns:

- **users** — credentials, roles, activation status
- **api_keys** — hashed API keys with usage tracking
- **global_settings** — JWT secret, registration status
- **timeline_shares** — sharing permissions between users

### Per-User Timeline Database (`data/users/{userId}/timeline.db`)

Each user gets their own isolated SQLite file containing all their timeline data:

- **timeline_nodes** — hierarchical tree of events and eras
- **calendars** — custom calendar systems
- **documents** — markdown documents with tags
- **entities** — characters, factions, locations, items
- **entity_node_links** — relationships between entities and events
- **settings** — world bounds, view preferences

Databases are created automatically on registration and cached in memory at runtime.

### Timeline Sharing

Users can share their timelines with others at varying permission levels:

| Level | Allows |
|-------|--------|
| **read** | View nodes, documents, and entities |
| **edit** | Read + create and modify content |
| **review** | Edit + request deletions (pending owner approval) |
| **full** | All operations including immediate deletion |

When accessing a shared timeline, the client sends an `X-Timeline-Owner` header — the middleware switches the request's database context to the owner's database while enforcing the granted permissions.

### Legacy Migration

If upgrading from a pre-auth single-user setup, the existing `timeline.db` is automatically migrated into the first registered user's database.

## Tech Stack

- **Backend:** Express, better-sqlite3, MCP SDK
- **Frontend:** Vanilla JS, CSS (no build step)
- **Database:** SQLite
