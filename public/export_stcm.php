<?php
/**
 * Export maps as a valid OMOP SOURCE_TO_CONCEPT_MAP (STCM) CSV.
 *
 * Unlike export_maps.php (which flattens up to 3 source codes per row and
 * drops maps with 4+), this emits one STCM row per map using spot-1 as the
 * source_code, with the columns an OMOP ETL expects. Target validity dates
 * come from the live omop_concept row.
 *
 * Optional ?release=<id> exports a frozen release from comet_map_release_maps
 * instead of the live maps.
 */
require_once("db.php");
require_once("common.php");
my_session_start();
verify_session();

$pdo = new PDO(
	'mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB . ';charset=utf8mb4',
	$_MY_USER,
	$_MY_PASS,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$release_id = (isset($_GET["release"]) && is_numeric($_GET["release"])) ? (int) $_GET["release"] : 0;

$filename = $release_id
	? "SourceToConceptMap_release_{$release_id}.csv"
	: "SourceToConceptMap_" . date("Ymd") . ".csv";

header('Content-Type: application/csv');
header('Content-Disposition: attachment; filename="' . $filename . '";');

$out = fopen('php://output', 'w');
fputcsv($out, [
	"source_code", "source_concept_id", "source_vocabulary_id", "source_code_description",
	"target_concept_id", "target_vocabulary_id", "valid_start_date", "valid_end_date", "invalid_reason",
]);

if( $release_id )
{
	// Frozen release: dates/invalid_reason resolved from the current vocabulary.
	$stmt = $pdo->prepare(
		"select r.source_code, r.source_vocabulary_id, r.source_code_description,
				r.target_concept_id, r.target_vocabulary_id,
				c.valid_start_date, c.valid_end_date, c.invalid_reason
		 from comet_map_release_maps r
		 left join omop_concept c on c.concept_id = r.target_concept_id
		 where r.release_id = ?
		 order by r.id"
	);
	$stmt->execute([$release_id]);
}
else
{
	$stmt = $pdo->query(
		"select m.source_code_1 as source_code, m.source_vocabulary_id_1 as source_vocabulary_id,
				m.source_code_description_1 as source_code_description,
				m.target_concept_id, m.target_vocabulary_id,
				c.valid_start_date, c.valid_end_date, c.invalid_reason
		 from phsa_all_maps m
		 left join omop_concept c on c.concept_id = m.target_concept_id
		 order by m.src_data_id, m.id"
	);
}

while( ($ft = $stmt->fetch(PDO::FETCH_ASSOC)) )
{
	fputcsv($out, [
		$ft["source_code"],
		0, // source_concept_id: 0 per STCM convention for un-mapped source vocabularies
		$ft["source_vocabulary_id"],
		$ft["source_code_description"],
		$ft["target_concept_id"],
		$ft["target_vocabulary_id"],
		$ft["valid_start_date"] ?: "19700101",
		$ft["valid_end_date"] ?: "20991231",
		$ft["invalid_reason"],
	]);
}

fclose($out);
