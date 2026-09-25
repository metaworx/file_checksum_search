# GitLab CI, parked

`gitlab-ci.yml` here is the pipeline this project ran on GitLab until
2026-09-25: the manifest check, the unit suite, two Cypress runs, and on a
tag the build, the GitLab Release and the store upload. GitLab reads its
pipeline from `.gitlab-ci.yml` at the repository root, so a file in this
directory runs nothing.

It is parked, not deleted, because GitLab's minutes are billed and GitHub
runs the wider matrix for free: testing and publishing happen in
`.github/workflows/`, on the mirror GitLab pushes to. GitLab stays the code
host, the issue tracker and the mirror's source; the releases and packages
it holds up to v0.20.2 stay where they are.

To run it again, move the file back to the root, or point Settings → CI/CD
→ General pipelines → CI/CD configuration file at `.gitlab/gitlab-ci.yml`.
The protected `v*` tags and the protected variables it needs are still
there.
