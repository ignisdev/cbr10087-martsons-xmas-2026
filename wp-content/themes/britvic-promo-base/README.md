# britvic-promo-base

Parent WordPress theme for all Britvic promotional microsites.

## Requirements

- WordPress 6.x
- PHP 8.1+
- Node 18+ / npm 9+
- Britvic Promotion Manager plugin (activated)
- Advanced Custom Fields PRO (activated)
- Gravity Forms (activated)

## Quick start

```bash
cd wp-content/themes/britvic-promo-base
npm install
npm run dev       # watch mode — rebuilds on save
```

For production:

```bash
npm run prod      # minified, no sourcemaps
```

Output: `dist/dist.css` and `dist/dist.js` (enqueued automatically by the theme).

## New promotion checklist

1. **BPM Admin → Brand Config**
   - Brand Identity: colours, fonts
   - Page Assets: upload images to named slots
   - Page Flow: set active steps and order
   - Form IDs: enter Gravity Forms IDs

2. **WordPress pages**
   - Create a page for each active step
   - Assign the matching page template (e.g. `page-game.php`)
   - Publish each page

3. **BPM Admin → Competitions**
   - Create a competition record
   - Link each page (live, closed, prize, terms)

4. **Build**
   ```bash
   npm run prod
   ```

## Customising per-promotion (child theme)

```
britvic-[brand]-promo/
├── style.css       ← Template: britvic-promo-base
├── functions.php
└── fonts/          ← Custom @font-face files (if needed)
```

No child theme is required if all customisation is handled via WP admin.

## Docs

| Document | Audience |
|---|---|
| `docs/ARCHITECTURE.md` | Developers |
| `docs/DESIGNER-GUIDE.md` | Designers |
| `docs/FIGMA-SPEC.md` | Designers (Figma library setup) |
| `../../docs/STAKEHOLDER-BRIEF.md` | Stakeholders |
