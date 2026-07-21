# editor-spike

Throwaway prototypes for Phase 0 risk spikes (see the plan's roadmap and
`docs/adr/spike-1-theme-canvas-verdict.md`). Nothing under this directory is
production code and nothing here is imported by the real plugin build
(`WP-GJ-Builder/assets/editor/` is the real editor source; this directory has
its own `package.json`/`node_modules`, isolated from the plugin root's build).

## Spike 1

**Claim tested:** a GrapesJS canvas can visually inherit a real WordPress
theme's CSS (block theme + classic theme), and the theme's theme.json design
tokens can be surfaced as pickable swatches in GrapesJS's Style Manager via
`grapesjs-css-variables`.

**Verdict:** validated. Full writeup, methodology, screenshots, and the
config snippets Phase 3 should reuse: `docs/adr/spike-1-theme-canvas-verdict.md`.

### Files

- `index.html` / `editor.js` — block-theme demo (Twenty Twenty-Five, real
  theme.json tokens loaded into the Style Manager).
- `index-classic.html` / `editor-classic.js` — classic-theme comparison
  (Twenty Twenty-One, no theme.json — demonstrates the expected token gap).
- `theme-tokens.js` — pure-text parser turning `--wp--preset--*` custom
  properties from a real generated stylesheet into
  `{name, value, type}` presets for `grapesjs-css-variables`.
- `theme-fixtures/` — real CSS, fetched verbatim from a running WordPress
  Playground instance (block theme) and from wordpress.org's theme SVN mirror
  on GitHub (classic theme comparison). Not hand-authored.
- `screenshots/` — canvas renders vs. the real WordPress front-end render of
  the same markup.
- `server.js` — zero-dependency static file server (`npm start`), needed
  because `<script type="module">` imports don't resolve over `file://`.

### Running it

```bash
cd assets/editor-spike
npm install   # already done once; re-run if node_modules is missing
npm start     # serves this directory on http://127.0.0.1:8934/
```

Then open `http://127.0.0.1:8934/index.html` (block theme) or
`http://127.0.0.1:8934/index-classic.html` (classic theme).
