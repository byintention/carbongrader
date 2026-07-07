# CarbonGrader

A lightweight, **bundler-free** web app that estimates a carbon footprint and
grades it from **A** (low impact) to **F** (very high impact).

## Quick start

This project runs directly in the browser — there is no build step.

- **Recommended:** open the folder in [Prepros](https://prepros.io/) and let it
  serve/process `src/` with live reload (see `.cursor/rules/050-tooling.mdc`).
- **Or** serve the static files:

```bash
npm start   # runs `npx serve src`
```

Then open the printed URL and load the app.

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
