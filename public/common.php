<?php
$empty_target_arr = array("t_concept_id" => "", "t_concept_code" => "", "t_concept_name" => "", "t_domain_id" => "", "t_vocabulary_id" => "", "t_valid_end_date" => "");
$empty_map_arr = array("m_concept_id" => "", "m_concept_code" => "", "m_map_id" => "", "m_concept_name" => "", "m_domain_id" => "", "m_vocabulary_id" => "");


function my_session_start()
{
	session_start();
}


if( !defined("_SESSION_PASS") )
	define("_SESSION_PASS","1oe2z7Yg]LwrbsqsYFWZR@DD_edn84g^LJQM=QaHG<92Gm=7GLak@vs[:p*2a9[kqS?aK8yUBCgD7nQ2T80MHWMwr4Mu6j@Jlv6bQUMfMDJqb8bv6KtE6:EgzWaB3oqn");


function check_expected($expected, $arr = "")
{
	if( $arr == "" )
		$arr = $_POST;
	
	foreach($expected as $exp)
	{
		if( !in_array($exp,array_keys($arr)) )
			return false;
	}
	return true;
}

function verify_session_time()
{
	if( !isset($_SESSION["lastAcc"]) || $_SESSION["lastAcc"] == "" || !isset($_SESSION["key"]) )
		return false;

	$lastAcc = $_SESSION["lastAcc"];
	$key = $_SESSION["key"];
	$now = time();
	if( $now < ($lastAcc + (60*30)) && md5(_SESSION_PASS . $lastAcc) == $key )  // session not older than 30 minutes and right key
	{
		$_SESSION["lastAcc"] = $now;
		$_SESSION["key"] = md5(_SESSION_PASS . $now);

		return true;
	}

	$_SESSION["lastAcc"] = "";
	$_SESSION["key"] = "";

	return false;
}

function verify_session_vars()
{
	$retval = true;

	if( !isset($_SESSION["PHSA_USER"]) || !isset($_SESSION["PHSA_UID"]) || $_SESSION["PHSA_USER"] != "Y" || $_SESSION["PHSA_UID"] == "" 
			|| !isset($_SESSION["PHSA_UNAME"]) || $_SESSION["PHSA_UNAME"] == "" )
		$retval = false;

	if( !$retval )
	{
		$_SESSION["POS_USER"]			= "";
		$_SESSION["POS_UID"]			= "";
		$_SESSION["POS_UNAME"]			= "";

		unset($_SESSION["POS_USER"]);
		unset($_SESSION["POS_UID"]);
		unset($_SESSION["POS_UNAME"]);

		return false;
	}

	return true;

}
/* ──────────────────────────────────────────────────────────
   Put this block **right above** function verify_session()
   in  common.php   (anywhere before the function is defined)
   ────────────────────────────────────────────────────────── */
   if (!defined('DEV_AUTH_BYPASS')) {
    // Default: 0 (normal auth).  Flip to 1 while you test.
    define('DEV_AUTH_BYPASS', 1);
}

function verify_session($redirect = true, $return_url = "index.php")
{
	if (DEV_AUTH_BYPASS) return true;           // ← DEV shortcut

	if(  !verify_session_vars() )
	{	
		if( !$redirect )
			return false;

		header("Location:login.php?ret=$return_url");

		echo "<script language='javascript'>";
		echo "<!-- \n";
		echo "window.location='login.php?ret=$return_url';";
		echo "// -->";
		echo "</script>";
		die();
	}

	if(  !verify_session_time() )
	{	
		if( !$redirect )
			return false;

		header("Location:login.php?ret=$return_url");

		echo "<script language='javascript'>";
		echo "<!-- \n";
		echo "window.location='login.php?ret=$return_url';";
		echo "// -->";
		echo "</script>";
		die();
	}

	return true;
}

function clean_input( $input )
{
	$clean_input = array();
	
	foreach( $input as $k=>$v )
	{
		$clean_val = trim( strip_tags($v) );
		$clean_val = str_replace("'","", str_replace("\"","",$clean_val));
		$clean_val = str_replace("\\","", str_replace("&","(and)",$clean_val));
		$clean_val = str_replace(":","(colon)", str_replace("`","",$clean_val));
		
		$clean_input[$k] = $clean_val;
	}
	return $clean_input;
}


function get_ip()
{
	if (getenv("HTTP_X_FORWARDED_FOR"))
		$ip=getenv("HTTP_X_FORWARDED_FOR"); 
	else
		$ip=getenv("REMOTE_ADDR"); 

	return $ip;
}

function error_handle($msg = "")
{
	die("Error! $msg");
}

function set_sources_arr($pdo, $data_id)
{
	$sources_arr = array();
	$sql = "select * from phsa_mr_data_src_cd_desc where data_id = $data_id order by spot";
	$rs = $pdo->query($sql);
	while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		$sources_arr[ $ft["spot"] ] = $ft;
	
	return $sources_arr;
}

function set_data_attr_arr($pdo, $data_id, $sheet_attr_arr)
{
	$data_attr_arr = array();
	if( count($sheet_attr_arr) ) 
	{
		$sql = "select value from phsa_mr_attr_data ad, phsa_mr_sheets_attr sa where ad.attr_id = sa.id and data_id = $data_id order by sa.col_position";
		$rs = $pdo->query($sql);
		while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
			$data_attr_arr[] = $ft["value"];
	}
	return $data_attr_arr;
}

function set_targets_arr($pdo, $data_id)
{
	global $empty_target_arr;
	$targets_arr = array();
	
	$sql = "select  c.concept_id as t_concept_id,
					c.concept_code as t_concept_code, 
					c.concept_name as t_concept_name, 
					c.domain_id as t_domain_id, 
					c.vocabulary_id as t_vocabulary_id,
					c.valid_end_date as t_valid_end_date
					
			from phsa_mr_data_targets dt
			left outer join omop_concept c 
				on c.concept_id = dt.concept_id
			where data_id = $data_id";
	$rs = $pdo->query($sql);
	while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		$targets_arr[] = $ft;
	
	if( !isset($targets_arr[0]) )
		$targets_arr[0] = $empty_target_arr;

	return $targets_arr;
}

function set_phsa_maps_arr($pdo, $data_id)
{
	$phsa_maps_arr = array();

	$sql = "select  c.concept_id as m_concept_id,
					c.concept_code as m_concept_code, 
					m.id as m_map_id, 
					m.target_concept_name as m_concept_name, 
					c.domain_id as m_domain_id, 
					m.target_vocabulary_id as m_vocabulary_id
					
			from phsa_all_maps m
			left outer join omop_concept c 
				on c.concept_id = m.target_concept_id
			where m.src_data_id = $data_id";
	$rs = $pdo->query($sql);
	while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		$phsa_maps_arr[] = $ft;

	return $phsa_maps_arr;
}

function set_sheet_attr_arr($pdo, $sheet_id)
{
	$sheet_attr_arr = array();

	$sql = "select * from phsa_mr_sheets_attr where sheet_id = $sheet_id order by col_position";
	$rs = $pdo->query($sql);
	while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
	{
		$sheet_attr_arr[ $ft["id"] ] = $ft;
	}

	return $sheet_attr_arr;
}

function match_up_targets_with_maps(&$targets_arr, $phsa_maps_arr)
{
	global $empty_map_arr, $empty_target_arr;
	
	$num_rows = max( count($targets_arr), count($phsa_maps_arr) );
	
	for( $i = 0 ; $i < $num_rows ; $i++ ) // need 2 loops. This is first, where we try to match each target with the same code in phsa_maps
	{
		if( isset( $targets_arr[$i] ) )
		{
			$key = array_search($targets_arr[$i]["t_concept_code"],  array_combine(array_keys($phsa_maps_arr), array_column($phsa_maps_arr, 'm_concept_code'))  );
			if( $key !== false )
			{
				$targets_arr[$i] = array_merge($targets_arr[$i], $phsa_maps_arr[$key]);
				unset($phsa_maps_arr[$key]);
			}
		}
		else
			$targets_arr[$i] = $empty_target_arr;
	}
	for( $i = 0 ; $i < $num_rows ; $i++ ) // This is the second loop, where we will add remaining phsa_maps (those that did not get unset in first loop) to any gaps in targets_arr
	{
		if( isset($targets_arr[$i]["m_concept_code"]) ) // this is already matched up
			continue;
		
		if( reset($phsa_maps_arr) === false ) // nothing left in $phsa_maps_arr
			$targets_arr[$i] = array_merge($targets_arr[$i], $empty_map_arr);
		else
		{
			$key = key($phsa_maps_arr);
			$targets_arr[$i] = array_merge($targets_arr[$i], $phsa_maps_arr[$key]);
			unset($phsa_maps_arr[$key]);
		}
	}
	return $num_rows;
}

function output_vocabulary_options($shee_vocab_array, $vocabulary)
{
	foreach( $shee_vocab_array as $sheet_vocab )
		echo "<option value='$sheet_vocab'" . ($sheet_vocab == $vocabulary ? " selected" : "") . ">$sheet_vocab</option>&";
}

function get_tr_bgcolor($mr_code, $map_code, $mr_status)
{
	if( $mr_status == "Absent in latest MR" )
		return "#FC8080";
		
	if($mr_code == "" && $map_code == "")
		return "#f0f0d0";
	
	if($mr_code == "" && $map_code != "")
		return "#c0d0f0";
	
	if($mr_code != "" && $map_code == "")
		return "#f0c0d0";
	
	if($mr_code == $map_code)
		return "#e0fae0";
	
	if($mr_code != $map_code)
		return "#a0e0e0";
	
	return "#ff0000";
}

function check_vocabulary_valid($pdo, $sheet_id, $vocabulary)
{
	$sql = "
			select count(*) as cnt
			from phsa_mr_sheet_vocabularies
			where sheet_id = $sheet_id and vocabulary = '$vocabulary'";
	$rs = $pdo->query($sql);
	if( !($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		die("Unknown error 56764");
	
	if( (string) $ft["cnt"] !== '1' )
		return false;
	
	return true;
}

function check_map_against_data($pdo, $map_id, $data_id)
{
	$sql = "select src_data_id from phsa_all_maps where id=$map_id";
	$rs = $pdo->query($sql);
	if( !($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
		die("Unknown error 56774564");
	
	if( $ft["src_data_id"] != $data_id )
		return false;
	
	return true;
}

function log_map_hx($pdo, $map_id, $action, $before_update_target_concept_id = 'null')
{
	global $_SESSION;
	
	$sql = "INSERT INTO phsa_all_maps_hx (map_id, src_data_id, 
											source_vocabulary_id_1, source_code_1, source_code_description_1, 
											source_vocabulary_id_2, source_code_2, source_code_description_2, 
											source_vocabulary_id_3, source_code_3, source_code_description_3, 
											source_vocabulary_id_4, source_code_4, source_code_description_4, 
											source_vocabulary_id_5, source_code_5, source_code_description_5, 
											source_vocabulary_id_6, source_code_6, source_code_description_6, 
											target_concept_id, date_time, username, change_action,
											before_update_target_concept_id) 
			select 
				id, src_data_id, 
				source_vocabulary_id_1, source_code_1, source_code_description_1, 
				source_vocabulary_id_2, source_code_2, source_code_description_2, 
				source_vocabulary_id_3, source_code_3, source_code_description_3, 
				source_vocabulary_id_4, source_code_4, source_code_description_4, 
				source_vocabulary_id_5, source_code_5, source_code_description_5, 
				source_vocabulary_id_6, source_code_6, source_code_description_6, 
				target_concept_id, now(), '" . $_SESSION["PHSA_UNAME"] . "', '$action',
				'$before_update_target_concept_id'
			from phsa_all_maps
			where id = $map_id;
			";
	$rs = $pdo->query($sql);
}

function log_map_hx_status($pdo, $src_data_id, $action)
{
	global $_SESSION;
	
	$sql = "INSERT INTO phsa_all_maps_hx (map_id, src_data_id, source_vocabulary_id_1, source_code_1,
											target_concept_id, target_concept_name, target_vocabulary_id, 
											date_time, username, change_action) 
			values
				(0, $src_data_id, 'Status Change', ' ',
				 0, ' ', ' ',
				 now(), '" . $_SESSION["PHSA_UNAME"] . "', '$action')
			";
	$rs = $pdo->query($sql);
}

function list_mr_create_tr($ft_data, $sources_arr, $targets_arr, $data_attr_arr)
{
	global $pdo, $r, $data_id, $td_bg, $num_of_srcs, $row, $One2M_index, $ft_sheet;
	
	$tr_html = "";
	$tr_bgcolor = get_tr_bgcolor($targets_arr[$r]["t_concept_code"], $targets_arr[$r]["m_concept_code"], $ft_data["mr_status"]);
	$retired_status_col = ($targets_arr[$r]["t_valid_end_date"] !="" && $targets_arr[$r]["t_valid_end_date"] <= date("Ymd")) ? "#ff0000" : "#000000";
	
	$tr_html .= "<tr class='highlight' bgcolor='$tr_bgcolor' id='tr_$data_id" . "_$One2M_index'>";
	
	if( $ft_data["exclude"] == "Out of Scope - Exclude" )
		$tr_html .= "<td bgcolor='white'><img src = 'x.png' width='20' border='0' /></td>";
	elseif( $ft_data["exclude"] == "Question - Pending" )
		$tr_html .= "<td bgcolor='white'><img src = 'question.png' width='22' border='0' /></td>";
	elseif( $ft_data["exclude"] == "SDO Submission - Send" )
		$tr_html .= "<td bgcolor='white'><img src = 'send.png' width='24' border='0' /></td>";
	elseif( $ft_data["exclude"] == "SDO Submitted - Pending" )
		$tr_html .= "<td bgcolor='white'><img src = 'submit.png' width='22' border='0' /></td>";
	else
		$tr_html .= "<td bgcolor='white'><img src = 'check.png' width='22' border='0' /></td>";
	
	$tr_html .= "<td style='background-color:$td_bg;'><a href='javascript:void(0)' onclick='open_mr_edit($data_id)' style='color:#000080; text-decoration:underline; font-weight:bold;'>$row</a></td>";
	$tr_html .= "<td style='background-color:$td_bg;'>$One2M_index</td>";
	for( $i = 1 ; $i <= $num_of_srcs ; $i++ ) ## assumption: $sources_arr will have the same number of elements
	{
		$tr_html .= "<td>" . $sources_arr[$i]["code"] . "</td>";
		
		############# special case - Event: showing list of results in tooltip, and the opposite for RESULTS
		if( $ft_sheet["name"] == "Event" || $ft_sheet["name"] == "Result (Cerner Code)" || $ft_sheet["name"] == "Result (Nomenclature)" ) 
		{
			if( $ft_sheet["name"] == "Event" ) 
			{
				$sql = "select s2_s.code, s2_s.description, d.id as er_data_id, dres.id as s_data_id from 
						phsa_mr_data d , phsa_mr_data_src_cd_desc s1_s , phsa_mr_data_src_cd_desc s2_s, phsa_mr_data dres , phsa_mr_data_src_cd_desc sres
						where 
						s1_s.data_id = d.id and
						s2_s.data_id = d.id and
						sres.data_id = dres.id and
						d.sheet_id in (18, 19) and
						dres.sheet_id in (16, 17) and
						s1_s.spot=1 and
						s2_s.spot=2 and
						sres.code = s2_s.code and
						s1_s.code = '" . $sources_arr[$i]["code"] . "'
						limit 30";
			}
			elseif( $ft_sheet["name"] == "Result (Cerner Code)" ) 
			{
				$sql = "select s1_s.code, s1_s.description, d.id as er_data_id, d_ev.id as s_data_id from 
						phsa_mr_data d , phsa_mr_data_src_cd_desc s1_s , phsa_mr_data_src_cd_desc s2_s, phsa_mr_data d_ev , phsa_mr_data_src_cd_desc s_ev
						where 
						s1_s.data_id = d.id and
						s2_s.data_id = d.id and
						s_ev.data_id = d_ev.id and
						d.sheet_id = 18 and
						d_ev.sheet_id = 15 and
						s1_s.spot=1 and
						s2_s.spot=2 and
						s_ev.code = s1_s.code and
						s2_s.code = '" . $sources_arr[$i]["code"] . "'
						limit 30";
			}
			elseif( $ft_sheet["name"] == "Result (Nomenclature)" ) 
			{
				$sql = "select s1_s.code, s1_s.description, d.id as er_data_id, d_ev.id as s_data_id from 
						phsa_mr_data d , phsa_mr_data_src_cd_desc s1_s , phsa_mr_data_src_cd_desc s2_s, phsa_mr_data d_ev , phsa_mr_data_src_cd_desc s_ev
						where 
						s1_s.data_id = d.id and
						s2_s.data_id = d.id and
						s_ev.data_id = d_ev.id and
						d.sheet_id = 19 and
						d_ev.sheet_id = 15 and
						s1_s.spot=1 and
						s2_s.spot=2 and
						s_ev.code = s1_s.code and
						s2_s.code = '" . $sources_arr[$i]["code"] . "'
						limit 30";
			}
			$rs_result = $pdo->query($sql);
			$results_list = "";
			while( ($ft_result = $rs_result->fetch(PDO::FETCH_ASSOC)) )
				$results_list .= $ft_result["description"] . "&nbsp;[" . $ft_result["code"] . "]&nbsp;<a href='javascript:void(0)' onclick='open_mr_edit(" . $ft_result["s_data_id"] . ")' style='color:#d0e0ff;'>(" . ($ft_sheet["name"] == "Event" ? "R" : "E") . ")</a>&nbsp;&nbsp;<a href='javascript:void(0)' onclick='open_mr_edit(" . $ft_result["er_data_id"] . ")' style='color:#d0e0ff;'>(ER)</a><br/>";
			
			$tr_html .= "<td><div class='tooltip'>" . $sources_arr[$i]["description"] . "<span class='tooltiptext'>$results_list</span></div></td>";
		}
		else
			$tr_html .= "<td>" . $sources_arr[$i]["description"] . "</td>";
		########################
	}
	for( $i = 0 ; $i < count($data_attr_arr) ; $i++ ) ## assumption: $sheet_attr_arr will have the same number of elements
		$tr_html .= "<td>" . $data_attr_arr[$i] . "</td>";
		
	$tr_html .= "<td><font color='$retired_status_col'>" . $targets_arr[$r]["t_concept_code"] . "</font></td>";
	$tr_html .= "<td>" . $targets_arr[$r]["t_concept_name"] . "</td>";
	$tr_html .= "<td>" . $targets_arr[$r]["t_domain_id"] . "</td>";
	$tr_html .= "<td>" . $targets_arr[$r]["t_vocabulary_id"] . "</td>";
	
	$tr_html .= "<td>" . $ft_data["map_source"] . "</td>";
	$tr_html .= "<td>" . $ft_data["total_count"] . "</td>";

	$tr_html .= "<td>" . $targets_arr[$r]["m_concept_code"] . "</td>";
	$tr_html .= "<td>" . $targets_arr[$r]["m_concept_name"] . "</td>";
	$tr_html .= "<td>" . $targets_arr[$r]["m_domain_id"] . "</td>";
	$tr_html .= "<td>" . $targets_arr[$r]["m_vocabulary_id"] . "</td>";
	
	$tr_html .= "</tr>";
	
	return $tr_html;
}

function create_sidenav_form()
{
	global $pdo, $sheet_id, $start, $vocab, $domain;
	
	?>
	<a href="javascript:void(0)" class="closebtn" onclick="closeFilterNav()">&times;</a>
	<div style='margin:20px;'>
	<div style='font-size:35px; color:#ffe7b7; font-weight:bold;'>Filter</div><br/><br/>
	<form action='#' method='get'>
	<input type='hidden' name='sheet' value='<?php echo $sheet_id; ?>' />
	<input type='hidden' name='start' value='0' />
	<table style='font-size:12px; color:#dfdfef; font-weight:bold; ' cellpadding='7'>
	<tr>
		<td>Term Status:</td>
		<td>
			<select name='_status' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<option value='i'<?php if(isset($_GET["_status"]) && $_GET["_status"] == "i") echo " selected"; ?>>Included Only</option>
				<option value='e'<?php if(isset($_GET["_status"]) && $_GET["_status"] == "e") echo " selected"; ?>>Excluded Only</option>
				<option value='q'<?php if(isset($_GET["_status"]) && $_GET["_status"] == "q") echo " selected"; ?>>Questions Only</option>
				<option value='s'<?php if(isset($_GET["_status"]) && $_GET["_status"] == "s") echo " selected"; ?>>To Submit to SDO Only</option>
				<option value='p'<?php if(isset($_GET["_status"]) && $_GET["_status"] == "p") echo " selected"; ?>>Submitted to SDO Only</option>
			</select>
		</td>
	</tr>
	<tr>
		<td>Mapped Status:</td>
		<td>
			<select name='_mapped' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<option value='m'<?php if(isset($_GET["_mapped"]) && $_GET["_mapped"] == "m") echo " selected"; ?>>All Mapped</option>
				<option value='a'<?php if(isset($_GET["_mapped"]) && $_GET["_mapped"] == "a") echo " selected"; ?>>Auto-Map Only</option>
				<option value='n'<?php if(isset($_GET["_mapped"]) && $_GET["_mapped"] == "n") echo " selected"; ?>>Not Mapped</option>
			</select>
		</td>
	</tr>
	<tr>
		<td>Retired Status:</td>
		<td>
			<select name='_retired' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<option value='r'<?php if(isset($_GET["_retired"]) && $_GET["_retired"] == "r") echo " selected"; ?>>Retired Only</option>
			</select>
		</td>
	</tr>
	<tr>
		<td>Vocabulary:</td>
		<td>
			<select name='_vocab' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<?php
				$sql = "select vocabulary from phsa_mr_sheet_vocabularies where sheet_id = $sheet_id order by vocabulary";
				echo $sql;
				$rs = $pdo->query($sql);
				while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
					echo "<option value='" . $ft["vocabulary"] . "'" . ((isset($vocab) && $vocab == $ft["vocabulary"]) ? " selected" : "") . ">" . $ft["vocabulary"] . "</option>";
				?>
			</select>
		</td>
	</tr>
	<tr>
		<td>Domain:</td>
		<td>
			<select name='_domain' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<?php
				$sql = "select domain_id from phsa_mr_sheet_domains where sheet_id = $sheet_id order by domain_id";
				echo $sql;
				$rs = $pdo->query($sql);
				while( ($ft = $rs->fetch(PDO::FETCH_ASSOC)) )
					echo "<option value='" . $ft["domain_id"] . "'" . ((isset($domain) && $domain == $ft["domain_id"]) ? " selected" : "") . ">" . $ft["domain_id"] . "</option>";
				?>
			</select>
		</td>
	</tr>
	<tr>
		<td colspan='2' align='right'><br/><br/><input type='submit' name='submit' value=' Filter ' /></td>
	</tr>
	</table>
	</form>
	</div>
	<?php
}


function create_sidenav_form_domain()
{
	global $pdo, $domain_id;
	
	?>
	<a href="javascript:void(0)" class="closebtn" onclick="closeFilterNav()">&times;</a>
	<div style='margin:20px;'>
	<div style='font-size:35px; color:#ffe7b7; font-weight:bold;'>Filter</div><br/><br/>
	<form action='#' method='get'>
	<input type='hidden' name='domain' value='<?php echo $domain_id; ?>' />
	<table style='font-size:12px; color:#dfdfef; font-weight:bold; ' cellpadding='7'>
	<tr>
		<td>Map Source:</td>
		<td>
			<select name='source' style='width: 160px;'>
				<option value='all'>No Filter</option>
				<option value='a'<?php if(isset($_GET["source"]) && $_GET["source"] == "a") echo " selected"; ?>>Auto-Map Only</option>
				<option value='u'<?php if(isset($_GET["source"]) && $_GET["source"] == "u") echo " selected"; ?>>User-Map Only</option>
			</select>
		</td>
	</tr>
	<tr>
		<td colspan='2' align='right'><br/><br/><input type='submit' name='submit' value=' Filter ' /></td>
	</tr>
	</table>
	</form>
	</div>
	<?php
}

?>