<?php
require_once("db.php");
require_once("common.php");

my_session_start();

verify_session();
$_USERNAME = $_SESSION["PHSA_UNAME"];


$page_size = 50;
$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

### $_GET handling
if( !isset($_GET["id"]) || !is_numeric($_GET["id"]) || floor($_GET["id"]) != $_GET["id"] || $_GET["id"] > 1000000 )
	die("Invalid page variables...");

$data_id = $_GET["id"];
##################

#### data info
$sql = "select * from phsa_mr_data d left outer join phsa_mr_data_comments c on c.data_id = d.id where d.id = $data_id";
$rs = $pdo->query($sql);
if( !($ft_data = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters 1...");
################

#### Sheets info
$sql = "select * from phsa_mr_sheets s where s.id = " . $ft_data["sheet_id"];
$rs = $pdo->query($sql);
if( !($ft_sheet = $rs->fetch(PDO::FETCH_ASSOC)) )
	die("Invalid parameters 2...");

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


### Sheet Vocabularies (i.e. what vocabulary can be selected for maps)
$sql = "
		select vocabulary
		from phsa_mr_sheet_vocabularies
		where sheet_id = " . $ft_data["sheet_id"] . "
		order by vocabulary";
$rs = $pdo->query($sql);
while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
	$shee_vocab_array[] = $ft["vocabulary"];

#### Sheet attributes
$sheet_attr_arr = set_sheet_attr_arr($pdo, $ft_data["sheet_id"]);

### SOURCE CODES AND DESC
$sources_arr = set_sources_arr($pdo, $data_id);


if( count($_POST) )
{
	##### Checking the "Importer" priviledge
	if( !isset($_SESSION["PHSA_PRIV_MAP"]) || $_SESSION["PHSA_PRIV_MAP"] !== "1" )
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

	$success_msg = "";
	$action = "";
	
	#### collecting initial data on count of available maps which will be used at the end of this if block
	$sql = "select count(*) as cnt from phsa_all_maps where src_data_id = $data_id";
	$rs = $pdo->query($sql);
	if( !($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		die("Unknown SQL error 88513");
	$initial_maps_count = $ft["cnt"];
	########
	
	### TARGETS
	$targets_arr = set_targets_arr($pdo, $data_id);

	### PHSA_MAPS
	$phsa_maps_arr = set_phsa_maps_arr($pdo, $data_id);

	### Creating an array in which targets are matched with phsa_maps as much as possible
	$num_rows = match_up_targets_with_maps($targets_arr, $phsa_maps_arr);
	
	
	if( isset($_POST["submit_update"]) && isset($_POST["map_id"]) && is_numeric($_POST["map_id"]) && isset($_POST["update_map_code"]) && isset($_POST["update_map_vocabulary"]) )
	{
		##UPDATE
		$vocabulary = strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_POST["update_map_vocabulary"]))));
		$map_code = strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_POST["update_map_code"]))));
		$map_id = $_POST["map_id"];

		if( !check_vocabulary_valid($pdo, $ft_data["sheet_id"], $vocabulary) )
			die("invalid vocabulary $vocabulary");
		if( !check_map_against_data($pdo, $map_id, $data_id) )
			die("invalid map id $map_id");
		
		########### Getting before_update_target_concept_id
		$sql = "select target_concept_id from phsa_all_maps where id = $map_id";
		$rs = $pdo->query($sql);
		if( !($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
			die("Unknown SQL error 6276");
		$before_update_target_concept_id = $ft["target_concept_id"];
		####################
		
		$sql = "update phsa_all_maps m, omop_concept c 
				set 
					m.target_concept_id = c.concept_id, 
					m.target_concept_name = c.concept_name, 
					m.target_vocabulary_id = '$vocabulary'					
				where 
					m.id = $map_id and
					c.concept_code = '$map_code' and 
					c.vocabulary_id = '$vocabulary'
				";
		$rs = $pdo->query($sql);
		$action = "Update";
		log_map_hx($pdo, $map_id, $action, $before_update_target_concept_id);
	}
	elseif( isset($_POST["submit_delete"]) && isset($_POST["map_id"]) && is_numeric($_POST["map_id"]) )
	{
		##DELETE
		if( !check_map_against_data($pdo, $_POST["map_id"], $data_id) )
			die("invalid map id " . $_POST["map_id"]);
		
		$action = "Delete";
		log_map_hx($pdo, $_POST["map_id"], $action);
		$sql = "delete from phsa_all_maps where id = " . $_POST["map_id"];
		$rs = $pdo->query($sql);
	}
	elseif( isset($_POST["submit_add"]) && isset($_POST["new_map_code"]) && isset($_POST["new_map_vocabulary"]) )
	{
		##ADD
		$vocabulary = strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_POST["new_map_vocabulary"]))));
		$map_code = trim(strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_POST["new_map_code"])))));
		
		if( in_array($map_code, array_column($targets_arr, 'm_concept_code')) )
			$success_msg = "<font color='red'>This target already exists!</font>";
		else
		{
			if( !check_vocabulary_valid($pdo, $ft_data["sheet_id"], $vocabulary) )
				die("invalid vocabulary $vocabulary");
			
			$sql = "insert into phsa_all_maps ( src_data_id, 
												source_vocabulary_id_1, source_code_1, source_code_description_1, 
												source_vocabulary_id_2, source_code_2, source_code_description_2, 
												source_vocabulary_id_3, source_code_3, source_code_description_3, 
												source_vocabulary_id_4, source_code_4, source_code_description_4, 
												source_vocabulary_id_5, source_code_5, source_code_description_5, 
												source_vocabulary_id_6, source_code_6, source_code_description_6, 
												target_concept_id, target_concept_name, target_vocabulary_id)
					select $data_id,
					";
			for( $i = 1 ; $i <= 6 ; $i++ )
			{
				if( isset($ft_sheet["src_voc_name_$i"]) )
					$sql .= "'" . $ft_sheet["src_voc_name_$i"] . "', '" . $sources_arr[$i]["code"] . "', '" . $sources_arr[$i]["description"] . "', ";
				else
					$sql .= "'', '', '', ";
			}
			$sql .= "concept_id, concept_name, '$vocabulary'
					from omop_concept where concept_code = '$map_code' and vocabulary_id = '$vocabulary'";
					
			$rs = $pdo->query($sql);
			$map_id = $pdo->lastInsertId();
			$action = "Add";
			log_map_hx($pdo, $map_id, $action);
		}
	}
	elseif( isset($_POST["exclude_status"]) && isset($_POST["comment_text"]) && isset($_POST["exclude_status_submit"]) )
	{
		##EXCLUDE STATUS
		$comment_text = strip_tags(str_replace("\"", "", str_replace("'", "", str_replace("\\", "", $_POST["comment_text"]))));
		if( $_POST["exclude_status"] == "e" )
		{
			$exclude_status = "Out of Scope - Exclude";
			$exclude_action = "Exclude";
		}
		elseif( $_POST["exclude_status"] == "q" )
		{
			$exclude_status = "Question - Pending";
			$exclude_action = "Question";
		}
		elseif( $_POST["exclude_status"] == "s" )
		{
			$exclude_status = "SDO Submission - Send";
			$exclude_action = "Set to SDO Submission";
		}
		elseif( $_POST["exclude_status"] == "p" )
		{
			$exclude_status = "SDO Submitted - Pending";
			$exclude_action = "Sent to SDO";
		}
		elseif( $_POST["exclude_status"] == "i" )
		{
			$exclude_status = "";
			$exclude_action = "Include";
		}
		else
			die("Invalid POST variables 6568767");
		
		$created_by = ($ft_data["created_by"] == "" ? $_USERNAME : $ft_data["created_by"]);
		$created_dttm = ($ft_data["created_by"] == "" ? "now()" : "'" . $ft_data["created_dttm"] . "'");
		
		$sql = "update phsa_mr_data 
				set exclude = '$exclude_status', 
					updated_by = '" . $_SESSION["PHSA_UNAME"] . "', 
					updated_dt = now()
				where id = $data_id limit 1";
		$rs = $pdo->query($sql);

		$sql = "replace into phsa_mr_data_comments (data_id,  comment_text,  created_by,  created_dttm, updated_by, updated_dttm)
											values ($data_id, '$comment_text', '$created_by', $created_dttm, '$_USERNAME', now())";
		$rs = $pdo->query($sql);
		
		log_map_hx_status($pdo, $data_id, $exclude_action);
	
		
		if( is_numeric($ft_data["total_count"]) )
		{
			if( $ft_data["exclude"] == "" && $exclude_status != "" )
			{
				$sql = "update phsa_mr_sheets 
						set 
							total_mappable_count = total_mappable_count - " . $ft_data["total_count"] . ", 
							excluded_num = excluded_num + 1, 
							excluded_count = excluded_count + " . $ft_data["total_count"] . "
						where 
							id = " . $ft_data["sheet_id"] . " limit 1";
				$rs = $pdo->query($sql);
			}
			elseif( $ft_data["exclude"] != "" && $exclude_status == "" )
			{
				$sql = "update phsa_mr_sheets 
						set 
							total_mappable_count = total_mappable_count + " . $ft_data["total_count"] . ", 
							excluded_num = excluded_num - 1, 
							excluded_count = excluded_count - " . $ft_data["total_count"] . "
						where 
							id = " . $ft_data["sheet_id"] . " limit 1";
				$rs = $pdo->query($sql);
			}
		}
		
		############# MANAGING PHSA_EXCLUSIONS TABLE
		if( $exclude_status == "Out of Scope - Exclude" )
		{
			if( count($sources_arr) > 1 )
				$success_msg .= "<font color='red'>Note- the map has multiple source codes and cannot be added to exclusions table.</font><br/>";
			else
			{
				$sql = "replace into phsa_exclusions (src_data_id, source_vocabulary_id_1, source_code_1, source_code_description_1) 
						values ($data_id, '" . $ft_sheet["src_voc_name_1"] . "', '" . $sources_arr[1]["code"] . "', '" . $sources_arr[1]["description"] . "');";
			}
		}
		else
			$sql = "delete from phsa_exclusions where src_data_id = $data_id";

		$rs = $pdo->query($sql);
		#############################################
		
		$action = "";
		$ft_data["exclude"] = $exclude_status;
		$ft_data["comment_text"] = $comment_text;
	}
	else
	{
		print_r($_POST);
		die("Invalid POST variables");
	}
	#### Processing counts based on initial data
	if( $action == "Delete" && $initial_maps_count == 1 && is_numeric($ft_data["total_count"]) )
	{
		if( $ft_data["map_source"] == "Auto" ) // this happens when this was auto, then mapped, then the map is being deleted
		{
			$sql = "update phsa_mr_sheets
				set 
					mapped_auto = mapped_auto + 1,
					mapped_auto_by_count = mapped_auto_by_count + " . $ft_data["total_count"] . " 
				where id = " . $ft_data["sheet_id"];
		}
		else
		{
			$sql = "update phsa_mr_sheets
				set 
					mapped_total = mapped_total - 1,
					mapped_total_by_count = mapped_total_by_count - " . $ft_data["total_count"] . " 
				where id = " . $ft_data["sheet_id"];
		}
		$rs = $pdo->query($sql);
	}
	elseif( $action == "Add" && $initial_maps_count == 0 && is_numeric($ft_data["total_count"]) )
	{
		if( $ft_data["map_source"] == "Auto" )
		{
			$sql = "update phsa_mr_sheets
				set 
					mapped_auto = mapped_auto - 1,
					mapped_auto_by_count = mapped_auto_by_count - " . $ft_data["total_count"] . " 
				where id = " . $ft_data["sheet_id"];
		}
		else
		{
			$sql = "update phsa_mr_sheets
				set 
					mapped_total = mapped_total + 1,
					mapped_total_by_count = mapped_total_by_count + " . $ft_data["total_count"] . " 
				where id = " . $ft_data["sheet_id"];
		}
		$rs = $pdo->query($sql);
	}
	if( empty($success_msg) )
		$success_msg = "Map updated successfully.";
	#####
}



### ATTRIBUTES
$data_attr_arr = set_data_attr_arr($pdo, $data_id, $sheet_attr_arr);

### TARGETS
$targets_arr = set_targets_arr($pdo, $data_id);

### PHSA_MAPS
$phsa_maps_arr = set_phsa_maps_arr($pdo, $data_id);

### Creating an array in which targets are matched with phsa_maps as much as possible
$num_rows = match_up_targets_with_maps($targets_arr, $phsa_maps_arr);

?>
<div  align='center' style='background-color:#f8fdff; margin:30px; padding:40px;'>
<table border='0' cellpadding = '15'>
<tr style="background-color:#f8fdff;"><td colspan='3' align='center'><div id='success_msg' style='font-size:20px; color:#20c020; '><?php if( isset($success_msg) ) echo $success_msg; ?></div></td></tr>
<tr style="background-color:#f8fdff;"><td>
	<table border='1' cellspacing='0' cellpadding='3' style="font-size:11pt;">

	<tr style='color:white; background-color:#202080;'>
		<td>Column Name</td>
		<td>Value</td>
	</tr>

	<?php
	echo "<tr><td>Status</td>";
	if( $ft_data["exclude"] == "Out of Scope - Exclude" )
		echo "<td bgcolor='white'><img src = 'x.png' width='26' border='0' /></td>";
	elseif( $ft_data["exclude"] == "Question - Pending" )
		echo "<td bgcolor='white'><img src = 'question.png' width='24' border='0' /></td>";
	else
		echo "<td bgcolor='white'><img src = 'check.png' width='24' border='0' /></td>";
	echo "</tr>";
	
	for( $i = 1 ; $i <= count($sources_arr) ; $i++ ) 
	{
		echo "<tr><td>" . $ft_sheet["src_cd_colname_$i"] . "</td><td>" . $sources_arr[$i]["code"] . "</td></tr>";
		echo "<tr><td>" . $ft_sheet["src_desc_colname_$i"] . "</td><td>" . $sources_arr[$i]["description"] . "</td></tr>";
	}
	$i = 0;
	foreach( $sheet_attr_arr as $attr )## assumption: $sheet_attr_arr will have the same number of elements
	{
		echo "<tr><td>" . $attr["attr_name"] . "</td><td>" . $data_attr_arr[$i] . "</td></tr>";
		$i++;
	}
	echo "<tr><td>Map&nbsp;Src</td><td>" . $ft_data["map_source"] . "</td></tr>";
	echo "<tr><td>Count</td><td>" . $ft_data["total_count"] . "</td></tr>";

	for( $r = 0, $One2M_index = 1 ; $r < $num_rows ; $r++, $One2M_index++ )
	{
		if( $targets_arr[$r]["t_concept_id"] == "" && $targets_arr[$r]["m_concept_id"] == "" )
		{
			echo "<tr style='background-color:#ffb0b0;'><td colspan='2' align='center'>No map found!</td></tr>";
			continue;
		}
		$tr_bgcolor = get_tr_bgcolor($targets_arr[$r]["t_concept_code"], $targets_arr[$r]["m_concept_code"], $ft_data["mr_status"]);
		$map_id = $targets_arr[$r]["m_map_id"];
		
	//	print_r($targets_arr);
		echo "<input type='hidden' name='map_id' id='map_id' value='$map_id' />";
		echo "<tr style='background-color:#000040;'><td colspan='2' style='height:5px;'></td></tr>";
			

		echo "<tr><td>Target Concept ID</td><td>" . $targets_arr[$r]["t_concept_id"] . "</td></tr>";
		echo "<tr><td>Target Concept Name</td><td>" . $targets_arr[$r]["t_concept_name"] . "</td></tr>";
		echo "<tr><td>Target Concept Code</td><td>" . $targets_arr[$r]["t_concept_code"] . "</td></tr>";
		echo "<tr><td>Target Concept Domain</td><td>" . $targets_arr[$r]["t_domain_id"] . "</td></tr>";
		echo "<tr><td>Target Concept Vocabulary</td><td>" . $targets_arr[$r]["t_vocabulary_id"] . "</td></tr>";
		
		echo "<tr style='background-color:$tr_bgcolor;'><td>Map Concept ID</td><td>" . $targets_arr[$r]["m_concept_id"] . "</td></tr>";
		echo "<tr style='background-color:$tr_bgcolor;'><td>Map Concept Name</td><td>" . $targets_arr[$r]["m_concept_name"] . "</td></tr>";
		echo "<tr style='background-color:$tr_bgcolor;'><td>Map Concept Code</td><td>";
		if( is_numeric($map_id) )
			echo "<input type='text' name='update_map_code' id='update_map_code_$map_id' size='25' maxlength='25' value='" . $targets_arr[$r]["m_concept_code"] . "' />";
		echo "</td></tr>";
		echo "<tr style='background-color:$tr_bgcolor;'><td>Map Concept Domain</td><td>" . $targets_arr[$r]["m_domain_id"] . "</td></tr>";
		echo "<tr style='background-color:$tr_bgcolor;'><td>Map Concept Vocabulary</td><td>";
		if( is_numeric($map_id) )
		{
			echo "<select name='update_map_vocabulary' id='update_map_vocabulary_$map_id'>";
			output_vocabulary_options($shee_vocab_array, $targets_arr[$r]["m_vocabulary_id"]);
			echo "</select>";
		}
		echo "</td></tr>";
		
		if( is_numeric($map_id) )
			echo "<tr><td colspan='2' align='center'><button name='submit_update' id='submit_update_btn' onclick=\"submit_update($data_id, $map_id);\"> Update </button>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<button name='submit_delete' id='submit_delete_btn' onclick=\"if(confirm('Are you sure you want to delete this map?')) submit_delete($data_id, $map_id); else return false;\"/> Delete </buttin></td></tr>";
	}

	echo "<tr style='background-color:#000040; color:#ffffe0;'><td colspan='2' style='height:5px;' align='center'>Add New Map</td></tr>";
	echo "<tr><td>Concept Code</td><td><input type='text' id='new_map_code' size='25' maxlength='25' /></td></tr>";
	echo "<tr><td>Concept Vocabulary</td><td><select id='new_map_vocabulary'>";
	output_vocabulary_options($shee_vocab_array, "");
	echo "</select></td></tr>";
	echo "<tr><td colspan='2' align='center'><button name='submit_add' id='submit_add_btn' onclick=\"submit_add($data_id);\"> Add </button></td></tr>";
	echo "</table>";
	
echo "</td><td></td><td>";
echo "<table border='0' cellpadding='10'>";
echo "<tr><td valign='middle'><input type='radio' name='exclude_status' value='i' " . ($ft_data["exclude"] == "" ? " checked" : "") . " />&nbsp;<img src = 'check.png' width='24' border='0' />&nbsp;Included</td></tr>";
echo "<tr><td valign='middle'><input type='radio' name='exclude_status' value='q' " . ($ft_data["exclude"] == "Question - Pending" ? " checked" : "") . " />&nbsp;<img src = 'question.png' width='20' border='0' />&nbsp;Question - pending clarification by SME</td></tr>";
echo "<tr><td valign='middle'><input type='radio' name='exclude_status' value='e' " . ($ft_data["exclude"] == "Out of Scope - Exclude" ? " checked" : "") . " />&nbsp;<img src = 'x.png' width='20' border='0' />&nbsp;Excluded - out of scope for mapping</td></tr>";
echo "<tr><td valign='middle'><input type='radio' name='exclude_status' value='s' " . ($ft_data["exclude"] == "SDO Submission - Send" ? " checked" : "") . " />&nbsp;<img src = 'send.png' width='26' border='0' />&nbsp;SDO Submission - Send</td></tr>";
echo "<tr><td valign='middle'><input type='radio' name='exclude_status' value='p' " . ($ft_data["exclude"] == "SDO Submitted - Pending" ? " checked" : "") . " />&nbsp;<img src = 'submit.png' width='26' border='0' />&nbsp;SDO Submitted - Pending</td></tr>";
echo "</table>";


echo "<br/><br/>Comment:<br/><textarea name='comment_text' id='comment_text' cols='50' rows='5'>" . $ft_data["comment_text"] . "</textarea><br/><br/>";
echo "<div align='right'><button name='submit_excl_update' id='submit_excl_update_btn' onclick=\"submit_excl_update($data_id);\">  Update  </button></div></td></tr></table>";
	

echo "<p><br/></p><p><br/></p><h2>Change History</h2>";
echo "<table border='1' cellpadding='3' cellspacing='0'><tr style='color:white; background-color:#202080;'>";
echo "<td>map_id</td><td>";

for( $i = 1 ; $i <= 6 ; $i++ )
{
	if( isset($ft_sheet["src_voc_name_$i"]) )
		echo "source_vocabulary_id_$i</td><td>source_code_$i</td><td>source_code_description_$i</td><td>";
}
echo "target_concept_id</td><td>target_concept_name</td><td>target_vocabulary_id</td><td>date_time</td><td>username</td><td>action</td>";
										
echo "</tr>";

$sql = "
	select c.concept_name as c_concept_name, c.vocabulary_id as c_vocabulary_id, h.* from phsa_all_maps_hx  h
	left outer join omop_concept c 
		on c.concept_id = h.target_concept_id
	left outer join omop_concept c_upd
		on c_upd.concept_id = h.before_update_target_concept_id
	where src_data_id = $data_id 
	order by date_time desc
	";
$rs = $pdo->query($sql);
while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
{
	echo "<tr><td>" . $ft["map_id"] . "</td><td>";
	for( $i = 1 ; $i <= 6 ; $i++ )
	{
		if( isset($ft_sheet["src_voc_name_$i"]) )
			echo $ft["source_vocabulary_id_$i"] . "</td><td>" . $ft["source_code_$i"] . "</td><td>" . $ft["source_code_description_$i"] . "</td><td>";
	}
	echo $ft["target_concept_id"] . "</td><td>" . $ft["c_concept_name"] . "</td><td>" . $ft["c_vocabulary_id"] . "</td><td>" . $ft["date_time"] . "</td><td>" . $ft["username"] . "</td><td>" . $ft["change_action"] . "</td>";
	echo "</tr>";
}
?>
</table>
</div>
<?php
##### Checking the "Importer" priviledge
if( !isset($_SESSION["PHSA_PRIV_MAP"]) || $_SESSION["PHSA_PRIV_MAP"] !== "1" )
{
?>
	<script>
	if( document.getElementById("submit_add_btn") )
		document.getElementById("submit_add_btn").disabled = true;
	
	if( document.getElementById("submit_delete_btn") )
		document.getElementById("submit_delete_btn").disabled = true;
	
	if( document.getElementById("submit_update_btn") )
		document.getElementById("submit_update_btn").disabled = true;
	
	if( document.getElementById("submit_excl_update_btn") )
		document.getElementById("submit_excl_update_btn").disabled = true;
	</script>
<?php
}
#######################################


if( count($_POST) )
{
?>
	<script>
	var mr_list_table = document.getElementById("mr_list_table");
	var mr_list_row, tr_id, html_text, row_num;
	for( var i = 1 ; tr_to_rem = document.getElementById("tr_" + <?php echo $data_id; ?> + "_" + i) ; i++ )
	{
		if( i == 1 )
		{
			tr_ind = tr_to_rem.rowIndex;
			row_num = tr_to_rem.cells[1].innerText;
		}
		tr_to_rem.remove();
	}
	
<?php		
	$td_bg = "#e8efff";
	$row = "##@##";

	for( $r = 0, $One2M_index = 1 ; $r < $num_rows ; $r++, $One2M_index++ )
	{
		$tr_html = list_mr_create_tr($ft_data, $sources_arr, $targets_arr, $data_attr_arr);
?>
		mr_list_row = mr_list_table.insertRow(tr_ind);
		tr_id = <?php echo "\"tr_$data_id" . "_$One2M_index\""; ?>;
		mr_list_row.id = tr_id;
		html_text = <?php echo "\"$tr_html\""; ?>;
		document.getElementById(tr_id).outerHTML = html_text.replace('##@##', row_num);
		tr_ind++;

<?php
	}
?>
	</script>
<?php
}
?>
