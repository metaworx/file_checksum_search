# AP BadgeContrast v1.0: text on Nextcloud's status colors

## Discussion

- The user, 2026-09-30, with screenshots: the status label in both rules
  tables is hard to read on a light background.

## Analysis

- **Nextcloud's colors:** Nextcloud 33, 34 and 35 define `--color-success`
  and `--color-error` as pale backgrounds:
  - light: `#D8F3DA`, `#FFE7E7`;
  - dark: `#11321A`, `#552121`.

  Each comes with its own text color, `--color-success-text` and
  `--color-error-text`:
  - light: `#005416`, `#8A0000`;
  - dark: `#D5F2DC`, `#FFCCCC`.

  (`apps/theming/lib/Themes/DefaultTheme.php`, `DarkTheme.php`.)
- **The defect:** two places in the app put white text on those
  backgrounds, which is nearly invisible in the light theme. Both shipped
  in v0.20.3.
  - `src/settings-admin.css`: `.fcias-compat-pass` and `-fail`, the
    Enabled / Disabled label of `src/rules-vue/RuleRow.vue`, in the admin
    and the personal rules tables alike.
  - `src/sidebar-vue/ChecksumsSidebarTab.vue`: `.fcias-copied-toast`, the
    "Copied!" toast.
- **Already right:** `.db-others` and `.fcias-dup-across` pair
  `--color-warning` with `--color-warning-text`.
- **Out of scope:** `.fcias-btn-danger`, `-delete` and `-toggle` in
  `src/settings-admin.css` have the same pattern but are used nowhere.

## Implementation Plan

1. **[FIX] Status labels are readable on a light background**, one commit:
   - each of the three rules takes the text color Nextcloud pairs with its
     background: `var(--color-success-text)`, `var(--color-error-text)`,
     `var(--color-success-text)`;
   - CHANGELOG Fixed.

   **Verification:** stylelint, ESLint, Vitest and the build; the colors
   checked against the theme values above.

## Proposed commit message

`[FIX] Status labels are readable on a light background`, in full at its
gate.

## Change History

- v1.0 (2026-09-30): first version.
