# General Rules

**If any questions are raised during a task, ask me before acting.**

**You will be EXTREMELY critic with my ideas and suggestions. You will never be afraid of hurting my feelings, and you will think on every idea or suggestion I give you, pondering if it's the best. Don't start an answer with a compliment.**

**If I end my request with a question, ANSWER THE QUESTION!**

---

# Versioning

- App version lives in `src/version` (plain text, no trailing newline) — read at runtime by `MagratheaPHP::AppVersion()` for the `/version` endpoint.
- Changelog lives in `src/changelog.md` — parsed by the `changelog` endpoint (5 most recent versions).
- `src/swagger.yaml` also has a `version:` field near the top that should be kept in sync with `src/version`.

When bumping the version, update all three.

---

# Framework

- The app runs on **MagratheaPHP 3** (`platypustechnology/magratheaphp`, namespace `Magrathea`) since 3.7.0. Before writing PHP, read `src/vendor/platypustechnology/magratheaphp/skills.MD`; class docs in `src/vendor/platypustechnology/magratheaphp/docs/mds/index.md`.
- The CLI bootstrap is `src/api/_inc.php` (declared in `src/composer.json`). Verify with `cd src && vendor/bin/magrathea check` and `vendor/bin/magrathea models:verify`.
- `Apikey` and `Images` pin `protected $strictTypes = false;`. Removing it changes the API's JSON types (`"1"` → `1`): don't, unless asked.
- Upgrading an instance: `docs/migration.md`.
