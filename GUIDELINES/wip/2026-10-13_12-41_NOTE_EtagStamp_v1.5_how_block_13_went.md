# NOTE EtagStamp v1.5: How Block 13 went

Where Block 13 went differently from the plan.

- The file's present ETag comes from `FilecacheService::statesOf()`, not
  from a new `FileLocation` field: `FileLocation` is built in four places
  and read everywhere, and the import alone needs the ETag. One query per
  page of 500 files, and only for files whose records carry an ETag.
- The new count is `ImportReport::$skippedChanged`, printed as
  `skipped changed`, beside `skippedOutdated`.
- The integration test writes a one-file backup itself rather than
  exporting the instance; the export's ETag is held by `ExportServiceTest`.
