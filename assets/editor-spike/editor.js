// Spike 1 — GrapesJS canvas / WordPress theme (block theme) parity.
//
// This is the block-theme half of the spike. See editor-classic.js for the
// classic-theme (Twenty Twenty-One) comparison.
//
// Ground truth used throughout this file: a real page was published on a
// running WordPress Playground instance (WP core, Twenty Twenty-Five active,
// PHP 8.3) via the REST API, using the exact markup below. Its front-end
// render was screenshotted for the visual verdict (see
// docs/adr/spike-1-theme-canvas-verdict.md). Every CSS file referenced here
// is a byte-for-byte copy of what that real WordPress instance served —
// fetched via `curl` from the running instance, not hand-authored.

import { extractWpPresetTokens } from './theme-tokens.js';

const grapesjs = window.grapesjs;
const cssVariablesPlugin = window['@silexlabs/grapesjs-css-variables'];

// ---------------------------------------------------------------------------
// 1. Real markup, captured verbatim from the published page's front-end HTML
//    (view-source of http://127.0.0.1:9400/spike-1-hero/ on this session's
//    WP Playground instance — see docs/adr/spike-1-theme-canvas-verdict.md
//    for the exact curl/REST steps used to create it).
// ---------------------------------------------------------------------------
const HERO_MARKUP = `
<div class="wp-block-group alignfull has-base-color has-contrast-background-color has-text-color has-background has-global-padding is-layout-constrained wp-block-group-is-layout-constrained" style="padding-top:var(--wp--preset--spacing--70);padding-bottom:var(--wp--preset--spacing--70)">
<h1 class="wp-block-heading has-text-align-center has-xx-large-font-size">Spike 1: Real Theme Hero</h1>
<p class="has-text-align-center has-medium-font-size wp-block-paragraph">This hero proves GrapesJS canvas / WordPress theme visual parity for WP-Builder Phase 0, Spike 1.</p>
<div class="wp-block-buttons is-content-justification-center is-layout-flex wp-block-buttons-is-layout-flex">
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-accent-4-background-color has-text-color has-background wp-element-button">Get Started</a></div>
</div>
</div>
`.trim();

// ---------------------------------------------------------------------------
// 2. Canvas CSS — the REAL theme cascade, three real sources, in the same
//    order WordPress itself enqueues them (core block styles → block support
//    styles → theme stylesheet → per-request global styles/theme.json
//    tokens last, so preset custom properties are always defined before use).
//
//    Phase 3 production equivalent (see verdict doc for full discussion):
//      canvas: {
//        styles: [
//          includes_url('css/dist/block-library/style.min.css'),
//          includes_url('css/dist/block-library/theme.min.css'),
//          get_stylesheet_directory_uri() + '/style.css',
//          rest_url('wpb/v1/theme-global-styles.css'),  // wraps wp_get_global_stylesheet()
//        ],
//      }
// ---------------------------------------------------------------------------
const CANVAS_STYLES = [
  './theme-fixtures/wp-core-block-library-style.min.css',
  './theme-fixtures/wp-core-block-library-theme.min.css',
  './theme-fixtures/twentytwentyfive-style.css',
  './theme-fixtures/twentytwentyfive-global-styles-inline.css',
];

async function main() {
  // Fetch the same global-styles CSS text we're loading into the canvas, and
  // mine it for --wp--preset--* tokens to seed the Style Manager with. This is
  // the "bridge" builder-analysis.md section 8.2 predicted would be needed —
  // grapesjs-css-variables does not auto-scan loaded stylesheets, it only
  // manages variables explicitly registered via its `presets` option.
  const globalStylesCss = await fetch('./theme-fixtures/twentytwentyfive-global-styles-inline.css').then(r => r.text());
  const tokens = extractWpPresetTokens(globalStylesCss);

  document.getElementById('token-count').textContent =
    `${tokens.length} theme.json tokens loaded into Style Manager ` +
    `(${tokens.filter(t => t.type === 'color').length} colors, ` +
    `${tokens.filter(t => t.type === 'size').length} sizes, ` +
    `${tokens.filter(t => t.type === 'font-family').length} font families)`;

  const editor = grapesjs.init({
    container: '#gjs',
    height: '100%',
    fromElement: false,
    storageManager: false,
    canvas: {
      styles: CANVAS_STYLES,
    },
    plugins: [cssVariablesPlugin],
    pluginsOpts: {
      [cssVariablesPlugin]: {
        enableColors: true,
        enableSizes: true,
        enableTypography: true,
        // Real theme.json tokens, parsed from the real generated stylesheet —
        // not hand-typed. See theme-tokens.js.
        presets: tokens,
      },
    },
  });

  editor.setComponents(HERO_MARKUP);

  // IMPORTANT finding: the plugin's `presets` option is only ever applied by
  // its internal `applyPresets()`, which runs on the editor's
  // 'storage:end:load' event (see src/variables.js). That event is part of
  // the Storage Manager lifecycle — it never fires if storageManager is
  // disabled (as here, since this spike has no backend to save to) or if
  // autoload is off. Passing `presets` alone is therefore NOT sufficient to
  // get real theme tokens into the Style Manager; Phase 3 must either (a)
  // enable Storage Manager with an initial load, or (b) do what we do here:
  // seed the editor's own internal `:root` CSS rule + `cssVarOrder` model
  // directly, replicating what applyPresets() would have done.
  const rootStyle = {};
  for (const t of tokens) rootStyle[`--${t.name}`] = t.value;
  editor.Css.setRule(':root', rootStyle);
  editor.getModel().set('cssVarOrder', tokens.map(t => ({ name: t.name, type: t.type })));

  // Also register it as a draggable block, matching the eventual "block
  // library" UX (Phase 3/4) — not required for the spike's core claim, but
  // cheap to demonstrate here.
  editor.BlockManager.add('hero-real-theme', {
    label: 'Hero (theme-styled)',
    content: HERO_MARKUP,
    category: 'Spike 1',
  });

  // Select the button link and expand "Decorations" so the background-color
  // field's variable picker (injected by grapesjs-css-variables) is visible
  // without extra manual clicks — purely for screenshot/demo purposes.
  editor.on('load', () => {
    const link = editor.getWrapper().find('.wp-block-button__link')[0];
    if (!link) return;
    editor.select(link);
    setTimeout(() => {
      const titles = editor.getContainer().querySelectorAll('.gjs-sm-sector .gjs-sm-sector-title');
      for (const el of titles) {
        if (/decorations|typography/i.test(el.textContent)) {
          el.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        }
      }
      // Force a fresh 'component:selected' event once the sector's field DOM
      // exists, so the plugin's property-view hook (which only attaches to
      // already-rendered fields) picks up the now-visible rows. Re-calling
      // select() on an already-selected component is a no-op in GrapesJS, so
      // deselect first to guarantee the event actually refires.
      setTimeout(() => {
        editor.select(null);
        editor.select(link);
      }, 150);
    }, 300);
  });

  window.__spike1Editor = editor; // for manual console poking during verification
}

main().catch(err => {
  console.error('Spike 1 init failed:', err);
  document.getElementById('token-count').textContent = 'ERROR: ' + err.message;
});
