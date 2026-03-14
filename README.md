# UM Songs Played Manager

A WordPress plugin for Ultimate Member that lets users build and manage a structured song library on their profile.

This plugin was designed for musician-facing workflows where users need to search for songs, save structured metadata, review duplicates, and manage their list through a cleaner profile experience than a raw text field.

## Features

- Ultimate Member profile integration
- Search-based song picker using Select2
- Structured JSON storage in user meta
- Profile table rendering for saved songs
- Dedicated management tab with:
  - search
  - filters
  - sorting
  - pagination
  - bulk delete
  - duplicate review workflow
- Curated starter-song tools
- Optional webhook dispatch on song changes

## Screenshots

### Library view
![Library view](screenshots/songs-library-view.png)

### Manage songs
![Manage songs](screenshots/songs-manage-songs-view.png)

### Duplicate review
![Duplicate review](screenshots/songs-duplicate-review-view.png)

## Why I built it

The goal was to replace a clunky freeform profile field with a more structured and user-friendly interface. The plugin focuses on practical UX improvements for real users while keeping the data model flexible enough for downstream integrations.

## Tech notes

- PHP
- WordPress
- Ultimate Member
- jQuery
- Select2
- REST API endpoints
- JSON-based user meta storage

## Main workflow

Users can:

1. Open their Songs tab in their profile
2. Search for a song and add it
3. Review duplicates
4. Filter and sort their list
5. Save changes back to structured user meta
6. Optionally trigger webhook updates for external systems

## Project structure

- `fnf-um-songs-played.php` - plugin bootstrap
- `includes/songs-played.php` - main plugin logic
- `assets/js/fnf-songs-tab.js` - management UI behavior
- `assets/css/um-song-picker.css` - UI styling
- `assets/data/dueling_piano_top150.json` - starter-song dataset

## Notes before production use

This project was extracted and cleaned from a real-world workflow. If you adapt it for your own use, you should review:

- route namespaces
- webhook configuration
- field labels
- starter-song data
- Ultimate Member field setup

## Roadmap

- additional settings screen
- more reusable configuration
- improved public-facing documentation
- optional admin controls for starter data and webhook behavior

## License

MIT
