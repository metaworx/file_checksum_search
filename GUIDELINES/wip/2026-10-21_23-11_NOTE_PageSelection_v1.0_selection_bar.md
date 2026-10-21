# NOTE PageSelection v1.0: selection bar

Four decisions of 2026-10-21 on the bar for choosing rows across the page,
and its layout.

- **Layout:**
  - *By path* and *Versions*, each with *Select* and *Deselect*,
    the versions set apart by a bar of their own:
    the path plays no part in them.
  - Between them and the actions, a status line: the rows chosen,
    the groups left alone, and the switch that would choose them.
  - The bottom row: *Keep one copy* with its help,
    *Select duplicates* or *Select all*,
    *Invert selection* and *Deselect all* at the start;
    *Cancel* and *Delete n selected* at the end, as a dialog's buttons are.
    The ends follow the writing direction.
  - *Select* and *Refresh* above it, one size, at the end of the filter row.
- **D9-A, what Keep one copy governs:**
  the page's bulk actions — by path, as versions, *Select duplicates*,
  *Invert selection* — and turning it on,
  which lets go of every group chosen whole.
  A group's own menu and its boxes, choosing by hand, stay unlimited.
- **D10-A:**
  *Invert selection* for the page; with the switch on,
  a group it would empty keeps its choice.
- **D11-C:**
  *Select duplicates* while the switch is on, *Select all* while off.
- **D12-A:**
  *Select duplicates* leaves each group its oldest copy,
  by when it last changed, the first path where two are as old;
  none where a copy that may not be deleted stays anyway.
  In a group of versions alone, the oldest version.
- **Found on the way:**
  the guard let a group that shares some files with another, not all,
  be emptied: the files it spared were taken through the other group.
  It now keeps a spared group's files out wherever they are listed.
