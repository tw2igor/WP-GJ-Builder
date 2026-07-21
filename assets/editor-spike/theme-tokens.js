/**
 * Spike 1 — theme.json token bridge.
 *
 * `@silexlabs/grapesjs-css-variables` (the real npm name behind the plugin the
 * plan/spec call "grapesjs-css-variables") does NOT scan the canvas iframe's
 * loaded stylesheets for custom properties on its own. It only manages
 * variables that live in GrapesJS's *own* internal `:root` CSS rule, seeded
 * via its `presets: [{name, value, type}]` option (see
 * node_modules/@silexlabs/grapesjs-css-variables/src/variables.js
 * `applyPresets()` / `setVariable()`).
 *
 * So the "theme.json tokens show up as swatches" requirement needs one small
 * bridge, exactly as builder-analysis.md section 8.2 anticipated: parse the
 * real generated global-styles CSS text (wp_get_global_stylesheet() output —
 * in this spike, extracted verbatim from the <style id="global-styles-inline-css">
 * WordPress prints in <head>) and turn its `--wp--preset--*` declarations into
 * the plugin's preset format, classified into color / size / font-family.
 *
 * This file has no dependency on the DOM — it's pure text parsing — so it can
 * run both in a browser (spike) and, later, in a small PHP-backed build step
 * or REST response for Phase 3.
 */

/**
 * @param {string} cssText raw CSS containing a `:root{ --wp--preset--...: ...; }` block
 * @returns {{name: string, value: string, type: 'color'|'size'|'font-family'}[]}
 */
export function extractWpPresetTokens(cssText) {
  const presets = [];
  const seen = new Set();

  // Matches `--wp--preset--<category>--<slug>: <value>` inside any :root rule,
  // stopping at `;` or `}` (values can contain commas/parens, e.g. gradients).
  const re = /(--wp--preset--([a-z-]+?)--[a-z0-9-]+)\s*:\s*([^;}]+)[;}]/gi;
  let match;
  while ((match = re.exec(cssText)) !== null) {
    const fullName = match[1]; // e.g. --wp--preset--color--accent-4
    const category = match[2]; // e.g. color, font-size, font-family, spacing, gradient, aspect-ratio
    const value = match[3].trim();
    const name = fullName.slice(2); // strip leading "--" — plugin re-adds it

    if (seen.has(name)) continue;

    const type = classify(category);
    if (!type) continue; // skip gradient/aspect-ratio — not modelled by this plugin version

    seen.add(name);
    presets.push({ name, value, type });
  }

  return presets;
}

function classify(category) {
  if (category === 'color') return 'color';
  if (category === 'font-family') return 'font-family';
  if (category === 'font-size' || category === 'spacing') return 'size';
  return null; // gradient, aspect-ratio, etc. — no matching Style Manager section in this plugin version
}
