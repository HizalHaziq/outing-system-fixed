# Theme verification

Compared the original application at `ea54541` with the Laravel application using the same isolated demo database. Chrome 156 / Playwright captured student login, student dashboard and guard gate lookup at **1440×1000** and **390×844**. The browser device timezone was intentionally UTC to expose clock errors. No new dashboard template was added.

All **315** original files under `assets/` and `images/` were copied byte-for-byte into `public/`. Their SHA-256 hashes are recorded in [asset-manifest.json](visuals/asset-manifest.json). Only `laravel-fixes.css` is added for validation and the identity-confirmation control. Fonts and QR libraries retain their original external dependencies.

| Page / size | Original | Laravel |
|---|---|---|
| Student login, desktop | [Before](visuals/before-login-1440.png) | [After](visuals/after-login-1440.png) |
| Student login, mobile | [Before](visuals/before-login-390.png) | [After](visuals/after-login-390.png) |
| Student dashboard, desktop | [Before](visuals/before-student-1440.png) | [After](visuals/after-student-1440.png) |
| Student dashboard, mobile | [Before](visuals/before-student-390.png) | [After](visuals/after-student-390.png) |
| Gate lookup, desktop | [Before](visuals/before-gate-1440.png) | [After](visuals/after-gate-1440.png) |
| Gate lookup, mobile | [Before](visuals/before-gate-390.png) | [After](visuals/after-gate-390.png) |

The dashboard's measured bounding boxes match exactly:

| Size | Header | Sidebar | Main |
|---|---|---|---|
| Desktop | x=0, y=0, 1440×72 | x=0, y=72, 260×928 | x=288, y=100, 1124×633.75 |
| Mobile | x=0, y=0, 390×60 | x=−260, y=60, 260×784 (closed) | x=8, y=76, 374×994.25 |

[Recorded measurements](visuals/layout-measurements.json) report no horizontal overflow or JavaScript errors for the compared dashboard/gate pages. Screenshots were visually inspected, including the mobile gate before/after pair. Colours, logo, font, sidebar dimensions, table/form styles and responsive structure remain recognizable and close to the original. This was a representative comparison, not a pixel-perfect certification of every populated state.

Necessary visible differences: gate submission includes a physical identity-confirmation checkbox, increasing its height; clocks show Kuala Lumpur time even on UTC devices; validation errors use the existing alert styling; logout bookmarks open a confirmation; and the manual-disabled gate exposes a restricted inactive-return recovery lookup. Temporary-password instructions explain secure handover. The original standalone password-change structure is retained. Dynamic particle positions and clock digits naturally differ between captures.

`tests/browser/visual.cjs` reproduces the comparison with separate original/Laravel servers and equivalent demo data. Set `LEGACY_PORT`, `APP_PORT`, `CHROME_BIN` if needed; screenshots go to ignored `test-results/visuals/`. The committed original captures provide review evidence when an original server is unavailable.
