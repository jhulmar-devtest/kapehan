# Kapehan ni Amang — POS & Pre-order System

EARIST Cavite Campus coffee shop ordering system: student/faculty pre-ordering,
cashier POS, and an admin dashboard (sales reports, inventory, products).

## Requirements

- PHP **8.0+**
- MySQL / MariaDB
- [Composer](https://getcomposer.org/)
- A local server stack — [Laragon](https://laragon.org/) (what this project
  was built with) or XAMPP both work fine.

## First-time setup

1. **Clone the repo** into your server stack's www folder.
   - Laragon: `C:\laragon\www\kapehan`
   - XAMPP: `C:\xampp\htdocs\kapehan`
   - The folder name matters — see step 4.

2. **Install dependencies:**
   ```
   composer install
   ```
   This creates the `vendor/` folder (PHPMailer). It's gitignored on purpose —
   everyone runs this command themselves instead of committing it.

3. **Create your database:**
   - Open phpMyAdmin (or any MySQL client).
   - Create a database named `kapehan_db`.
   - Import `database/kapehan_db.sql` into it. This already includes every
     column added by the files in `database/migrations/` — you don't need to
     run those separately on a fresh database. They're only there in case
     someone is updating an **existing** database that was set up before a
     given migration was added (see the date in each migration's filename).

4. **Create your local secrets file:**
   ```
   copy config\secrets.example.php config\secrets.php      (Windows)
   cp config/secrets.example.php config/secrets.php         (Mac/Linux)
   ```
   Open `config/secrets.php` and fill in:
   - Your MySQL username/password (Laragon/XAMPP default: `root` / empty password — already set).
   - `APP_BASE_PATH` — must match your folder name. If you cloned into a
     folder called `kapehan`, leave it as `/kapehan/public`. If your folder
     is named differently, change this line to match.
   - Mail credentials — only needed if you're testing OTP emails /
     password reset. Safe to leave as placeholders otherwise; the app logs
     a warning and skips sending instead of crashing.
   - `APP_KEY` — generate your own random value, don't leave the placeholder:
     ```
     php -r "echo bin2hex(random_bytes(32));"
     ```

   **`config/secrets.php` is gitignored — never commit it.** Every group
   member creates their own from the example file. This is intentional:
   your database password and mail credentials should never end up in
   GitHub history, even in a private repo.

5. **Visit the app** at `http://localhost/kapehan/public/` (adjust the path
   to match `APP_BASE_PATH` if you changed it).

## ⚠️ If you're picking this project up from before it had a `secrets.php`

This project used to have a real Gmail app password and a security key
(`APP_KEY`) committed directly in `config/mail.php` and `config/init.php`.
Both have been moved to the gitignored `config/secrets.php`, but the old
values are no longer good — **the app password must be revoked and
regenerated** in Google Account → Security → App passwords, and a fresh
`APP_KEY` should be generated with the command above. Don't carry the old
values forward into your own `secrets.php`.

## What's safe to commit, what isn't

| Committed (tracked by git) | Not committed (gitignored) |
|---|---|
| All PHP source, `database/kapehan_db.sql`, `database/migrations/*.sql` | `config/secrets.php` |
| `composer.json`, `composer.lock` | `vendor/` (run `composer install`) |
| `uploads/products/*` (seed images matching the sample data) | `.claude/settings.local.json` |
| `config/secrets.example.php` (template, no real values) | |

## Database migrations

New schema changes go in `database/migrations/` as a new dated `.sql` file
(e.g. `2026_10_05_add_something.sql`), **and** get mirrored into
`database/kapehan_db.sql` so a fresh clone+import always has the full
schema in one step. If you already have the database set up and pull a
change that includes a new migration file, just run that one file against
your existing database — don't re-import the whole dump (you'd lose your
local data).

## Working together on this repo

- Pull before you start working; push in small, focused commits.
- Don't commit `config/secrets.php`, `vendor/`, or anything under
  `.claude/settings.local.json` — `.gitignore` already keeps these out, but
  double-check with `git status` before committing if you ever see them show
  up unexpectedly (usually means a file was force-added before `.gitignore`
  existed).
- If you add a new PHP dependency, commit the updated `composer.json` **and**
  `composer.lock` so everyone installs the same version.
- If your change adds a new product image upload type, column, or table,
  update `database/kapehan_db.sql` (and add a migration file if the team
  already has existing local databases to carry forward).
