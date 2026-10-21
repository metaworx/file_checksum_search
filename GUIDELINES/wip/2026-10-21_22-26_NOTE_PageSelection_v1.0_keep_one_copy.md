# NOTE PageSelection v1.0: keep one copy

Two decisions of 2026-10-21, after the run, on choosing by a rule.
They change D1-A, and correct the run's NOTE where it says a version is no copy.

- **The defect:**
  a group of earlier versions alone,
  two files' versions that once held the same content,
  was left alone by any pattern, as having no copy to keep:
  the guard counted a version as no copy everywhere.
- **D7-A, what the guard protects:**
  a group's best copies, its files outside the trash,
  or, where it has none, its versions outside the trash.
  A rule may take some of them, never all.
  Beside a file, a version is still no copy: it goes with its file, and expires.
- **D8-A, a rule may empty a group when asked:**
  a **Keep one copy** switch in the selection bar, on as choosing starts.
  Turned off, *Select matching* and *Select versions* take every row they match,
  a content's last copy included, as one holding a password must go everywhere.
  The page's confirmation still says in how many groups no copy stays,
  and the bar's note on groups left alone points to the switch.
- **Rejected:**
  - D8-B, *Min. files* at 1 as the switch:
    it mixes a listing filter with a deletion rule,
    and would list every hashed file as a group of its own.
  - D8-C, no guard at all:
    one pattern could empty groups with nothing before the dialog.
