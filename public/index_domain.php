<?php
require_once("db.php");
require_once("common.php");
my_session_start();
verify_session();

$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

?>
<html>
<head>
<link rel="stylesheet" href="styles.css?v=1">
<link rel="icon" href="comet.png">
<title>COMET - Centralized Online Mapping and Export Tool</title>
</head>

<body style='font-family:Arial; background-color:#FFFAF2;'>

<div style="font-size:22px;cursor:pointer; padding:20px 50px;"><a href='index.php'><img src='home.png' width='50' height='50' border='0' /><a></div>

<table border='0' align='center'>
<tr>
<td align='center' valign='top'>
	<table width='450px;' style='height:100%' border='0' cellspacing='1'>
		<tr bgcolor='#FAB67A'><td align='center' style='font-family:Arial; font-size:27px; padding:8px;'>Mapped Terms By OMOP Domain</td></tr>
		<?php	
		$sql = "
				select distinct sq.domain_id from
				(
					select domain_id as domain_id
					from omop_concept c, phsa_all_maps m
					where c.concept_id = m.target_concept_id
					UNION
					select c2.domain_id as domain_id
					from omop_concept c2, phsa_mr_data d, phsa_mr_data_targets t 
					where c2.concept_id = t.concept_id and d.id = t.data_id and d.map_source = 'Auto'
				) sq
				order by sq.domain_id
				";
		$rs = $pdo->query($sql);
		while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		{
			echo "<tr>";
			echo "	<td class = 'domain'><a href='list_domain.php?domain=" . $ft["domain_id"] . "&start=0' class='domain'>" . $ft["domain_id"] . "</a>";
			echo "</tr>";
		}	
		?>
		</tr>
	</table>
</td>
</tr>
</table>

</body>
</html>