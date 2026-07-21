# WP-Builder (WP-GJ-Builder)

Tilda-style page builder for WordPress, built on [GrapesJS](https://grapesjs.com/). Pre-built semantic blocks inherit the active theme's real styling instead of shipping their own design system, and published pages render as plain static HTML/CSS on the frontend — GrapesJS itself never touches a page after it's published.

## Features

- **Drag-and-drop page builder** — GrapesJS editor with an Elementor-style left panel (Blocks / Elements / Pages) and right panel (Style / Content / Layers).
- **Theme-aware blocks** — `blocks-library/` ships ready-made sections (hero, pricing, testimonials, FAQ, team, footer, etc.) that pick up the active theme's colors, fonts, and spacing via `var(--wp--preset--*)` tokens instead of hardcoded styles.
- **Free-form Elements** — atomic components (heading, button, image, icon, forms, accordion) plus animated/interactive ones: countdown, counter, image gallery, image slider, testimonial slider, tabs, flip card, hotspot, bar/line/pie charts, sortable data table, and a syntax-highlighted code block.
- **Page templates** — a virtual "Blank Canvas" template (no theme header/footer) and an improved "Full-width" template (real theme chrome, unconstrained content width), selectable per page and defaulted for every new page.
- **Dynamic tags** — `{{wpb:tag_id;param=value}}` placeholders resolved server-side at render time (author name, post meta, etc.), batched to a fixed, small number of DB queries regardless of how many tag instances appear on a page.
- **AI page generation** — generates a full page from a short brief (niche/type/tone) via the Timeweb Cloud AI Agent API, using the exact same block-assembly REST endpoint a human editing session uses (no parallel content pipeline).
- **Two-contour security sanitizer** — every save and every publish independently re-run the same whitelist-based HTML/CSS sanitizer, defense-in-depth against XSS in a multi-tenant hosting context.

## Requirements

- WordPress 6.5+
- PHP 8.0+
- Node.js + npm (build tooling only, not required at runtime)

## Development

```bash
npm install
npm run build      # production build -> assets/build/
npm run start       # watch mode
npm run lint:js
npm run lint:css
npm run test
```

```bash
composer install
composer lint       # phpcs --standard=WordPress-Extra includes/
composer analyse    # phpstan analyse (level 5)
```

## Architecture

See [`INDEX.md`](INDEX.md) for the full file-by-file architecture map (editor front-end, block manifest contract, dynamic tags, sanitizer, render pipeline). Product spec and design rationale live in `docs/`.

## License

GPL-2.0-or-later.
