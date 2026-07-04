<?php
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

###### $_GET handling
if( !isset( $_GET["domain"] ) )
	die("Invalid page variables...");

$domain_id = $_GET["domain"];
$domain_id = str_replace("\\", "", $domain_id);
$domain_id = str_replace("'", "\\'", $domain_id);
$domain_id = str_replace("\"", "\\\"", $domain_id);
$domain_id = strip_tags($domain_id);
$domain_id = substr($domain_id, 0, 30);

if( isset($_GET["source"]) && $_GET["source"] != "all" && $_GET["source"] != "u" && $_GET["source"] != "a" )
	die("Invalid page variables [457546]");

if( isset($_GET["source"]) )
	$source = $_GET["source"];
else
	$source = "all";

###################

$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

$sql = "
		select distinct s.id, s.name
		from phsa_mr_data d, phsa_mr_sheets s
		where 
		s.id = d.sheet_id and
		s.id <> 13 and -- RETIRED - Problem
		IFNULL(d.exclude, '')='' and
		(
			exists (select 1 from omop_concept c, phsa_all_maps m
				where c.concept_id = m.target_concept_id and d.id = m.src_data_id and c.domain_id = '$domain_id')
			OR
			exists (select 1 from omop_concept c2, phsa_mr_data_targets t 
				where c2.concept_id = t.concept_id and d.id = t.data_id and d.map_source = 'Auto' and c2.domain_id = '$domain_id')
		)
		order by s.name 
	";

$rs_sheet = $pdo->query($sql);

$ft_sheets_arr = array();
while( ($ft = $rs_sheet->fetch(PDO::FETCH_ASSOC)) )
	$ft_sheets_arr[] = $ft;

?>
<html>
<head>
<title>COMET - View List and Edit Source Terms - by OMOP Domain</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css">
<style>
.tabs_bar
{
	width:100%;
	overflow:hidden
}
.tabs_bar_item
{
	padding:8px 16px;
	float:left;
	width:auto;
	border-width:1px; 
	border-style: solid;
	border-color:#DCBB89; 
	display:block;
}
.tab_inactive
{
	color:#000000!important;
	background-color:#FFDEAD!important
}
.tab
{
	background-color:#FFDEAD!important;
	display:inline-block;
	padding:8px 16px;
	vertical-align:middle;
	overflow:hidden;
	text-decoration:none;
	color:inherit;
	text-align:center;
	cursor:pointer;
	white-space:nowrap
}
.tab_active
{
	border-width:3px 3px 0px 3px; 
	color:#000000!important;
	background-color:#FFF4E3!important;
	font-weight:bolder;
}
.content_container
{
	padding:0.01em 16px;
}
.content_border
{
	border:1px solid #ccc!important;
}



</style>
</head>

<body style='font-family:Arial; padding:20px; background-color:#FFF4E3'>

<div id="mr_edit_main_div" class="overlay">
  <a href="javascript:void(0)" class="closebtn" onclick="close_mr_edit()">&times;</a>
  <div id="mr_edit_content_div" class="overlay-content">
  </div>
</div>


<div id="FilterSideNav" class="sidenav">
<?php
create_sidenav_form_domain();
?>
</div>

<div width='100%' align='center'>
<form method='GET' action='#'>
<input type='hidden' name='domain' value='<?php echo $domain_id; ?>'/>
<input type='hidden' name='source' value='<?php echo $source; ?>'/>

<div style='width:100%; background-color:#E7BA93;'>
<table width='100%'>
	<tr style='background-color:E7BA93;'>
		<td width='100px'><span style="font-size:22px;cursor:pointer" onclick="openFilterNav()">&#9776; Filter</span></td>
		<td align='center'>
			<table border='0' cellpadding='4'><tr style='background-color:#E7BA93'>
				<td>Search:&nbsp;&nbsp;</td>
				<td><input type='text' size='30' maxlength='50' name='kw'/></td>
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
<a href='index_domain.php'><img src='target.png' width='52' border='0' alt = 'Back to Mapped Data By COMOP Domain' /></a>
<br/><br/>
<div style='font-size:22px; font-weight:bold;'>Mapped Data By OMOP Domain: <?php echo $domain_id;  ?></div>
<br/>
<div id='tabs_container' class="tabs_bar">
<?php
foreach( $ft_sheets_arr as $ft_sheet )
{
	echo "<button id='tab_" . $ft_sheet["id"] . "' class='tabs_bar_item tab tab_inactive tablink' onclick=\"tab_click_manage(" . $ft_sheet["id"] . ")\">" . $ft_sheet["name"] . "</button>";
}
?>
</div>

<div id="loading" class="content_container content_border tab_content_div" style='text-align:center; padding:100px;'>
<img src='loading.gif' alt='Loading...' width='100' />
</div>
<?php
foreach( $ft_sheets_arr as $ft_sheet )
{
	echo "<div id='content_" . $ft_sheet["id"] . "' class='content_container content_border tab_content_div' style='display:none; overflow:scroll;'></div>";
}
?>
<script src="comet_ops.js?v=1"></script>

<script>
function tab_click_manage(sheet_id) 
{
	var i, x, tablinks;

	tablinks = document.getElementsByClassName("tablink");

	for (i = 0; i < tablinks.length; i++) 
	{
		tablinks[i].className = tablinks[i].className.replace(" tab_active", "");
	}
	document.getElementById('tab_' + sheet_id).className += " tab_active";

	x = document.getElementsByClassName("tab_content_div");
	for (i = 0; i < x.length; i++) 
	{
		x[i].style.display = "none";
	}
	document.getElementById('content_' + sheet_id).style.display = "block";
}

function load_sheet(domain, sheet_id, start, source)
{
	$("#content_" + sheet_id).load(encodeURI("create_map_table.php?domain=" + domain + "&sheet=" + sheet_id + "&start=" + start + "&source=" + source)); 
}

<?php
foreach( $ft_sheets_arr as $ft_sheet )
{
	echo "load_sheet('$domain_id', " . $ft_sheet["id"] . ", 0, '$source'); \n";
}
?>

tab_click_manage(<?php echo $ft_sheets_arr[0]['id']; ?>);

</script>

</body>
</html>

