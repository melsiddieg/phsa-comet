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

<body style='font-family:Arial; background-color:#e0f0ff;'>

<div style="font-size:22px;cursor:pointer; padding:20px 50px;"><a href='index.php'><img src='home.png' width='50' height='50' border='0' /></a></div>

<table border='0' align='center'>
<tr>
<td align='center' valign='top'>
	<table width='450px;' style='height:100%' border='0' cellspacing='1'>
		<tr bgcolor='#78c8ff'><td align='center' style='font-family:Arial; font-size:30px; padding:8px;'>Source Data by Cerner Area</td></tr>
		<?php	
		$sql = "select distinct s.id, s.name
				from phsa_mr_sheets s, phsa_mr_data d
				where d.sheet_id = s.id
				";
		$rs = $pdo->query($sql);
		while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		{
			echo "<tr>";
			echo "	<td class = 'source'><a href='list_mr.php?sheet=" . $ft["id"] . "&start=0' class='domain'>" . $ft["name"] . "</a>";
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