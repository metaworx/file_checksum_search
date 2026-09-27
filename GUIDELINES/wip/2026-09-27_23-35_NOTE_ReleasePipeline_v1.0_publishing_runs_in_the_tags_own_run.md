# NOTE ReleasePipeline v1.0: publishing runs in the tag's own run

Accompanies `2026-09-27_22-31_AP_ReleasePipeline_v1.0_sign_verify_publish_confirm.md`
and the note `2026-09-27_23-00_NOTE_ReleasePipeline_v1.0_decisions_before_block_1.md`.
Where they differ, this note holds.

## Block 2: `publish.yml` is called by the tag's test run

An environment's deployment rule is matched against the run's
`GITHUB_REF`, and a run started by `workflow_run` always carries the
default branch there, never the tag. So a tag rule on `signing` or
`appstore` would refuse every release as long as `publish.yml` starts on
`workflow_run`. Instead, `test.yml` gets a last job that runs only for a
`v*` tag, needs its three test jobs, and calls `publish.yml` as a reusable
workflow; the publishing jobs then run with the tag as their ref.

What follows from it:

- the tag rule works, and the environments can carry
  `v[0-9]*.[0-9]*.[0-9]*`: GitHub's rules are Ruby `File.fnmatch` globs,
  not regular expressions, so a digit after `v` and after each dot is the
  closest a rule gets to a version (it still admits `v1x.2.3`, and it
  matches `-rc` tags, which the dry runs need);
- no publish run starts, and is skipped, on every push to `master`;
- the tag, the version and the release's kind come from `GITHUB_REF`,
  not from the triggering run's `head_branch`;
- the release build no longer lints: the tag's own run has linted it
  before the call;
- a release is re-run by hand with `gh workflow run publish.yml --ref
  <tag>`, which gives that run the tag as its ref too.

## Block 2: the environments' secrets

`signing` holds `APPSTORE_KEY`; `appstore` holds `APPSTORE_TOKEN`;
`APPSTORE_CERT` stays a repository secret, handed to the called workflow
with `secrets: inherit`. Environment secrets reach the called workflow's
jobs that name the environment, without being passed.
