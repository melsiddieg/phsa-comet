<?php
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

############### $_GET handling
$expected = array("domain", "sheet", "start", "source");
if( !check_expected($expected, $_GET) || !is_numeric($_GET["sheet"]) || !is_numeric($_GET["start"]) || $_GET["start"] < 0 || $_GET["sheet"] < 0  || $_GET["sheet"] > 1000 || $_GET["start"] != floor($_GET["start"]) || $_GET["sheet"] != floor($_GET["sheet"]) )
	die("Invalid page variables [61562]");

if( $_GET["source"] != "all" && $_GET["source"] != "u" && $_GET["source"] != "a" )
	die("Invalid page variables [617131]");

$domain_id = $_GET["domain"];
$domain_id = str_replace("\\", "", $domain_id);
$domain_id = str_replace("'", "\\'", $domain_id);
$domain_id = str_replace("\"", "\\\"", $domain_id);
$domain_id = strip_tags($domain_id);
$domain_id = substr($domain_id, 0, 30);

$sheet_id 	= $_GET["sheet"];
$start 		= $_GET["start"];
$source 	= $_GET["source"];
##############################

$page_size = 50;
$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

#### Sheets info
$sql = "select * from phsa_mr_sheets where id = $sheet_id";
$rs = $pdo->query($sql);
if( !($ft_sheet = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters [6161751]");
################

### Filter 
if( $source == "all" )
{
	$data_sql = "
		select *
		from phsa_mr_data d
		where 
		d.sheet_id = $sheet_id and
		IFNULL(d.exclude, '')='' and
		(
			exists (select 1 from omop_concept c, phsa_all_maps m
				where c.concept_id = m.target_concept_id and d.id = m.src_data_id and c.domain_id = '$domain_id')
			OR
			exists (select 1 from omop_concept c2, phsa_mr_data_targets t 
				where c2.concept_id = t.concept_id and d.id = t.data_id and d.map_source = 'Auto' and c2.domain_id = '$domain_id')
		)
		order by d.total_count desc 
		limit $start, $page_size
		";
}
elseif( $source == "u" )
{
	$data_sql = "
		select *
		from phsa_mr_data d
		where 
			d.sheet_id = $sheet_id and
			IFNULL(d.exclude, '')='' and
			exists (select 1 from omop_concept c, phsa_all_maps m
				where c.concept_id = m.target_concept_id and d.id = m.src_data_id and c.domain_id = '$domain_id')
		order by d.total_count desc 
		limit $start, $page_size
	";
}
elseif( $source == "a" )
{
	$data_sql = "
		select *
		from phsa_mr_data d
		where 
		d.sheet_id = $sheet_id and
		IFNULL(d.exclude, '')='' and
		not exists (select 1 from phsa_all_maps m  where d.id = m.src_data_id)
		and
		exists (select 1 from omop_concept c2, phsa_mr_data_targets t 
			where c2.concept_id = t.concept_id and d.id = t.data_id and d.map_source = 'Auto' and c2.domain_id = '$domain_id')
		order by d.total_count desc 
		limit $start, $page_size
	";
}
else
{
	die("Invalid parameters [686663]");
}


##################
$rs_data = $pdo->query($data_sql);

#### Sheets info
$sql = "select * from phsa_mr_sheets where id = $sheet_id";
$rs = $pdo->query($sql);
if( !($ft_sheet = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters...");
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

echo "<br/><br/>";
echo "<table border='1' cellspacing='0' cellpadding='3' style='font-size:11pt;' id='mr_list_table'>";
echo "<tr style='color:white; background-color:#643002;'>";
echo "<td bgcolor='white'><img src = 'hand.png' width='26' border='0' /></td>";
for( $i = 0 ; $i < count($disp_columns) ; $i++ )
	echo "<td>" . $disp_columns[$i] . "</td>";
	
echo "<td style='color:#ffa0a0'>Map Target Code</td>";
echo "<td style='color:#ffa0a0'>Map Target Name</td>";
echo "<td style='color:#ffa0a0'>Map Target Domain</td>";
echo "<td style='color:#ffa0a0'>Map Target Vocabulary</td>";
echo "</tr>";


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
	<tr style='height:65px; vertical-align:bottom;'>
		<td style='width:50%;' align='left'>
			<?php
			if( $start > 0 )
				echo "<button onclick=\"load_sheet('$domain_id', $sheet_id, " . ($start-$page_size) . ", '$source');\">&nbsp;&nbsp;&lt;-Prev Page&nbsp;&nbsp;</button>";
			?>
		</td>
		<td style='width:50%;' align='right'>
			<?php
			if( ($row - ($start + 1)) >= $page_size )
				echo "<button onclick=\"load_sheet('$domain_id', $sheet_id, " . ($start+$page_size) . ", '$source');\">&nbsp;&nbsp;Next Page-&gt;&nbsp;&nbsp;</button>";
			?>
		</td>
	</tr>
</table>
<br/><br/><br/>


