# NOTE MimeTypes v1.0: delete time, cut string, is-enabled, e2e

Decided with the user, 2026-10-10, after v1.0 was presented. The blocks
stand; the steps named below change.

- **The deletion is a time, not a flag** (Block 1, step 1; Block 2). In
  place of `mimetype_deleting`, `mimetype_delete_requested_at`: unix
  seconds of the latest request, an int; 0, its default, reads as null,
  not deleting. A request while one runs, from `occ`, writes it again.
  The status says since when a deletion runs: "deleting since …, N left".
- **The cut string is never compared for equality** (Block 1, step 2).
  The `.docx` and `.dotx` types share their first 63 characters, both
  ending at `wordprocessingml.`, as `.sldx` and `.ppsx` do. Equality goes
  through `meta_value_int`; the string serves prefix filters and a reader.
  A hash in its place was weighed and dropped: it repeats what the id
  gives and loses both.
- **`occ fcias:mimetype:is-enabled`** (Block 2, step 3), beside the four
  commands:
  - prints `on`, `off` or `unavailable` (switched on, `finfo` missing),
    nothing under `--quiet`, which Symfony silences by itself;
  - exits 0 for `on`, 3 for `off` and `unavailable` (the LSB status code
    `systemctl is-active` gives for not running), 1 for an error, 2 for an
    invalid argument or option;
  - catches the input error in `run()` for that 2: Symfony throws it
    before `execute()`, and `Application::run()` exits 1 for it.
  While a deletion runs the feature is off: `off`, 3.
- **The Cypress specs** (Block 4, step 6):
  - `tests/e2e/mimetypes.cy.js`, which switches the feature on through
    `occ fcias:mimetype:enable` before it and off, its types deleted,
    after it, so every other spec runs with it off;
  - the sidebar: a PDF uploaded as `.jpg` and hashed through `occ` shows
    `application/pdf` beside Nextcloud's `image/jpeg`; a `.txt` shows one
    type;
  - the duplicates page: two copies under different names show the type
    once, on the group header;
  - the admin section: Delete disabled while on, with the hint; Refresh
    asking *Missing* or *All*, then `mime` under *Queued files*; after the
    drain, N detected; switched off, Delete confirmed, deleting with N
    left, then disabled once the job ran; switching on refused while
    deleting;
  - `status.cy.js`: the row reads disabled by default;
  - unchanged: `screenshots.cy.js` and `tests/e2e/store/check-install.sh`,
    the feature being off by default and adding no migration.
