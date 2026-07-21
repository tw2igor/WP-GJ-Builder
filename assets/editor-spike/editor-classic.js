// Spike 1 — classic theme comparison (Twenty Twenty-One).
//
// Twenty Twenty-One has no theme.json. Its stylesheet was fetched verbatim
// from https://raw.githubusercontent.com/WordPress/twentytwentyone/trunk/style.css
// (this WP Playground instance didn't have a classic theme installed to
// publish a real page against, unlike the block-theme half of this spike —
// see docs/adr/spike-1-theme-canvas-verdict.md for the exact methodology and
// why this is still an honest test of the *approach*: the CSS is 100% real
// and unmodified, only the "load it in canvas iframe" step is being proven,
// not an end-to-end WP publish/render round-trip).
//
// Point of this half of the spike: prove the EXPECTED gap, not paper over it.
// Twenty Twenty-One does define its own color palette via
// get_theme_support('editor-color-palette') (black/blue/dark-gray/gray/green/
// orange/purple/red/white/yellow) — but that support call is a PHP array,
// rendered into hand-authored `.has-<slug>-color` CSS classes with literal
// hex values, NOT `--wp--preset--*` custom properties. Our theme-tokens.js
// bridge only understands the latter (because grapesjs-css-variables only
// understands CSS custom properties, not baked-in class selectors), so
// running it against this stylesheet is expected to find zero tokens.

import { extractWpPresetTokens } from './theme-tokens.js';

const grapesjs = window.grapesjs;
const cssVariablesPlugin = window['@silexlabs/grapesjs-css-variables'];

const HERO_MARKUP = `
<div class="wp-block-group has-black-background-color has-white-color has-text-color has-background" style="padding:4em 2em;text-align:center;">
<h1>Spike 1: Classic Theme Hero</h1>
<p>Same hero concept, rendered with Twenty Twenty-One's real stylesheet — no theme.json tokens available here.</p>
<div class="wp-block-buttons">
<div class="wp-block-button"><a class="wp-block-button__link">Get Started</a></div>
</div>
</div>
`.trim();

const CANVAS_STYLES = [
  './theme-fixtures/twentytwentyone-style.css',
];

async function main() {
  const themeCss = await fetch('./theme-fixtures/twentytwentyone-style.css').then(r => r.text());
  // Expected to be empty: no --wp--preset--* variables in a classic theme.
  const tokens = extractWpPresetTokens(themeCss);

  document.getElementById('token-count').textContent =
    tokens.length === 0
      ? '0 theme.json tokens found (expected — classic theme has no theme.json)'
      : `${tokens.length} tokens found (unexpected for a classic theme)`;

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
        presets: tokens, // empty for this theme — see comment above
      },
    },
  });

  editor.setComponents(HERO_MARKUP);

  window.__spike1ClassicEditor = editor;
}

main().catch(err => {
  console.error('Spike 1 (classic) init failed:', err);
  document.getElementById('token-count').textContent = 'ERROR: ' + err.message;
});
