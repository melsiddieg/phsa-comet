# COMET mapper guide

You map Cerner source terms to standard OMOP (CDM v5.4) concepts. This is the
short version of how to do that quickly and correctly.

## Where to work

- **Cerner Areas** → pick a sheet → you get the mapping grid (all terms, with
  filters). Click the ✏️ on a row to open the **term editor**.
- **⚡ Mapping mode** (button on a sheet grid) — the fast, keyboard-driven way
  to clear a sheet's unmapped terms. Use this for volume.

## Mapping mode (keyboard)

It walks the sheet's unmapped terms **highest-usage first** and shows ranked
candidate concepts (precomputed — see auto-map below).

| Key | Action |
|-----|--------|
| `1`–`9` | map the numbered candidate |
| `e` | exclude (out of scope) |
| `q` | question — needs SME clarification |
| `s` | SDO submission — no standard concept exists, escalate |
| `k` | skip (leave for later) |
| `/` | jump to the search box to override the candidates |

Set the **equivalence** dropdown (EQUAL / EQUIVALENT / WIDER / NARROWER /
INEXACT) before mapping when the match isn't exact — it travels with the map.

Ask an admin to run `comet:auto-map <sheet>` (or `--all`) after a vocabulary
load so candidates are precomputed and mapping mode is instant.

## The term editor

- **Find target concept** — searches concept names *and* synonyms,
  accent-insensitively (so `hypertension essentielle` finds the French synonym).
  Filters: domain, vocabulary (defaults to the sheet's allowed set),
  standard-only, valid-only. Click **🔍** on a result to inspect its hierarchy,
  relationships, and synonyms before committing.
- **Guardrails** — you can't save a non-standard or invalid target; COMET
  offers the standard replacement instead. This is intentional (CDM v5.4).
- **Mapped elsewhere** — if the same term is mapped on another row, reuse that
  decision with one click, or **apply it to every identical unmapped term** in
  the sheet.
- **Status** — Included / Question / Excluded / SDO. Add a comment explaining
  non-obvious calls; reviewers read it.

## Statuses — when to use which

- **Included** (default) — a standard concept exists; map it.
- **Question** — you're unsure what the term means; needs a subject-matter
  expert. Internal.
- **Excluded (out of scope)** — should not be mapped at all.
- **SDO Submission** — the term is real and mappable, but no standard concept
  exists. Flag it; it goes on the SDO export for the standards body (OHDSI /
  CIHI / Infoway). Don't force a wrong target.

## Claims

Opening a term claims it so two mappers don't collide. A 👤 appears on the grid
for others. Claims expire after a few hours, so nothing gets stuck.

## When a reviewer returns your work

A **↩ Returned to you** strip appears on the home page. Open the term, read the
reviewer's comment, fix the map — re-saving clears the returned flag and sends
it back for approval.
