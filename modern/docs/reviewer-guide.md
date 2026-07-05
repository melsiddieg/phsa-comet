# COMET reviewer guide

Reviewers approve mapping changes, keep the maps standard-valid across
vocabulary refreshes, and watch team throughput.

## Review queue (`Review`)

Every map add/update/delete and status change lands here as a **pending
change** (until approved). For each term you can:

- **Inspect** — see the before/after (last approved snapshot vs current maps)
  and the pending audit rows (who changed what, when).
- **Approve** — accepts the change and snapshots the term's current targets as
  the new baseline.
- **↩ Request changes** — returns the term to its mapper with a **required
  comment**. It leaves your queue, and the mapper sees a "Returned to you"
  strip on their home; when they re-save, it comes back for approval.
- **Bulk approve** — tick several rows and approve them together.

Filter by **sheet** and **age** to focus (e.g. everything older than 7 days).

## Vocabulary Impact Report (`Impact`)

Run this after every vocabulary refresh (`comet:load-vocab`). It lists maps
whose target became **deprecated**, **non-standard**, or **missing** in the new
release, with the replacement the vocabulary suggests. Per row: one-click
**remap** to the replacement (audited) or **send to Question**. Clearing this
report keeps every map pointing at a standard, valid concept — the OHDSI
requirement.

## Team dashboard (`Team`)

- Per-mapper throughput (map Add/Update) over 7 and 30 days.
- Review backlog and its aging (how much is older than 7/30 days).
- Pipeline counts: returned-to-mapper, Questions, SDO to-send / submitted,
  and claims in flight.

## Exports & releases

- **STCM** — the OMOP `source_to_concept_map` for ETL.
- **Usagi** — richer interchange CSV (adds equivalence + target domain).
- **SDO Submissions** — the queue of terms with no standard target, formatted
  to send to the standards body.
- **Exclusions** — out-of-scope terms.
- **Map Releases** (admin) — freeze a named, vocabulary-tagged snapshot of all
  current maps for a reproducible ETL hand-off; export any release as STCM.

## The SDO loop

When a mapper flags **SDO Submission**, export the SDO Submissions CSV and send
it to the relevant body (OHDSI vocabulary WG for international gaps; CIHI /
Infoway for Canadian content). Mark those terms **Submitted** while you wait;
when the new concept ships in a vocabulary release, the impact report / search
surfaces it for normal mapping.
