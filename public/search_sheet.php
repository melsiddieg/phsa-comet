<?php
set_time_limit(0);
require_once("common.php");
require_once("db.php");

if( !isset( $_POST["kw"] ) )
	die("No keyword... Invalid page variables...");

if( !isset( $_POST["sheet_id"] ) || !is_numeric( $_POST["sheet_id"] ) )
	die("Invalid or missing sheet number...");

if( !isset( $_POST["search_in"] ) || ($_POST["search_in"] != "source" && $_POST["search_in"] != "target" && $_POST["search_in"] != "both") )
	die("Invalid variable (3578)...");

$kw = $_POST["kw"];
$kw = str_replace("\\", "", $kw);
$kw = str_replace("'", "\\'", $kw);
$kw = str_replace("\"", "\\\"", $kw);
$kw = strip_tags($kw);

$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

$sheet_id 	= $_POST["sheet_id"];
$search_in	= $_POST["search_in"];

#####################
######## CREATING SQL
if( $search_in == "source" )
{
	$sql = "
		select distinct d.id, d.total_count, d.map_source, d.exclude 
		from 
			phsa_mr_data d, phsa_mr_data_src_cd_desc s 
		where 
			s.data_id = d.id and
			d.sheet_id = $sheet_id and
			match(s.description) against ('$kw')
		order by total_count desc 
		limit 200
		";
}
elseif( $search_in == "target" )
{
	$sql = "
		select distinct d.id, d.total_count, d.map_source, d.exclude 
		from 
			phsa_mr_data d, phsa_mr_data_targets t, omop_concept c
		where 
			t.data_id = d.id and
			c.concept_id = t.concept_id and
			d.sheet_id = $sheet_id and
			match(c.concept_name) against ('$kw')
		order by total_count desc 
		limit 200
		";
}
elseif( $search_in == "both" )
{
	$sql = "
		select distinct d.id, d.total_count, d.map_source, d.exclude 
		from 
			phsa_mr_data d, phsa_mr_data_src_cd_desc s, phsa_mr_data_targets t, omop_concept c
		where 
			s.data_id = d.id and
			t.data_id = d.id and
			c.concept_id = t.concept_id and
			d.sheet_id = $sheet_id and
			(
				(match(c.concept_name) against ('$kw'))
				or
				(match(s.description) against ('$kw'))
			)
		order by total_count desc 
		limit 200
		";
}
else
	die("Error (54789)");
#####################
#####################
$rs_data = $pdo->query($sql);

//echo "<p><pre>$sql</pre></p>";

?>
<html>
<head>
<title>COMET - Search Within Sheet</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css">
</head>
<body style='font-family:Arial; padding:20px; background-color:#f0e5ff'>

<div id="mr_edit_main_div" class="overlay">
  <a href="javascript:void(0)" class="closebtn" onclick="close_mr_edit()">&times;</a>
  <div id="mr_edit_content_div" class="overlay-content">
  </div>
</div>

<div align='center'>
<div align='center' style='width:100%; background-color:#b5cfd9'>Search Results for: <?php echo $kw; ?><br>Searched in: <?php echo $search_in; ?></div>
<div style='height:6px;'></div>
<form method='POST' action='search_sheet.php'>
<input type='hidden' name='sheet_id' value='<?php echo $sheet_id; ?>'/>

<div style='width:100%; background-color:#c5dfe9'>
<table border='0' cellpadding='4'><tr style='background-color:#c5dfe9'>
	<td>Search again:&nbsp;&nbsp;</td>
	<td><input type='text' size='30' maxlength='50' name='kw' value='' /></td>
	<td>&nbsp;&nbsp;</td>
	<td><input type='submit' value=' Search '/></td>
	<td>&nbsp;&nbsp;&nbsp;</td>
	<td>Search in:</td>
	<td>&nbsp;</td>
	<td><input type='radio' name='search_in' value='source' checked />&nbsp;Source descriptions</td>
	<td><input type='radio' name='search_in' value='target' />&nbsp;Target descriptions</td>
	<td><input type='radio' name='search_in' value='both' />&nbsp;Both</td>
</tr></table>
</div>
</form>

<div><a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a></div>

<?php
#### Sheets info
$sql = "select * from phsa_mr_sheets where id = $sheet_id";
$rs = $pdo->query($sql);
if( !($ft_sheet = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters...");
echo "<h2><a style='color:#0000b8;' href='list_mr.php?sheet=$sheet_id&status=all&start=0'>" . $ft_sheet["name"] . "</a></h2><br/>";
################

#### Number of source codes
for( $num_of_srcs = 2 ; $num_of_srcs <= 7 ; $num_of_srcs++ )
{
	if( $num_of_srcs == 7 || $ft_sheet["src_voc_name_$num_of_srcs"] == "" || is_null($ft_sheet["src_voc_name_$num_of_srcs"]) )
	{
		$num_of_srcs--;
		break;
	}
}
################

#### Sheet attributes
$sheet_attr_arr = set_sheet_attr_arr($pdo, $sheet_id);
###

$disp_columns = array();
$disp_columns[] = "Row#";
$disp_columns[] = "Index";

for( $i = 1 ; $i <= $num_of_srcs ; $i++ )
{
	$disp_columns[] = $ft_sheet["src_cd_colname_$i"];
	$disp_columns[] = $ft_sheet["src_desc_colname_$i"];
}
foreach( $sheet_attr_arr as $attr )
{
	$disp_columns[] = $attr["attr_name"];
}
$disp_columns[] = "Target Code";
$disp_columns[] = "Target Name";
$disp_columns[] = "Target Domain";
$disp_columns[] = "Target Vocabulary";
$disp_columns[] = "Map&nbsp;Src";
$disp_columns[] = "Count";

echo "<table border='1' cellspacing='0' cellpadding='3' style='font-size:11pt;' id='mr_list_table'>";
echo "<tr style='color:white; background-color:#202080;'>";
echo "<td bgcolor='white'><img src = 'hand.png' width='26' border='0' /></td>";
for( $i = 0 ; $i < count($disp_columns) ; $i++ )
	echo "<td>" . $disp_columns[$i] . "</td>";
	
echo "<td style='color:#ffa0a0'>Map Target Code</td>";
echo "<td style='color:#ffa0a0'>Map Target Name</td>";
echo "<td style='color:#ffa0a0'>Map Target Domain</td>";
echo "<td style='color:#ffa0a0'>Map Target Vocabulary</td>";
echo "</tr>";


for( $row = 1 ; ($ft_data = $rs_data->fetch(PDO::FETCH_ASSOC)) ; $row++ )
{
	$data_id = $ft_data["id"];
	$td_bg = ($row%2 == 0 ? "#f9f9f9" : "d8d8d8");

	### SOURCE CODES AND DESC
	$sources_arr = set_sources_arr($pdo, $data_id);

	### ATTRIBUTES
	$data_attr_arr = set_data_attr_arr($pdo, $data_id, $sheet_attr_arr);

	### TARGETS
	$targets_arr = set_targets_arr($pdo, $data_id);
	
	### PHSA_MAPS
	$phsa_maps_arr = set_phsa_maps_arr($pdo, $data_id);

	### Creating an array in which targets are matched with phsa_maps as much as possible
	$num_rows = match_up_targets_with_maps($targets_arr, $phsa_maps_arr);
	####

	for( $r = 0, $One2M_index = 1 ; $r < $num_rows ; $r++, $One2M_index++ )
	{
		echo list_mr_create_tr($ft_data, $sources_arr, $targets_arr, $data_attr_arr);
	}
}
?>
</table>
</div>
<script src="comet_ops.js"></script>

</body>
</html>
