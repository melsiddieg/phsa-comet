#!/usr/bin/env python3
"""
Convert an OHDSI Eunomia CDM dataset's vocabulary tables (comma-separated,
quoted, ISO dates) into Athena download format (tab-separated, header row,
YYYYMMDD dates) so comet:load-vocab can ingest them.

Useful for testing COMET with a small *real* vocabulary subset without an
Athena account: e.g. the public GiBleed dataset
(https://github.com/OHDSI/EunomiaDatasets) carries ~450 real concepts,
synonyms, and a 65k-row hierarchy.

Usage:
  python3 bin/eunomia_to_athena.py <eunomia-cdm-dir> <output-dir>
  # then: docker compose exec app php artisan comet:load-vocab /vocab_data/<output-dir-name>
"""
import csv
import sys
from pathlib import Path

TABLES = {
    "CONCEPT.csv": 10,
    "CONCEPT_SYNONYM.csv": 3,
    "CONCEPT_RELATIONSHIP.csv": 6,
    "VOCABULARY.csv": 5,
    "CONCEPT_ANCESTOR.csv": 4,  # optional in the loader
}

DATE_COLS = {"VALID_START_DATE", "VALID_END_DATE"}


def athena_date(value: str) -> str:
    value = value.strip()
    if len(value) == 10 and value[4] == "-" and value[7] == "-":
        return value.replace("-", "")
    return value  # already YYYYMMDD or empty


def clean(value: str) -> str:
    # Names must not carry the delimiters of the tab-separated output.
    return value.replace("\t", " ").replace("\r", " ").replace("\n", " ")


def convert(src: Path, dst: Path) -> None:
    dst.mkdir(parents=True, exist_ok=True)
    for name, ncols in TABLES.items():
        src_file = src / name
        if not src_file.exists():
            print(f"  {name:28s} (absent — skipped)")
            continue
        with src_file.open(newline="", encoding="utf-8-sig") as fin, \
                (dst / name).open("w", encoding="utf-8") as fout:
            reader = csv.reader(fin)
            header = next(reader)
            date_idx = {i for i, h in enumerate(header) if h.strip().upper() in DATE_COLS}
            fout.write("\t".join(h.strip().lower() for h in header[:ncols]) + "\n")
            rows = 0
            for row in reader:
                row = (row + [""] * ncols)[:ncols]
                out = [
                    athena_date(clean(v)) if i in date_idx else clean(v)
                    for i, v in enumerate(row)
                ]
                fout.write("\t".join(out) + "\n")
                rows += 1
            print(f"  {name:28s} {rows} rows")


if __name__ == "__main__":
    if len(sys.argv) != 3:
        sys.exit(__doc__)
    convert(Path(sys.argv[1]), Path(sys.argv[2]))
    print("Done. Load with: php artisan comet:load-vocab /vocab_data/<dir>")
