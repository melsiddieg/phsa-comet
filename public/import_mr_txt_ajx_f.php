<?php
//error_reporting(0);
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

##### Checking the "Importer" priviledge
if( !isset($_SESSION["PHSA_PRIV_IMPORT"]) || $_SESSION["PHSA_PRIV_IMPORT"] !== "1" )
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


$_USERNAME = $_SESSION["PHSA_UNAME"];

$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

$mr_sheets_arr = array();
$sql = "select * from phsa_mr_sheets order by name";
$rs = $pdo->query($sql);
while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
{
	$mr_sheets_arr[ $ft["id"] ] = $ft;
}
?>
<html>
<style>
.collapsible
{
	font-size: 11px;
	display: table-cell;
}
.collapsed
{
	font-size: 0px;
	display: none;
}
tr:hover { background-color: #fff0c0; }

</style>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<head>
</head>

<body style='font-family:Arial; padding:20px; font-size:11px;'>
<div width='100%' align='left'>
<?php
if( !count($_POST) )
{
	echo "<button onclick = 'toggle_expand()' id = 'toggle_expand_btn'>Collapse Table</button><br/><br/>";
	echo "<table border = '1' cellspacing='0' style='font:Arial; font-size:9pt;'><tr bgcolor='c0c0ff'>";
	foreach($mr_sheets_arr[1] as $k => $v)
	{
		if( $k == "id" )
			continue;
		
		echo "<td" . (substr($k, 0, 4) == "src_" ? " class='collapsible'" : "") . "><b>$k</b></td>";
	}
	echo "<td><b>latest_data_file</b></td><td><b>Import&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</b></td>";
	foreach($mr_sheets_arr as $sheet_id => $mr_sheet)
	{
		$last_import_date = $mr_sheet["last_import_date"];
		
		echo "</tr><tr>";
		foreach( $mr_sheet as $k => $v )
		{
			if( $k == "name" )
			{
				echo "<td nowrap>$v</td>";
				$sheet_name = $v;
			}
			elseif( $k != "id" )
				echo "<td" . (substr($k, 0, 4) == "src_" ? " class='collapsible'" : "") . ">$v</td>";
		}
		if( file_exists("./mr_data/$sheet_name.txt") )
		{
			$file_ts = filemtime("./mr_data/$sheet_name.txt") - (3600 * 7);
			//if( date('I', $file_ts) != 1 ) //account for daylight saving
			//	$file_ts -= 3600;
			
			$file_date_tz = date("Y-m-d H:i:s", $file_ts);
			echo "<td bgcolor='" . ($file_date_tz > $last_import_date ? "#ffb0b0" : "#b0ffb0") . "'>$file_date_tz</td>";
			echo ($file_date_tz > $last_import_date ? "<form method='post' action='#'><td><button name='sheet' value='$sheet_id'>Import</button></td></form>" : "<td></td>" );
		}
		else
			echo "<td bgcolor='#b0b0b0'>No data available</td><td></td>";
	}
	echo "</tr></table><br/><br/>";
	echo "
			<script>
			function toggle_expand()
			{
				const collection = document.getElementsByClassName('collapsible');
				for (let i = 0; i < collection.length; i++) 
				{
				  collection[i].classList.toggle('collapsed');
				}
				
				const toggle_expand_btn = document.getElementById('toggle_expand_btn');
				if( toggle_expand_btn.innerHTML == 'Expand Table' )
					toggle_expand_btn.innerHTML = 'Collapse Table';
				else
					toggle_expand_btn.innerHTML = 'Expand Table';
					
			}
			toggle_expand();
			</script>
	";
	
	
}
elseif( count($_POST) && isset($_POST["sheet"]) && is_numeric($_POST["sheet"]) )
{
	$sheet_id = $_POST["sheet"];
	
	if( !isset($mr_sheets_arr[$sheet_id]["name"]) )
		die("Invalid variables...");
	
	$log_file = fopen("import_sql_log_ajax.txt", "w");
	fwrite($log_file, "###########################\n Starting import_mr_txt_ajx_f.php " . date("Y-m-d H:i:s") . " \n###########################\n ");
	fclose($log_file);

	$sql = "update phsa_mr_data set inphp_status = '' where sheet_id = $sheet_id";
	$rs = $pdo->query($sql);
	echo "<a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a><br/><h1>" . $mr_sheets_arr[$sheet_id]["name"] . "</h1>";
	?>
	<div id='results'></div>
	
	<script>
		function load_import_result(round)
		{
			var results_div = document.getElementById("results");
			
			results_div.innerHTML = results_div.innerHTML + "<div id='result_" + round + "'>Working on round " + round + "<img src='loading.gif' width='30' /></div>";
			
			$("#result_" + round).load("import_mr_txt_ajx_b.php", 
										{
											sheet: <?php echo $sheet_id; ?>,
											round : round
										});
		}
		
		load_import_result(1);
	</script>
	<?php
	
	$log_file = fopen("import_sql_log_ajax.txt", "a");
	fwrite($log_file, "[F] Row iterator complete.... \n");
	$sql = "update phsa_mr_data set mr_status = 'Absent in latest MR' where sheet_id = $sheet_id and inphp_status = ''";
	fwrite($log_file, "[F]$sql \n");
	$rs = $pdo->query($sql);
	fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n");
	
	
	
	$sql = "update phsa_mr_sheets s
			set
				num_items = (select count(*) from phsa_mr_data where sheet_id = $sheet_id),
				total_count = (select sum(ifnull(d.total_count, 0)) from phsa_mr_data d where d.sheet_id = $sheet_id),
				mapped_total = (select count(*) from phsa_mr_data d where d.sheet_id = $sheet_id and ((d.map_source IS NOT NULL) OR EXISTS (select 1 from phsa_all_maps m where m.src_data_id = d.id)) and ifnull(exclude, 'A') <> 'Out of Scope - Exclude'),
				mapped_auto = (select count(*) from phsa_mr_data where sheet_id = $sheet_id and map_source = 'Auto' and ifnull(exclude, 'A') <> 'Out of Scope - Exclude'),
				mapped_total_by_count = (select ifnull(sum(ifnull(d.total_count, 0)), 0) from phsa_mr_data d where d.sheet_id = $sheet_id and ((d.map_source IS NOT NULL) OR EXISTS (select 1 from phsa_all_maps m where m.src_data_id = d.id)) and ifnull(exclude, 'A') <> 'Out of Scope - Exclude'),
				mapped_auto_by_count = (select ifnull(sum(ifnull(d.total_count, 0)), 0) from phsa_mr_data d where d.sheet_id = $sheet_id and d.map_source = 'Auto' and ifnull(exclude, 'A') <> 'Out of Scope - Exclude'),
				excluded_num = (select count(*) from phsa_mr_data where sheet_id = $sheet_id and exclude = 'Out of Scope - Exclude'),
				excluded_count = (select sum(ifnull(d.total_count, 0)) from phsa_mr_data d where d.sheet_id = $sheet_id and exclude = 'Out of Scope - Exclude'),
				total_mappable_count = (select sum(ifnull(d.total_count, 0)) from phsa_mr_data d where d.sheet_id = $sheet_id and ifnull(exclude, 'A') <> 'Out of Scope - Exclude'),
				last_import_date = now()
			where
				s.id = $sheet_id
			";
	fwrite($log_file, "[F] $sql \n");
	$rs = $pdo->query($sql);
	fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n\n");
	
	fwrite($log_file, "###########################\n Completed import_mr_txt_ajx_f.php " . date("Y-m-d H:i:s") . " \n###########################\n ");
	
	fclose($log_file);
}
else
	die("invalid variables");

?>
</div>
</body>
</html>