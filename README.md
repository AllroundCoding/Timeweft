# World Timelines

A local web app + MCP server for building and managing timelines for creative worldbuilding projects. Track events, characters, factions, locations, and documents — all connected through a hierarchical, calendar-aware timeline.

## Features

- **Interactive Timeline** — Gantt-style horizontal timeline with hierarchical nodes, zoom, filters, and a detail panel
- **Custom Calendars** — Define your own time systems with custom eras, months, and date formats
- **Entities** — Manage characters, factions, locations, and items linked to timeline events
- **Documents** — Write and organize markdown documents with tags, categories, and @mention support
- **REST API** — Express server backed by SQLite
- **MCP Server** — Connect to Claude Desktop to build and query your timeline through conversation

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

## MCP Server — Claude Desktop Integration

The MCP server lets you interact with your timeline from Claude chat.

### Setup

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

## Data

All data is stored in a local SQLite database. The database is created automatically on first run.

## Tech Stack

- **Backend:** Express, better-sqlite3, MCP SDK
- **Frontend:** Vanilla JS, CSS (no build step)
- **Database:** SQLite
