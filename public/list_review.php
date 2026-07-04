<?php
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

##### Checking the "Reviewer" priviledge
if( !isset($_SESSION["PHSA_PRIV_REVIEW"]) || $_SESSION["PHSA_PRIV_REVIEW"] !== "1" )
{
	header("Location:unauthorized.html");

	echo "<script language='javascript'>";
	echo "<!-- \n";
	echo "window.location='unauthorized.html';";
	echo "// -->";
	echo "</script>";
	die();
}
#######################################

### $_GET handling
if( !isset($_GET["start"]) || !is_numeric($_GET["start"]) || $_GET["start"] < 0 )
	die("Invalid page variables...");
$start = $_GET["start"];
$page_size = 250;
$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

?>
<html>
<head>
<title>COMET - List of Terms for Reviewer Approval</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css?v=1">
</head>

<body style='font-family:Arial; padding:20px; background-color:#e5f5ff'>

<div id="review_edit_main_div" class="overlay">
  <a href="javascript:void(0)" class="closebtn" onclick="close_review_edit()">&times;</a>
  <div id="review_edit_content_div" class="overlay-content">
  </div>
</div>



<div width='100%' align='center'>
<a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a><br/>
<h1>Review and Approve Changed Maps</h1>
<?php

$disp_columns = array();
$disp_columns[] = "#";
$disp_columns[] = "MR&nbsp;Sheet";
$disp_columns[] = "Source&nbsp;Name";

echo "<table border='1' cellspacing='0' cellpadding='3' style='font-size:11pt;' id='review_list_table'>";
echo "<tr style='color:white; background-color:#202080;'>";
for( $i = 0 ; $i < count($disp_columns) ; $i++ )
	echo "<td>" . $disp_columns[$i] . "</td>";
	
echo "</tr>";

$sql = "select DISTINCT sh.name, sr.description, d.id, d.exclude
		from phsa_mr_sheets sh, phsa_mr_data d, phsa_mr_data_src_cd_desc sr, phsa_all_maps_hx hx
		where 	
			sh.id = d.sheet_id and
			d.id = hx.src_data_id and
			sr.data_id = d.id and
			sr.spot = 1 and 
			hx.approved_by is null and
			sh.id != 13 -- RETIRED Problem
		order by sh.id, hx.source_code_description_1
		limit $start, $page_size";
$rs = $pdo->query($sql);
//echo $sql;

for( $row = $start + 1 ; ($ft = $rs->fetch(PDO::FETCH_ASSOC)) ; $row++ )
{
	$data_id = $ft["id"];
	$td_bg = ($row%2 == 0 ? "#f9f9f9" : "d8d8d8");
	
	if( ($row - ($start + 1)) >= $page_size )
		break;
	
	echo "<tr id='tr_$data_id'>";
	echo "<td>$row</td>";
	echo "<td>" . $ft["name"] . "</td>";
	echo "<td><a href='javascript:void(0)' class='clickable' onclick='open_review_edit($data_id)'>" . $ft["description"] . "</a></td>";
/*	
	echo "<td bgcolor='white' align='center'>";
	if( $ft["exclude"] == "Out of Scope - Exclude" )
		echo "<img src = 'x.png' width='20' border='0' />";
	elseif( $ft["exclude"] == "Question - Pending" )
		echo "<img src = 'question.png' width='22' border='0' />";
	elseif( $ft["exclude"] == "SDO Submission - Send" )
		echo "<img src = 'send.png' width='24' border='0' />";
	elseif( $ft["exclude"] == "SDO Submitted - Pending" )
		echo "<img src = 'submit.png' width='22' border='0' />";
	else
		echo "<img src = 'check.png' width='22' border='0' />";
	echo "</td>";
*/	
	echo "</tr>";
}
?>
</table>

<table border='0' width='95%'>
	<tr style='height:95px; vertical-align:bottom; background-color:#e5f5ff'>
		<td style='width:50%;' align='left'>
			<?php
			if( $start > 0 )
				echo "<a href='list_review.php?start=" . ($start-$page_size) . "'><input type='button' value='  <-Prev Page  '/></a>";
			?>
		</td>
		<td style='width:50%;' align='right'>
			<?php
			if( ($row - ($start + 1)) >= $page_size )
				echo "<a href='list_review.php?start=" . ($start+$page_size) . "'><input type='button' value='  Next Page->  '/></a>";
			?>
		</td>
	</tr>
</table>
</div>
<script src="comet_ops.js?v=1"></script>


</body>
</html>

