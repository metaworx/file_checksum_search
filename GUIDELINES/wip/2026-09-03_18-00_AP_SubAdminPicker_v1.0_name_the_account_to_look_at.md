# AP SubAdminPicker v1.0: name the account to look at

> **Status: proposal, 2026-09-03.** The UI half of SudoMode ruling 4 (the
> sub-admin case), deferred at SudoMode's close: the authorisation rule is
> built and tested, the control that lets a leader name a member is not.
> Design work — re-confirm the open decisions before executing.

## Where it stands

`SudoScope::resolve($uid, $target)` already answers the whole question:

- a **sudoer** (member of `admin`, or named by `instance_view`) may look at
  any `$target`, or at every account when `$target` is null;
- a **sub-admin** may look only at a `$target` that is a member of a group
  they administer (`ISubAdmin::isUserAccessible`), and at nobody when
  `$target` is null — they have no "all accounts" view.

The cross-account routes take `?user=<uid>` and pass it straight to
`resolve()`. So the backend already supports "look at this named account."

What is missing is the **control**. The Duplicates page's *Show all users*
switch sends no target, so it resolves to *every account* — which a sudoer
may have and a sub-admin may not. A sub-admin flipping it is refused, and
there is no way in the UI for them to name one of their accessible members
instead. The feature the backend supports is unreachable for exactly the
role it was built for.

## What this adds

A **picker** on the Duplicates page: choose whose duplicates to browse.

- For a **sub-admin**, the picker lists the members of the groups they
  administer; there is no *all accounts* option, because the scope rule
  gives them none. Picking a member sends `?user=<uid>`.
- For a **sudoer**, the picker offers *All accounts* (the current switch's
  behaviour, `?user` omitted) and, optionally, any single named account —
  so an administrator can narrow to one person without wading through
  everyone's duplicates.
- For an account that is neither, the picker does not appear — as the
  switch does not today.

The password confirmation is unchanged: naming an account still costs a
confirmation through the same `SudoConfirmation` flow, once per window.

## Blocks

1. **[TASK] An endpoint for "whose files may I browse".** A new read route
   returns the accounts the caller may look at and whether an *all* option
   is offered: a sudoer gets `{ all: true, users: [] }` (the picker need not
   enumerate the instance — *all* plus a typeahead is enough, see open
   decision 1); a sub-admin gets `{ all: false, users: [<uid+display of each
   accessible member>] }`, resolved through
   `ISubAdmin::getSubAdminsGroups()` → the groups' members, deduplicated;
   anyone else gets 403, the way the sudo routes already refuse them. Unit
   tests for each role.
2. **[TASK] The picker on the Duplicates page.** Replace *Show all users*
   with an `NcSelect`: *All accounts* (sudoer only) plus the offered
   accounts. Selecting one confirms the password (as the switch does) and
   loads that scope through the cross-account route with `?user=`; clearing
   it returns to one's own files. The red *instance-wide* affordance from
   AP DuplicatesControls carries over to "an account that is not yours is
   being shown," named. Vitest for the select's states; the e2e grows a
   sub-admin case (an account made a sub-admin of a group, browsing a
   member) alongside the existing sudoer one.
3. **Docs and the full suite.** User guide (the picker, who sees which
   options), FAQ, README; `docs/api-v1.md` for the new route; full e2e.

## Open decisions

1. **How a sudoer picks one of many.** *All accounts* plus a server-side
   typeahead (query as you type, backed by the user manager's search) —
   recommended, since enumerating every account into a select does not
   scale. Alternative: enumerate for small instances, typeahead above a
   threshold. Confirm before block 1, since it shapes the endpoint.
2. **Scope beyond Duplicates.** SudoMode scoped the sidebar and the search
   provider too, but those are per-file and per-hash, not "browse an
   account." Recommend the picker stays on Duplicates; the sidebar/search
   keep answering for the file/hash in front of you. Confirm before block 2.
3. **Sub-admin of a group with many members.** Same typeahead question at a
   smaller scale; decision 1's answer likely covers it.

## Gate

Per block: PHP suite; for block 2, `npm run lint` + `npx vitest run` +
`npm run build` + the `duplicates` e2e; full e2e at the end.

## Change History

- v1.0 (2026-09-03): proposal.
