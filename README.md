<div align="center">

# ☕ Kapehan ni Amang

**A full POS & pre-ordering system built for a real campus coffee shop.**

Students and faculty order ahead from their phones. Cashiers run the counter
from a POS screen. Admins track sales, inventory, and best-sellers from a
dashboard — all built on one shared PHP/MySQL codebase, no frameworks.

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-MariaDB-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?logo=javascript&logoColor=black)](https://developer.mozilla.org/en-US/docs/Web/JavaScript)
[![Chart.js](https://img.shields.io/badge/Chart.js-Reports-FF6384?logo=chartdotjs&logoColor=white)](https://www.chartjs.org/)

</div>

---

## What this is

Kapehan ni Amang is the ordering system for a coffee shop at EARIST Cavite
Campus. It's built as **one system serving four different roles**, each with
its own view into the same data:

| Role                     | What they do                                                                                                                   |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------ |
| 🎓 **Student / Faculty** | Browse the menu, customize drinks (size, sugar level, add-ons), pre-order ahead of pickup, and pay via GCash or cash on pickup |
| 💳 **Cashier**           | Run the counter POS for walk-ins, claim pre-orders via QR scan, print receipts, manage shifts                                  |
| 🛠️ **Admin**             | Track sales (daily/monthly/quarterly/yearly), see best-sellers, manage inventory & recipes, manage the menu, review feedback   |

<!--
  📸 ADD SCREENSHOTS HERE before publishing — a few of these go a long way:
  - The student/faculty menu page (![Menu](docs/screenshots/menu.png))
  - The product customize modal
  - The cashier POS screen
  - The admin sales report with charts
  - The admin inventory page with the item details popup
-->

## Key features

- **Pre-order + walk-in, one order pipeline.** Students/faculty pre-order
  ahead; cashiers also ring up walk-ins directly — both flow through the
  same order/kitchen status pipeline (pending → preparing → ready → claimed).
- **QR pickup claims.** Each paid pre-order gets a signed (HMAC) QR code;
  scanning it at pickup is what the cashier uses to mark it claimed — no
  unsigned/guessable codes.
- **Smart homepage Spotlight.** A "Today's Picks" section that's part
  admin-curated (pin a product, optionally with an auto-expiry) and part
  automatic (ranks recent best-sellers weighted by rating, rotating daily
  so it's not always the same 1–2 items).
- **Inventory with recipes.** Products are linked to the ingredients they
  consume (`product_ingredients`), with a full restock history (date,
  supplier, quantity) per item — not just a single stock number.
- **Admin sales reporting.** Daily/monthly/quarterly/yearly totals and
  best-sellers, computed from real order data, visualized with Chart.js.
- **Role-based everything.** Students, faculty, cashiers, and admins each
  get their own login and their own view — enforced server-side, not just
  hidden in the UI.

## Tech stack

- **Backend:** PHP 8 (no framework — plain PDO, a small shared routing/
  layout layer, role-based auth)
- **Database:** MySQL / MariaDB
- **Frontend:** Vanilla JS + CSS (custom design system — CSS custom
  properties for theming, no Bootstrap/Tailwind)
- **Charts:** Chart.js
- **Email:** PHPMailer (OTP verification, password reset)
- **Dependency management:** Composer

## A few things I'm proud of under the hood

- **Security fixes during the project:** found and fixed a hardcoded
  security key and a committed email app-password before this went public
  — moved all secrets into a gitignored config file with a documented
  rotation path (see [`docs/SETUP.md`](docs/SETUP.md)).
- **UI audit that found real bugs, not just opinions:** traced several
  "something looks off" reports back to concrete causes — a CSS class used
  across four pages but never actually defined, and a handful of CSS
  custom properties referenced but never declared (so declarations were
  silently dropped by the browser).
- **Multi-add UX:** admin flows for adding add-ons and inventory items used
  to close/reload after every single save — fixed to stay open so adding
  ten items doesn't mean reopening a modal ten times.

## Running it locally

See [`docs/SETUP.md`](docs/SETUP.md) for full setup instructions (database
import, environment config, dependencies).

## Credits

Built as a group capstone project for EARIST Cavite Campus.

> _This README is written for a portfolio audience. If you're adapting it
> for your own portfolio, update this section to describe your specific
> role/contributions on the team rather than presenting the whole project
> as solo work._
