<?php
/**
 * Vocabulary-refresh impact report.
 *
 * After each Athena vocabulary load (bin/load_vocab.php), lists every map in
 * phsa_all_maps whose target concept is no longer a valid CDM v5.4 target -
 * deprecated (invalid_reason set), no longer standard, or missing from the
 * current vocabulary - together with the standard replacement suggested by
 * the vocabulary's 'Concept replaced by' / 'Maps to' relationships.
 *
 * Per-row actions: one-click remap to the suggested concept (audited via
 * log_map_hx) or flag the source row as 'Question - Pending'.
 */
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();

verify_session();

##### Reviewer privilege required
if( !isset($_SESSION["PHSA_PRIV_REVIEW"]) || $_SESSION["PHSA_PRIV_REVIEW"] !== "1" )
{
	header("Location:unauthorized.html");
	die();
}

$pdo = new PDO(
	'mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB . ';charset=utf8mb4',
	$_MY_USER,
	$_MY_PASS,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$action_msg = "";

##### POST actions
if( count($_POST) )
{
	if( isset($_POST["do_remap"], $_POST["map_id"], $_POST["new_concept_id"])
		&& is_numeric($_POST["map_id"]) && is_numeric($_POST["new_concept_id"]) )
	{
		if( !isset($_SESSION["PHSA_PRIV_MAP"]) || $_SESSION["PHSA_PRIV_MAP"] !== "1" )
			die("Mapper privilege required for remapping.");

		$map_id         = (int) $_POST["map_id"];
		$new_concept_id = (int) $_POST["new_concept_id"];

		$stmt = $pdo->prepare(
			"select concept_id, concept_name, vocabulary_id, standard_concept, invalid_reason
			 from omop_concept where concept_id = ?"
		);
		$stmt->execute([$new_concept_id]);
		$new_concept = $stmt->fetch(PDO::FETCH_ASSOC);

		if( !is_valid_map_target($new_concept) )
			$action_msg = "<font color='red'>Replacement concept $new_concept_id is not a valid standard target.</font>";
		else
		{
			$stmt = $pdo->prepare("select target_concept_id from phsa_all_maps where id = ?");
			$stmt->execute([$map_id]);
			$before_id = $stmt->fetchColumn();

			if( $before_id === false )
				$action_msg = "<font color='red'>Map $map_id no longer exists.</font>";
			else
			{
				$stmt = $pdo->prepare(
					"update phsa_all_maps
					 set target_concept_id = ?, target_concept_name = ?, target_vocabulary_id = ?
					 where id = ?"
				);
				$stmt->execute([$new_concept["concept_id"], $new_concept["concept_name"], $new_concept["vocabulary_id"], $map_id]);
				log_map_hx($pdo, $map_id, "Update", $before_id);
				$action_msg = "Map $map_id remapped to '" . htmlspecialchars($new_concept["concept_name"], ENT_QUOTES) . "'.";
			}
		}
	}
	elseif( isset($_POST["do_question"], $_POST["data_id"]) && is_numeric($_POST["data_id"]) )
	{
		$q_data_id = (int) $_POST["data_id"];
		$stmt = $pdo->prepare(
			"update phsa_mr_data set exclude = 'Question - Pending', updated_by = ?, updated_dt = now() where id = ? limit 1"
		);
		$stmt->execute([$_SESSION["PHSA_UNAME"], $q_data_id]);
		log_map_hx_status($pdo, $q_data_id, "Question");
		$action_msg = "Source row $q_data_id flagged as 'Question - Pending'.";
	}
}

##### Current vocabulary release
$vocab_release = "";
try {
	$vocab_release = (string) $pdo->query("select athena_release from comet_vocab_meta order by id desc limit 1")->fetchColumn();
} catch (PDOException $e) {
}

##### Stale maps: deprecated / non-standard / missing targets
$sql = "select m.id as map_id, m.src_data_id, m.source_vocabulary_id_1, m.source_code_1, m.source_code_description_1,
			   m.target_concept_id, m.target_concept_name, m.target_vocabulary_id,
			   c.concept_id as cur_concept_id, c.concept_name as cur_concept_name,
			   c.standard_concept, c.invalid_reason, c.valid_end_date
		from phsa_all_maps m
		left join omop_concept c on c.concept_id = m.target_concept_id
		where c.concept_id is null
		   or c.invalid_reason is not null
		   or c.standard_concept is null
		   or c.standard_concept <> 'S'
		order by m.src_data_id, m.id";
$stale_maps = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

##### Stale MR-sheet suggested targets (informational)
$sql = "select count(*) as cnt
		from phsa_mr_data_targets t
		left join omop_concept c on c.concept_id = t.concept_id
		where c.concept_id is null or c.invalid_reason is not null or c.standard_concept is null or c.standard_concept <> 'S'";
$stale_targets_cnt = (int) $pdo->query($sql)->fetchColumn();

?>
<html>
<head>
<title>COMET - Vocabulary Impact Report</title>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.3/jquery.min.js"></script>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css">
</head>
<body style='font-family:Arial; padding:20px; background-color:#FFFAF2;'>

<div><a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a></div>
<h2>Vocabulary Impact Report</h2>
<div style='color:#875503;'>Current vocabulary release: <b><?php echo htmlspecialchars($vocab_release ?: "none loaded", ENT_QUOTES); ?></b></div>

<?php if( $action_msg ): ?>
<div style='margin:10px 0; font-size:16px; color:#20a020;'><?php echo $action_msg; ?></div>
<?php endif; ?>

<p>
<b><?php echo count($stale_maps); ?></b> map(s) point at concepts that are deprecated, non-standard, or missing in the current vocabulary.
<b><?php echo $stale_targets_cnt; ?></b> MR-sheet suggested target(s) are also stale (informational &mdash; they come from the Cerner MappingReport and are corrected on the next sheet import).
</p>

<?php if( count($stale_maps) ): ?>
<table border='1' cellspacing='0' cellpadding='4' style='font-size:10pt; background-color:white;'>
<tr style='color:white; background-color:#202080;'>
	<td>Map</td><td>Source Code</td><td>Source Description</td>
	<td>Current Target</td><td>Problem</td><td>Suggested Replacement</td><td>Actions</td>
</tr>
<?php
foreach( $stale_maps as $row )
{
	if( is_null($row["cur_concept_id"]) )
		$problem = "<font color='red'>missing from vocabulary</font>";
	elseif( !is_null($row["invalid_reason"]) )
		$problem = "<font color='red'>deprecated (invalid_reason = " . htmlspecialchars($row["invalid_reason"], ENT_QUOTES) . ")</font>";
	else
		$problem = "<font color='#b06000'>no longer standard</font>";

	$replacements = is_null($row["cur_concept_id"]) ? [] : get_standard_replacements($pdo, (int) $row["cur_concept_id"]);

	echo "<tr>";
	echo "<td><a href='javascript:void(0)' onclick='open_mr_edit(" . (int) $row["src_data_id"] . ")' style='color:#000080; text-decoration:underline;'>" . (int) $row["map_id"] . "</a></td>";
	echo "<td>" . htmlspecialchars((string) $row["source_code_1"], ENT_QUOTES) . "</td>";
	echo "<td>" . htmlspecialchars((string) $row["source_code_description_1"], ENT_QUOTES) . "</td>";
	echo "<td>" . htmlspecialchars((string) $row["target_concept_name"], ENT_QUOTES) . " (" . htmlspecialchars((string) $row["target_concept_id"], ENT_QUOTES) . ", " . htmlspecialchars((string) $row["target_vocabulary_id"], ENT_QUOTES) . ")</td>";
	echo "<td>$problem</td>";

	echo "<td>";
	if( count($replacements) )
	{
		foreach( $replacements as $alt )
			echo htmlspecialchars($alt["concept_name"], ENT_QUOTES) . " (" . htmlspecialchars($alt["concept_code"], ENT_QUOTES) . ", " . htmlspecialchars($alt["vocabulary_id"], ENT_QUOTES) . ")<br/>";
	}
	else
		echo "<i>none found</i>";
	echo "</td>";

	echo "<td>";
	foreach( $replacements as $alt )
	{
		echo "<form method='post' action='vocab_impact.php' style='display:inline; margin-right:6px;'>";
		echo "<input type='hidden' name='do_remap' value='1' />";
		echo "<input type='hidden' name='map_id' value='" . (int) $row["map_id"] . "' />";
		echo "<input type='hidden' name='new_concept_id' value='" . (int) $alt["concept_id"] . "' />";
		echo "<input type='submit' value='Remap to " . htmlspecialchars($alt["concept_code"], ENT_QUOTES) . "' onclick=\"return confirm('Remap this map to " . htmlspecialchars(addslashes($alt["concept_name"]), ENT_QUOTES) . "?');\" />";
		echo "</form>";
	}
	echo "<form method='post' action='vocab_impact.php' style='display:inline;'>";
	echo "<input type='hidden' name='do_question' value='1' />";
	echo "<input type='hidden' name='data_id' value='" . (int) $row["src_data_id"] . "' />";
	echo "<input type='submit' value='Send to Question' />";
	echo "</form>";
	echo "</td>";
	echo "</tr>";
}
?>
</table>
<?php else: ?>
<p style='color:#20a020; font-size:16px;'>All maps point at standard, valid concepts in the current vocabulary. Nothing to do.</p>
<?php endif; ?>

<div id="mr_edit_main_div" class="overlay">
  <a href="javascript:void(0)" class="closebtn" onclick="close_mr_edit()">&times;</a>
  <div id="mr_edit_content_div" class="overlay-content">
  </div>
</div>

<script src="comet_ops.js"></script>
</body>
</html>
