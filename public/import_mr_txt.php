<?php
//die("Security Lock!<br/>This is to prevent accidental run of this script when not needed. Remove the lock before running this :-)");
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
	
	$sql = "update phsa_mr_data set inphp_status = '' where sheet_id = $sheet_id";
	$rs = $pdo->query($sql);
	
	$log_file = fopen("import_sql_log.txt", "w");
	fwrite($log_file, "###########################\n Starting import_mr_txt.php " . date("Y-m-d H:i:s") . " \n###########################\n ");

	if( !isset($mr_sheets_arr[$sheet_id]["name"]) )
		die("Invalid variables...");
	
	$inputFileName = "./mr_data/" . $mr_sheets_arr[$sheet_id]["name"] . ".txt";

	if( !file_exists($inputFileName) )
		die("$inputFileName was not found. Please copy it in /mr_data folder and try again...");

	$data_file = fopen($inputFileName, "r");

	echo "<a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a><br/><h1>" . $mr_sheets_arr[$sheet_id]["name"] . "</h1>";
	
	###### GETTING SHEET ATTRIBUTES #######
	$sheet_attr_arr = set_sheet_attr_arr($pdo, $sheet_id);
	##############
	
	###### GETTING SOURCE VOCABULARY NAMES + SOURCE CODE COLUMN NAMES THAT ARE EXPECTED FOR THIS SHEET #######
	$src_voc_names_arr = array();
	$src_cd_colnames_arr = array();
	$src_desc_colnames_arr = array();
	
	for( $i = 1 ; $i <= 6 ; $i++ )
	{
		if( $mr_sheets_arr[$sheet_id]['src_voc_name_' . $i] == "" )
		{
			if( $i == 1 )
				die("Invalid sheet data - no source vocabulary name defined");
			
			break;
		}
		if( $mr_sheets_arr[$sheet_id]['src_cd_colname_' . $i] == "" || $mr_sheets_arr[$sheet_id]['src_desc_colname_' . $i] == "" ) // if source vocabulary is in the data, source code column name and source desc column name must also be in the data
			die("Invalid sheet data - no source code/description column name defined in spot $i");
		
		$src_voc_names_arr[$i] 		= $mr_sheets_arr[$sheet_id]['src_voc_name_' . $i];		
		$src_cd_colnames_arr[$i] 	= $mr_sheets_arr[$sheet_id]['src_cd_colname_' . $i];		
		$src_desc_colnames_arr[$i] 	= $mr_sheets_arr[$sheet_id]['src_desc_colname_' . $i];		
	}
	##############
	
	###### GETTING COLUMN NAMES FROM THE MAPPINGREPORT SHEET #######
	$mr_sheet_column_names = array();
	
	$line = fgets($data_file);
	$line = str_replace(chr(10), "", str_replace(chr(13), "", $line));
	$line = str_replace("\"", "", $line);

	if( $line == "" )
		die("File is empty.");

	$line_values_arr = explode(chr(9), $line);
	
	$nb = 0;
	foreach ($line_values_arr as $col) 
	{
		if( is_null($col) || trim($col) == "" )
			break;
		
		$mr_sheet_column_names[ ++$nb ] = trim($col);
	}
	##############
	echo "<pre><p>sheet_attr_arr</p>\n\n";
	print_r( $sheet_attr_arr );
	echo "<p>src_voc_names_arr</p>\n\n";
	print_r( $src_voc_names_arr );
	echo "<p>src_cd_colnames_arr</p>\n\n";
	print_r( $src_cd_colnames_arr );
	echo "<p>src_desc_colnames_arr</p>\n\n";
	print_r( $src_desc_colnames_arr );
	echo "<p>mr_sheet_column_names</p>\n\n";
	print_r( $mr_sheet_column_names );
	echo "<p> </p></pre>";
	
	###### CHECKING DATA (IN DB) AGAINST MR SHEET: (1) ATTRIBUTES IN MR SHEET MUST BE IN THE RIGHT COLUMN  (2) SOURCE CODE COLUMNS AND SOURCE DESCRIPTION COLUMNS MUST BE PRESENT IN MR SHEET (3) A COLUMN MUST BE PRESENT IN MR SHEET WITH THE NAME OMOP Concept ID #######
	### (1)
	foreach( $sheet_attr_arr as $ar_v )
	{
		if( !isset($mr_sheet_column_names[$ar_v["col_position"]]) || $mr_sheet_column_names[$ar_v["col_position"]] != $ar_v["attr_name"] )
			die("Column " . $ar_v["attr_name"] . " not found in MappingReport in position " . $ar_v["col_position"]);
	}
	### (2)
	foreach( $src_cd_colnames_arr as $v )
	{
		if( !in_array($v, $mr_sheet_column_names) )
			die("Column $v not found in MappingReport" );
	}
	foreach( $src_desc_colnames_arr as $v )
	{
		if( !in_array($v, $mr_sheet_column_names) )
			die("Column $v not found in MappingReport" );
	}
	### (3)
	if( !in_array("OMOP Concept ID", $mr_sheet_column_names) )
		die("Column OMOP Concept ID not found in MappingReport" );
	##############
	
	###### ITERATION THROUGH MR ROWS #######
	for( $r = 2 ; $line = fgets($data_file) ; $r++ )
	{
		$line = str_replace(chr(10), "", str_replace(chr(13), "", $line));
		$line = str_replace("\"", "", $line);
		$line = str_replace("'", "''", $line);
		$line = str_replace("\\", "\\\\", $line);
		$line = str_replace("`", "", $line);

		if( $line == "" )
			break;

		fwrite($log_file, "\nStarting to process row $r ($line) at " . date("Y-m-d H:i:s") . "\n");
		$row_data = explode(chr(9), $line);
		array_unshift($row_data , ''); // adding a blank item in position 0 so that line data starts at position 1 to match with mr_sheet_column_names
		
		###### CREATING SQL AND CHECKING IF THIS MR ROW EXISTS IN DB #######
		$sql = "select id, inphp_status from phsa_mr_data d where sheet_id = $sheet_id";
		for( $i = 1 ; $i <= count($src_cd_colnames_arr) ; $i++ )
		{
			$sql .= " and exists (select 1 from phsa_mr_data_src_cd_desc dsc$i where dsc$i.data_id = d.id and dsc$i.spot = $i and dsc$i.code = '" . $row_data[array_search($src_cd_colnames_arr[$i], $mr_sheet_column_names)] . "')";
		}
		$rs = $pdo->query($sql);
		fwrite($log_file, "[24]SEARCH SQL: $sql \n");
		if( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		{
			$found = true;
			$data_id = $ft["id"];
		}
		else
			$found = false;
		##############
		
		###### HANDLING THE POSSIBILE OUTCOMES: (1) FOUND (1.A) inphp_status = null (1.B) inphp_status = inserted  (2) NOT FOUND #######
		if( $found )
		{
			if( is_null($ft["inphp_status"]) || $ft["inphp_status"] == "" )
			{
				fwrite($log_file, "[267] Row found - inphp_status = '' \n");
				
				### updating source descriptions
				for( $i = 1 ; $i <= count($src_cd_colnames_arr) ; $i++ )
				{
					$sql = "update phsa_mr_data_src_cd_desc set description = '" . $row_data[array_search($src_desc_colnames_arr[$i], $mr_sheet_column_names)] . "' where data_id = $data_id and spot = $i and code = '" . $row_data[array_search($src_cd_colnames_arr[$i], $mr_sheet_column_names)] . "'";
					fwrite($log_file, "[270] $sql \n");
					$rs = $pdo->query($sql);
					fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n");
				}

				### target
				$sql = "delete from phsa_mr_data_targets where data_id = $data_id";
				fwrite($log_file, "[277] $sql \n");
				$rs = $pdo->query($sql);
				fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n");
				
				$target_concept_id = $row_data[array_search("OMOP Concept ID", $mr_sheet_column_names)];
				insert_target( $data_id, $target_concept_id );
				
				### attributes
				$sql = "delete from phsa_mr_attr_data where data_id = $data_id";
				fwrite($log_file, "[286] $sql \n");
				$rs = $pdo->query($sql);
				fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n");
				
				insert_attributes($data_id, $row_data);

				### phsa_all_maps
				update_maps($data_id, $row_data);
				
				### Count, Map Source, inphp_status
				set_data_vars($count, $map_source, $row_data);
				
				$sql = "update phsa_mr_data 
						set total_count = $count, map_source = $map_source, inphp_status = 'inserted', updated_by='$_USERNAME', updated_dt = now(), mr_status = 'Still in MR' 
						where id = $data_id";
				fwrite($log_file, "[301] $sql \n");
				$rs = $pdo->query($sql);
				fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n");
			}
			elseif( $ft["inphp_status"] == "inserted" )
			{
				### target
				$target_concept_id = $row_data[array_search("OMOP Concept ID", $mr_sheet_column_names)];
				insert_target( $data_id, $target_concept_id );
			}
			else
				die("Invalid state of data (inphp_status)");
		}
		else
		{
			fwrite($log_file, "[316] Row not found \n");
			### Count, Map Source
			set_data_vars($count, $map_source, $row_data);
			
			### Insert in phsa_mr_data
			$sql = "INSERT INTO phsa_mr_data (id, sheet_id, total_count, map_source, inserted_by, inserted_dt, inphp_status, mr_status) 
									VALUES ( null, $sheet_id, $count, $map_source, '$_USERNAME', now(), 'inserted', 'New in MR' )";
			fwrite($log_file, "[323]$sql ---> id = $data_id ; ");
			$rs = $pdo->query($sql);
			$data_id = $pdo->lastInsertId();
			fwrite($log_file, $rs->rowCount() . " row inserted\n");
			
			### source
			for( $i = 1 ; $i <= count($src_cd_colnames_arr) ; $i++ )
			{
				$sql = "insert into phsa_mr_data_src_cd_desc (data_id, spot, code, description) values($data_id, $i, '" . $row_data[array_search($src_cd_colnames_arr[$i], $mr_sheet_column_names)] . "', '" . $row_data[array_search($src_desc_colnames_arr[$i], $mr_sheet_column_names)] . "')";
				fwrite($log_file, "[332] $sql \n");
				$rs = $pdo->query($sql);
				fwrite($log_file, " ---> " . $rs->rowCount() . " row inserted \n");
			}
			
			### target
			$target_concept_id = $row_data[array_search("OMOP Concept ID", $mr_sheet_column_names)];
			insert_target( $data_id, $target_concept_id );
			
			### attributes
			insert_attributes($data_id, $row_data);
			
			### phsa_all_maps
			update_maps($data_id, $row_data);
		}		
		##############
	##############
	}
	fwrite($log_file, "[350] Row iterator complete.... \n");
	$sql = "update phsa_mr_data set mr_status = 'Absent in latest MR' where sheet_id = $sheet_id and inphp_status = ''";
	fwrite($log_file, "[352]$sql \n");
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
	fwrite($log_file, "[373] $sql \n");
	$rs = $pdo->query($sql);
	fwrite($log_file, " ---> " . $rs->rowCount() . " rows affected\n\n");
	
	fwrite($log_file, "###########################\n Completed import_mr_txt.php " . date("Y-m-d H:i:s") . " \n###########################\n ");
	
	fclose($log_file);
}
else
	die("invalid variables");





function insert_target($data_id, $concept_id)
{
	global $pdo, $log_file;
	
	if( is_numeric($concept_id) )
	{
		$sql = "insert into phsa_mr_data_targets (data_id, concept_id) values($data_id, $concept_id)";
		$rs = $pdo->query($sql);
		fwrite($log_file, "$sql ---> " . $rs->rowCount() . " row inserted\n");
	}
}
function insert_attributes($data_id, $row_data)
{
	global $pdo, $log_file, $sheet_attr_arr;
	
	foreach( $sheet_attr_arr as $ar_v )
	{
		$sql = "insert into phsa_mr_attr_data (data_id, attr_id, value) values($data_id, " . $ar_v["id"] . ", '" . $row_data[$ar_v["col_position"]] . "')";
		fwrite($log_file, "TO RUN (1): $sql \n");
		$rs = $pdo->query($sql);
		fwrite($log_file, "COMPLETE (1): $sql ---> " . $rs->rowCount() . " row(s) inserted\n");
	}
}
function update_maps($data_id, $row_data)
{
	global $pdo, $log_file, $sheet_attr_arr, $src_voc_names_arr, $src_cd_colnames_arr, $mr_sheet_column_names;

	$sql = "update phsa_all_maps 
			set src_data_id = $data_id
			where ";
	for( $i = 1 ; $i <= count($src_voc_names_arr) ; $i++ )
	{
		if( $i > 1 )
			$sql .= " and ";
		
		$source_code_to_check 			= $row_data[array_search( $src_cd_colnames_arr[$i], $mr_sheet_column_names)];
		$source_vocabulary_id_to_check 	= ($source_code_to_check == "" ? "" : $src_voc_names_arr[$i]);
		
		$sql .= "source_vocabulary_id_$i = '$source_vocabulary_id_to_check' and source_code_$i = '$source_code_to_check'";
	}
	for( ; $i <= 6 ; $i++ )
		$sql .= "and (source_vocabulary_id_$i = '' OR source_vocabulary_id_$i is null)   and   (source_code_$i = '' OR source_code_$i is null)";

	$rs = $pdo->query($sql);
	fwrite($log_file, "$sql ---> " . $rs->rowCount() . " rows affected\n");
}
function set_data_vars(&$count, &$map_source, $row_data)
{
	global $pdo, $log_file, $mr_sheet_column_names;

	$count_col = array_search( "Count", $mr_sheet_column_names );
	if( $count_col )
		$count = str_replace(",", "", $row_data[$count_col]);
	else
		$count = "null";
	
	$reviewed_col = array_search( "Reviewed", $mr_sheet_column_names );
	if( $reviewed_col )
	{
		if( trim($row_data[$reviewed_col]) == "" )
			$map_source = "null";
		else
			$map_source = $row_data[$reviewed_col] == "Auto" ? "'Auto'" : "'User'";
	}
	elseif( is_numeric($row_data[array_search("OMOP Concept ID", $mr_sheet_column_names)]) )
		$map_source = "'User'";
		
	else
		$map_source = "null";
	
	
}
?>
</div>
</body>
</html>