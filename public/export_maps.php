<?php
require_once("db.php");
require_once("common.php");

my_session_start();

verify_session();


$delimiter = ",";

header('Content-Type: application/csv');
header('Content-Disposition: attachment; filename="SourceToConceptMap.csv";');

$f = fopen('php://output', 'w');

$header = array
		("source_vocabulary_id", "source_code", "source_code_description", 
		 "source_vocabulary_id_2", "source_code_2", "source_code_description_2", 
		 "source_vocabulary_id_3", "source_code_3", "source_code_description_3", 
		 "target_concept_id", "target_concept_name", "target_vocabulary_id");

$line = "\"" . implode("\",\"", $header) . "\"" . chr(13) . chr(10);

fwrite($f, "$line");


$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

$sql = "select 
			p.source_vocabulary_id_1 as source_vocabulary_id_1, p.source_code_1 as source_code_1, p.source_code_description_1 as source_code_description_1, 
			p.source_vocabulary_id_2 as source_vocabulary_id_2, p.source_code_2 as source_code_2, p.source_code_description_2 as source_code_description_2, 
			p.source_vocabulary_id_3 as source_vocabulary_id_3, p.source_code_3 as source_code_3, p.source_code_description_3 as source_code_description_3, 
			p.target_concept_id, p.target_concept_name, p.target_vocabulary_id
		from 
			phsa_all_maps p
		where 
			ifnull(p.source_vocabulary_id_4, '') = ''
		";
$rs = $pdo->query($sql);
while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
{
	$ft["source_code_description_1"] = str_replace("\"", "\"\"", $ft["source_code_description_1"]);
	if( !is_null($ft["source_code_description_2"]) && $ft["source_code_description_2"] != "" )
		$ft["source_code_description_2"] = str_replace("\"", "\"\"", $ft["source_code_description_2"]);
	if( !is_null($ft["source_code_description_3"]) && $ft["source_code_description_3"] != "" )
		$ft["source_code_description_3"] = str_replace("\"", "\"\"", $ft["source_code_description_3"]);

	$ft["source_code_1"] = str_replace("\"", "\"\"", $ft["source_code_1"]);
	if( !is_null($ft["source_code_2"]) && $ft["source_code_2"] != "" )
		$ft["source_code_2"] = str_replace("\"", "\"\"", $ft["source_code_2"]);
	if( !is_null($ft["source_code_3"]) && $ft["source_code_3"] != "" )
		$ft["source_code_3"] = str_replace("\"", "\"\"", $ft["source_code_3"]);

	$ft["target_concept_name"] = str_replace("\"", "\"\"", $ft["target_concept_name"]);

	$line = "\"" . implode("\",\"", $ft) . "\"" . chr(13) . chr(10);

	fwrite($f, "$line");

}
fclose($f);

?>