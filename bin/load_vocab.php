<?php
declare(strict_types=1);

/**
 * OMOP vocabulary loader (Athena download -> MySQL).
 *
 * Loads CONCEPT.csv, CONCEPT_SYNONYM.csv, CONCEPT_RELATIONSHIP.csv and
 * VOCABULARY.csv (tab-delimited, as downloaded from https://athena.ohdsi.org)
 * into *_stg staging tables, validates them, then atomically swaps them in
 * with RENAME TABLE so the app never sees a half-loaded vocabulary.
 *
 * Run inside the app container (the directory must be visible in the
 * container, e.g. under /var/www/vocab_data or /var/www/fixtures):
 *   docker compose exec app php /var/www/bin/load_vocab.php /var/www/vocab_data/<unzipped-athena-dir>
 *
 * See docs/vocab-refresh.md for the full refresh procedure.
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

// Only these relationship types are kept - they are what COMET needs to
// resolve replacements for deprecated/non-standard targets.
const KEPT_RELATIONSHIPS = [
    'Maps to',
    'Mapped from',
    'Concept replaced by',
    'Concept poss_eq to',
    'Concept same_as to',
];

const REQUIRED_FILES = ['CONCEPT.csv', 'CONCEPT_SYNONYM.csv', 'CONCEPT_RELATIONSHIP.csv', 'VOCABULARY.csv'];

if ($argc < 2) {
    die("Usage: php load_vocab.php <directory-with-athena-csv-files> [loaded-by]\n");
}

$dir = rtrim($argv[1], '/');
$loadedBy = $argv[2] ?? get_current_user();

foreach (REQUIRED_FILES as $f) {
    if (!is_readable("$dir/$f")) {
        die("Missing or unreadable file: $dir/$f\n");
    }
}

require '/var/www/html/db.php';

$pdo = new PDO(
    "mysql:host={$_MY_SERV};dbname={$_MY_DB};charset=utf8mb4",
    $_MY_USER,
    $_MY_PASS,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_LOCAL_INFILE => true,
    ]
);

echo "Creating staging tables...\n";

$pdo->exec('DROP TABLE IF EXISTS omop_concept_stg, omop_concept_synonym_stg, omop_concept_relationship_stg, omop_vocabulary_stg');

// Column layout mirrors the live tables (002_vocab_tables.sql / seed schema);
// omop_concept_stg is InnoDB even though the seeded table is MyISAM - the
// swap upgrades the engine.
$pdo->exec(
    'CREATE TABLE omop_concept_stg (
        concept_id int UNSIGNED NOT NULL,
        concept_name varchar(255) NOT NULL,
        domain_id varchar(20) NOT NULL,
        vocabulary_id varchar(20) NOT NULL,
        concept_class_id varchar(20) NOT NULL,
        standard_concept char(1) DEFAULT NULL,
        concept_code varchar(50) NOT NULL,
        valid_start_date char(8) NOT NULL,
        valid_end_date char(8) NOT NULL,
        invalid_reason char(1) DEFAULT NULL,
        PRIMARY KEY (concept_id),
        KEY ind_con_code (concept_code),
        KEY ind_voc_id (vocabulary_id),
        FULLTEXT KEY omop_con_fulltext (concept_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE omop_concept_synonym_stg (
        concept_id int UNSIGNED NOT NULL,
        concept_synonym_name varchar(1000) NOT NULL,
        language_concept_id int UNSIGNED NOT NULL,
        KEY ind_syn_concept_id (concept_id),
        FULLTEXT KEY omop_syn_fulltext (concept_synonym_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE omop_concept_relationship_stg (
        concept_id_1 int UNSIGNED NOT NULL,
        concept_id_2 int UNSIGNED NOT NULL,
        relationship_id varchar(20) NOT NULL,
        valid_start_date char(8) NOT NULL,
        valid_end_date char(8) NOT NULL,
        invalid_reason char(1) DEFAULT NULL,
        PRIMARY KEY (concept_id_1, concept_id_2, relationship_id),
        KEY ind_rel_concept_id_1 (concept_id_1)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$pdo->exec(
    'CREATE TABLE omop_vocabulary_stg (
        vocabulary_id varchar(20) NOT NULL,
        vocabulary_name varchar(255) NOT NULL,
        vocabulary_reference varchar(255) DEFAULT NULL,
        vocabulary_version varchar(255) DEFAULT NULL,
        vocabulary_concept_id int UNSIGNED NOT NULL,
        PRIMARY KEY (vocabulary_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

function load_file(PDO $pdo, string $file, string $table, string $columns): int
{
    // Athena files are tab-delimited with a header row; \r handling keeps
    // this tolerant of files that were re-saved with CRLF endings.
    $sql = "LOAD DATA LOCAL INFILE " . $pdo->quote($file) . "
            INTO TABLE $table
            CHARACTER SET utf8mb4
            FIELDS TERMINATED BY '\\t'
            LINES TERMINATED BY '\\n'
            IGNORE 1 LINES
            $columns";
    $rows = $pdo->exec($sql);
    echo sprintf("  %-28s %d rows\n", basename($file) . ':', $rows);
    return (int) $rows;
}

echo "Loading files from $dir ...\n";

$conceptRows = load_file(
    $pdo,
    "$dir/CONCEPT.csv",
    'omop_concept_stg',
    '(concept_id, concept_name, domain_id, vocabulary_id, concept_class_id, @std, concept_code, valid_start_date, valid_end_date, @inv)
     SET standard_concept = NULLIF(TRIM(REPLACE(@std, "\r", "")), ""),
         invalid_reason   = NULLIF(TRIM(REPLACE(@inv, "\r", "")), "")'
);

load_file(
    $pdo,
    "$dir/CONCEPT_SYNONYM.csv",
    'omop_concept_synonym_stg',
    '(concept_id, concept_synonym_name, @lang)
     SET language_concept_id = CAST(TRIM(REPLACE(@lang, "\r", "")) AS UNSIGNED)'
);

load_file(
    $pdo,
    "$dir/CONCEPT_RELATIONSHIP.csv",
    'omop_concept_relationship_stg',
    '(concept_id_1, concept_id_2, relationship_id, valid_start_date, valid_end_date, @inv)
     SET invalid_reason = NULLIF(TRIM(REPLACE(@inv, "\r", "")), "")'
);

load_file(
    $pdo,
    "$dir/VOCABULARY.csv",
    'omop_vocabulary_stg',
    '(vocabulary_id, vocabulary_name, vocabulary_reference, @ver, vocabulary_concept_id)
     SET vocabulary_version = NULLIF(TRIM(REPLACE(@ver, "\r", "")), "")'
);

echo "Pruning relationships to the kept set...\n";
$placeholders = implode(',', array_fill(0, count(KEPT_RELATIONSHIPS), '?'));
$stmt = $pdo->prepare(
    "DELETE FROM omop_concept_relationship_stg
     WHERE relationship_id NOT IN ($placeholders) OR invalid_reason IS NOT NULL"
);
$stmt->execute(KEPT_RELATIONSHIPS);
echo '  removed ' . $stmt->rowCount() . " rows\n";

// Sanity checks before swapping anything in.
if ($conceptRows < 1) {
    die("Aborting: CONCEPT.csv produced no rows.\n");
}
$badDates = (int) $pdo->query(
    "SELECT COUNT(*) FROM omop_concept_stg WHERE valid_start_date NOT REGEXP '^[0-9]{8}$'"
)->fetchColumn();
if ($badDates > 0) {
    die("Aborting: $badDates concept rows have malformed valid_start_date (expected YYYYMMDD).\n");
}

// Athena encodes the overall release version on the 'None' vocabulary row.
$release = $pdo->query(
    "SELECT vocabulary_version FROM omop_vocabulary_stg WHERE vocabulary_id = 'None'"
)->fetchColumn();
if ($release === false || $release === null || $release === '') {
    $release = 'unknown (' . date('Y-m-d') . ')';
}

echo "Swapping staging tables in (release: $release)...\n";

$pdo->exec('DROP TABLE IF EXISTS omop_concept_old, omop_concept_synonym_old, omop_concept_relationship_old, omop_vocabulary_old');
$pdo->exec(
    'RENAME TABLE
        omop_concept              TO omop_concept_old,
        omop_concept_stg          TO omop_concept,
        omop_concept_synonym      TO omop_concept_synonym_old,
        omop_concept_synonym_stg  TO omop_concept_synonym,
        omop_concept_relationship     TO omop_concept_relationship_old,
        omop_concept_relationship_stg TO omop_concept_relationship,
        omop_vocabulary           TO omop_vocabulary_old,
        omop_vocabulary_stg       TO omop_vocabulary'
);
$pdo->exec('DROP TABLE omop_concept_old, omop_concept_synonym_old, omop_concept_relationship_old, omop_vocabulary_old');

$stmt = $pdo->prepare(
    'INSERT INTO comet_vocab_meta (athena_release, loaded_at, loaded_by, concept_count) VALUES (?, NOW(), ?, ?)'
);
$stmt->execute([$release, $loadedBy, $conceptRows]);

echo "Done. Vocabulary release '$release' is live ($conceptRows concepts).\n";
echo "Next: review the impact report (vocab_impact.php) for maps whose targets changed.\n";
