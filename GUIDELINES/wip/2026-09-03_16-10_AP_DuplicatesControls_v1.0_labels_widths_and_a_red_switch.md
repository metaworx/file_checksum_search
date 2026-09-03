# AP DuplicatesControls v1.0: labels, widths, and a red switch

> **Status: proposal, 2026-09-03.** From the user's review of the Duplicates
> page after AdminTabs (f1c4bcb). One block.

## Findings

1. **The controls have no visible label.** The algorithm select, *Min*
   and *Limit* carry their label for screen readers only; a reader sees a
   select, a `2` and a `50`. Nothing says what the numbers bound.
2. **Min and Limit span the page.** `.db-control--narrow { width: 110px }`
   never reached the field: `NcTextField` binds the class on its input,
   not its root, so the two number fields fill the row and push every
   other control onto lines of its own.
3. **Nothing says the instance-wide view is on.** *Show all users* looks
   the same on as off. It is the one control on the page whose state
   changes whose files are shown; the page should not let that be missed.

## Rulings folded in

- Every control gets a visible label, a tooltip, and a help button — the
  `HelpPopover` the settings pages already use, next to the label.
- *Min* and *Limit* are narrow; the row flows on one line where there is
  room and wraps where there is not.
- The *Show all users* switch turns red while it is on.

## Block

**[TASK] Labels, widths, and a red switch on the Duplicates page.**
Each field becomes a labelled column: a `<label>` for the input, a
`HelpPopover` beside it, a `title` on the field. The number fields are
sized by their own wrapper, not by a class the component drops on the
input. The switch's wrapper carries `db-sudo-on` while the view is
instance-wide: error background, error text, the toggle in the same red.
User guide: the sentence about the switch says it shows red. e2e: the
labels are found by their `for`, and the switch's wrapper carries the
class after the toggle — the existing spec's toggle test grows two
assertions.

## Gate

`npm run lint`, `npx vitest run`, `npm run build`, e2e `duplicates.cy.js`;
PHP suite untouched (no PHP in this block), run once at the end anyway.

## Open decisions

1. Red (`--color-error`) or yellow (`--color-warning`) for the active
   switch — red unless struck: it marks a state, not an error, but it is
   the state that widens what is shown, and yellow reads as "attention"
   where red reads as "stop and notice". The user named red first.

## Change History

- v1.0 (2026-09-03): proposal.
