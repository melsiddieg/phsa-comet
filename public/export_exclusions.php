<?php
require_once("db.php");
require_once("common.php");

my_session_start();

verify_session();


$delimiter = ",";

header('Content-Type: application/csv');
header('Content-Disposition: attachment; filename="SourceCodeExclusion.csv";');

$f = fopen('php://output', 'w');

$header = array
		("source_vocabulary_id", "source_code", "source_code_description", "comment");

$line = "\"" . implode("\",\"", $header) . "\"" . chr(13) . chr(10);

fwrite($f, "$line");


$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

$sql = "select 
			source_vocabulary_id_1, source_code_1, source_code_description_1, ''
		from 
			phsa_exclusions
		";
$rs = $pdo->query($sql);
while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
{
	$ft["source_code_description_1"] = str_replace("\"", "\"\"", $ft["source_code_description_1"]);
	$ft["source_code_1"] = str_replace("\"", "\"\"", $ft["source_code_1"]);
	$line = "\"" . implode("\",\"", $ft) . "\"" . chr(13) . chr(10);

	fwrite($f, "$line");

}
fclose($f);

?>