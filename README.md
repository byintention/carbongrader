# CarbonGrader

A lightweight, **bundler-free** web app that estimates a carbon footprint and
grades it from **A** (low impact) to **F** (very high impact).

## Quick start

```bash
npm start   # php -S localhost:8000 -t src
```

Open `http://localhost:8000/`, enter a URL, and grade it. Copy `.env.example` to
`.env` and set `PAGESPEED_API_KEY` for live PageSpeed results.

```bash
npm test    # 82 offline tests (PHP + JS)
```

## Project structure

```
CarbonGrader/
├── .cursor/rules/   # AI workflow rules (planning, audits, tooling, frontend, changelog)
├── docs/            # planning.md · audits.md · changelog.md
├── src/             # web app source (HTML / CSS / JS, no bundler)
│   ├── index.html
│   ├── css/style.css
│   └── js/{main,score}.js
├── .cursorignore
└── package.json
```

## Conventions

- **Planning** lives in `docs/planning.md` — read it before starting work.
- **No build systems** — use Prepros, not Webpack/Vite/etc.
- **Changelog** in `docs/changelog.md` follows Keep a Changelog + SemVer.

See the rules in `.cursor/rules/` for the full workflow.
