# MTG Collection Tracker

A PHP/MySQL Magic: The Gathering collection and deck tracker built as a school project.

## Overview

This application lets users:

- register and log in securely
- search cards via Scryfall data
- add cards to a personal collection with condition, language, finish, notes, quantity, and purchase price
- create, edit, and manage multiple decks
- batch-add cards to the collection
- maintain a wishlist

## Requirements

- PHP 8+ with PDO MySQL support
- MySQL or MariaDB
- A web server or PHP built-in server

## Setup

1. Create the database and tables:

   - Import `schema.sql` into MySQL.
   - The schema creates `mtg`, `users`, `cards`, `user_collection`, `decks`, `deck_cards`, and `wishlist`.

2. Update database credentials in `config.php`:

   - `DB_HOST`
   - `DB_PORT`
   - `DB_NAME`
   - `DB_USER`
   - `DB_PASS`

3. Start the application:

   - Run `php -S localhost:8000` from the project root, or use `run.bat` on Windows.
   - Open `http://localhost:8000/index.php` in your browser.

## Authentication

- Passwords are stored securely using `password_hash()`.
- CSRF protection is implemented via `csrf_token()` and `csrf_check()`.
- Session cookies use secure defaults where possible.

## Main pages

- `index.php` — home page with login/register if unauthenticated
- `cards.php` — browse card catalog and search
- `collection.php` — view and manage the signed-in user's collection
- `batch_add.php` — add many collection items at once
- `decks.php` — deck list and navigation
- `deck.php` — single deck detail and card management
- `import_deck.php` — import decks

## Action endpoints and config

- `collection_config/add_to_collection.php` — add a card to the user collection
- `collection_config/update_collection_item.php` — update collection item details
- `collection_config/export_collection.php` — export collection data
- `deck_config/create_deck.php` — create a new deck
- `deck_config/delete_deck.php` — delete a deck
- `deck_config/add_to_deck.php` — add a card to a deck
- `deck_config/update_deck_card.php` — update cards in a deck

## Supporting files and directories

- `config.php` — database connection setup and session initialization
- `schema.sql` — database schema and table definitions
- `auth/` — authentication helper functions and config
- `users/` — login, logout, and registration actions
- `css/` — stylesheet files for pages
- `js/` — client-side JavaScript for deck behavior
- `partials/` — shared UI fragments (`header.php`, `footer.php`)

## Notes

- The code uses PDO with strict error handling.
- The database schema includes referential integrity and validation checks such as unique keys and quantity constraints.
- The app is intended as a prototype/school project and is not production hardened.

## Running locally

From the project root:

```bash
php -S localhost:8000
```

Then visit:

- `http://localhost:8000/index.php`

## Troubleshooting

- If the page fails to connect to MySQL, verify `config.php` credentials and port.
- If sessions do not persist, make sure PHP session support is enabled and the browser accepts cookies.
