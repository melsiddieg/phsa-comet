<?php
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

### $_GET handling
$expected = array("sheet", "start");
if( !check_expected($expected, $_GET) || !is_numeric($_GET["sheet"]) || !is_numeric($_GET["start"]) || $_GET["start"] < 0 )
	die("Invalid page variables...");
$sheet_id = $_GET["sheet"];
$start = $_GET["start"];

### Filters
$filter_tables_to_join = array();
$filter_where_clause = "";
$filter_url_part = "";

if( isset($_GET["_status"]) )
{
	switch( $_GET["_status"] )
	{
		case "i":
			$filter_where_clause .= " and exclude is null";
			break;
		case "e":
			$filter_where_clause .= " and exclude = 'Out of Scope - Exclude'";
			break;
		case "q":
			$filter_where_clause .= " and exclude = 'Question - Pending'";
			break;
		case "s":
			$filter_where_clause .= " and exclude = 'SDO Submission - Send'";
			break;
		case "p":
			$filter_where_clause .= " and exclude = 'SDO Submitted - Pending'";
			break;
		case "all":
			break;
		default:
			die("Invalid status filter value...");
	}
	$filter_url_part .= "&_status=" . $_GET["_status"];
}

if( isset($_GET["_mapped"]) )
{
	switch( $_GET["_mapped"] )
	{
		case "m":
			$filter_where_clause .= " and (exists (select 1 from phsa_mr_data_targets t where t.data_id = d.id) or exists (select 1 from phsa_all_maps m where m.src_data_id = d.id))";
			break;
		case "a":
			$filter_where_clause .= " and d.map_source = 'Auto' and not exists (select 1 from phsa_all_maps m where m.src_data_id = d.id)";
			break;
		case "n":
			$filter_where_clause .= " and ifnull(d.map_source, '_') <> 'Auto' and not exists (select 1 from phsa_all_maps m where m.src_data_id = d.id)";
			break;
		case "all":
			break;
		default:
			die("Invalid mapped filter value...");
	}
	$filter_url_part .= "&_mapped=" . $_GET["_mapped"];
}

if( isset($_GET["_retired"]) )
{
	switch( $_GET["_retired"] )
	{
		case "r":
			$filter_where_clause .= " and exists (select 1 from phsa_mr_data_targets t, omop_concept c where t.data_id = d.id and c.concept_id = t.concept_id and str_to_date(c.valid_end_date, '%Y%m%d')<= now())";
		case "all":
			break;
		default:
			die("Invalid retired filter value...");
	}
	$filter_url_part .= "&_retired=" . $_GET["_retired"];
}

if( isset($_GET["_vocab"]) )
{
	switch( $_GET["_vocab"] )
	{
		case "all":
			$vocab = "all";
			break;
		default:
			$vocab = substr(htmlspecialchars(strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_GET["_vocab"]))))), 0, 25);
			$filter_where_clause .= " and exists (select 1 from phsa_mr_data_targets t, omop_concept c where t.data_id = d.id and c.concept_id = t.concept_id and c.vocabulary_id = '$vocab')";
	}
	$filter_url_part .= "&_vocab=$vocab";
}

if( isset($_GET["_domain"]) )
{
	switch( $_GET["_domain"] )
	{
		case "all":
			$domain = "all";
			break;
		default:
			$domain = substr(htmlspecialchars(strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_GET["_domain"]))))), 0, 25);
			$filter_where_clause .= " and exists (select 1 from phsa_mr_data_targets t, omop_concept c where t.data_id = d.id and c.concept_id = t.concept_id and c.domain_id = '$domain')";
	}
	$filter_url_part .= "&_domain=$domain";
}

$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

##################
?>
<html>
<head>
<title>COMET - View List and Edit Source Terms</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css">
</head>

<body style='font-family:Arial; padding:20px; background-color:#e5f5ff'>

<div id="mr_edit_main_div" class="overlay">
  <a href="javascript:void(0)" class="closebtn" onclick="close_mr_edit()">&times;</a>
  <div id="mr_edit_content_div" class="overlay-content">
  </div>
</div>


<div id="FilterSideNav" class="sidenav">
<?php
create_sidenav_form();
?>
</div>



<div width='100%' align='center'>
<form method='POST' action='search_sheet.php'>
<input type='hidden' name='sheet_id' value='<?php echo $sheet_id; ?>'/>

<div style='width:100%; background-color:#c5dfe9'>
<table width='100%'>
	<tr style='background-color:c5dfe9;'>
		<td width='100px'><span style="font-size:22px;cursor:pointer" onclick="openFilterNav()">&#9776; Filter</span></td>
		<td align='center'>
			<table border='0' cellpadding='4'><tr style='background-color:#c5dfe9'>
				<td>Search:&nbsp;&nbsp;</td>
				<td><input type='text' size='30' maxlength='50' name='kw' placeholder='(4 letters or more)' /></td>
				<td>&nbsp;&nbsp;</td>
				<td><input type='submit' value=' Search '/></td>
				<td>&nbsp;&nbsp;&nbsp;</td>
				<td>Search in:</td>
				<td>&nbsp;</td>
				<td><input type='radio' name='search_in' value='source' checked />&nbsp;Source descriptions</td>
				<td><input type='radio' name='search_in' value='target' />&nbsp;Target descriptions</td>
				<td><input type='radio' name='search_in' value='both' />&nbsp;Both</td>
			</tr></table>
		</td>
		<td width='100px'>&nbsp;</td>
	</tr>
</table>
</div>
</form>
<a href='index_source.php'><img src='source.png' width='40' border='0' alt = 'Back to Source Data By Cerner Area' /></a>
<?php

$page_size = 50;


#### Sheets info
$sql = "select * from phsa_mr_sheets where id = $sheet_id";
$rs = $pdo->query($sql);
if( !($ft_sheet = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters...");
echo "<h2>" . $ft_sheet["name"] . "</h2><br/>";
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

$sql = "select * from phsa_mr_data d
		where 	d.sheet_id = $sheet_id 
				$filter_where_clause
		order by d.total_count desc 
		limit $start, $page_size";
$rs_data = $pdo->query($sql);
//echo $sql;

for( $row = $start + 1 ; ($ft_data = $rs_data->fetch(PDO::FETCH_ASSOC)) ; $row++ )
{
	$data_id = $ft_data["id"];
	$td_bg = ($row%2 == 0 ? "#f9f9f9" : "d8d8d8");
	
	if( ($row - ($start + 1)) >= $page_size )
		break;
	
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

<table border='0' width='95%'>
	<tr style='height:95px; vertical-align:bottom; background-color:#e5f5ff'>
		<td style='width:50%;' align='left'>
			<?php
			if( $start > 0 )
				echo "<a href='list_mr.php?sheet=$sheet_id&start=" . ($start-$page_size) . "$filter_url_part'><input type='button' value='  <-Prev Page  '/></a>";
			?>
		</td>
		<td style='width:50%;' align='right'>
			<?php
			if( ($row - ($start + 1)) >= $page_size )
				echo "<a href='list_mr.php?sheet=$sheet_id&start=" . ($start+$page_size) . "$filter_url_part'><input type='button' value='  Next Page->  '/></a>";
			?>
		</td>
	</tr>
</table>
<br/><br/>
<!-- color legend -->
<table border='0' width='80%' cellpadding='10' cellspacing='40'>
	<caption><b>Colour Legend</b></caption>
	<tr>
		<td bgcolor='#f0f0d0' style='border:1px solid #000000;' align='center'>Not Mapped</td>
		<td bgcolor='#c0d0f0' style='border:1px solid #000000;' align='center'>&nbsp;New Map&nbsp;</td>
		<td bgcolor='#f0c0d0' style='border:1px solid #000000;' align='center'>&nbsp;Auto Map&nbsp;</td>
		<td bgcolor='#e0fae0' style='border:1px solid #000000;' align='center'>No Change</td>
		<td bgcolor='#a0e0e0' style='border:1px solid #000000;' align='center'>Updated Map</td>
		<td bgcolor='#FC8080' style='border:1px solid #000000;' align='center'>&nbsp;Excluded&nbsp;</td>
	</tr>
</table>

</div>
<script src="comet_ops.js?v=1"></script>


</body>
</html>

