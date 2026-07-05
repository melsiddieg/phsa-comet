<?php
/**
 * Create and list named, versioned map releases (portal_admin).
 *
 * "Create release" freezes every current map into comet_map_release_maps,
 * tagged with the release name and the current OMOP vocabulary vintage, so an
 * ETL run can pin to a reproducible set of maps. Exports are available live or
 * per-release via export_stcm.php.
 */
set_time_limit(0);
require_once("db.php");
require_once("common.php");
my_session_start();
verify_session();

##### Admin privilege required
if( !isset($_SESSION["PHSA_PRIV_ADMIN"]) || $_SESSION["PHSA_PRIV_ADMIN"] !== "1" )
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

$msg = "";

if( isset($_POST["create_release"]) )
{
	$name  = trim((string) ($_POST["release_name"] ?? ""));
	$notes = trim((string) ($_POST["release_notes"] ?? ""));

	if( $name === "" )
		$msg = "<font color='red'>Release name is required.</font>";
	else
	{
		$vocab_release = "";
		try {
			$vocab_release = (string) $pdo->query("select athena_release from comet_vocab_meta order by id desc limit 1")->fetchColumn();
		} catch (PDOException $e) {}

		try {
			$pdo->beginTransaction();

			$stmt = $pdo->prepare(
				"insert into comet_map_releases (name, vocab_release, notes, created_by, created_at, map_count)
				 values (?, ?, ?, ?, now(), 0)"
			);
			$stmt->execute([$name, $vocab_release, $notes, $_SESSION["PHSA_UNAME"]]);
			$release_id = (int) $pdo->lastInsertId();

			$stmt = $pdo->prepare(
				"insert into comet_map_release_maps
					(release_id, src_data_id, source_code, source_vocabulary_id, source_code_description,
					 target_concept_id, target_concept_name, target_vocabulary_id)
				 select ?, src_data_id, source_code_1, source_vocabulary_id_1, source_code_description_1,
					 target_concept_id, target_concept_name, target_vocabulary_id
				 from phsa_all_maps"
			);
			$stmt->execute([$release_id]);
			$count = $stmt->rowCount();

			$pdo->prepare("update comet_map_releases set map_count = ? where id = ?")->execute([$count, $release_id]);
			$pdo->commit();

			$msg = "Release &ldquo;" . htmlspecialchars($name, ENT_QUOTES) . "&rdquo; created with <b>$count</b> maps"
				 . ($vocab_release ? " (vocabulary " . htmlspecialchars($vocab_release, ENT_QUOTES) . ")" : "") . ".";
		} catch (PDOException $e) {
			if( $pdo->inTransaction() ) $pdo->rollBack();
			$msg = "<font color='red'>Could not create release &mdash; is the name unique?</font>";
		}
	}
}

$releases = $pdo->query(
	"select id, name, vocab_release, notes, created_by, created_at, map_count
	 from comet_map_releases order by id desc"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<html>
<head>
<title>COMET - Map Releases</title>
<link rel="icon" href="comet.png">
<link rel="stylesheet" href="styles.css">
</head>
<body style='font-family:Arial; padding:20px; background-color:#FFFAF2;'>

<div><a href='index.php'><img src='home.png' width='30' height='30' border='0' /></a></div>
<h2>Map Releases</h2>

<?php if( $msg ): ?>
<div style='margin:10px 0; font-size:16px; color:#20a020;'><?php echo $msg; ?></div>
<?php endif; ?>

<div style='background-color:#eef4ff; border:1px solid #a0b0d0; padding:15px; margin-bottom:15px;'>
	<form method='post' action='manage_releases.php'>
		<b>Create a new release</b> (freezes all current maps):<br/><br/>
		Name: <input type='text' name='release_name' size='30' maxlength='100' required />
		Notes: <input type='text' name='release_notes' size='40' maxlength='500' />
		<input type='hidden' name='create_release' value='1' />
		<input type='submit' value=' Create Release ' onclick="return confirm('Freeze all current maps into a new release?');" />
	</form>
</div>

<p><a href='export_stcm.php'>Download current live maps as STCM CSV</a></p>

<table border='1' cellspacing='0' cellpadding='5' style='font-size:11pt; background-color:white;'>
<tr style='color:white; background-color:#202080;'>
	<td>ID</td><td>Name</td><td>Vocabulary</td><td>Maps</td><td>Created By</td><td>Created</td><td>Notes</td><td>Export</td>
</tr>
<?php foreach( $releases as $r ): ?>
<tr>
	<td><?php echo (int) $r["id"]; ?></td>
	<td><?php echo htmlspecialchars($r["name"], ENT_QUOTES); ?></td>
	<td><?php echo htmlspecialchars((string) $r["vocab_release"], ENT_QUOTES); ?></td>
	<td><?php echo (int) $r["map_count"]; ?></td>
	<td><?php echo htmlspecialchars($r["created_by"], ENT_QUOTES); ?></td>
	<td><?php echo htmlspecialchars($r["created_at"], ENT_QUOTES); ?></td>
	<td><?php echo htmlspecialchars((string) $r["notes"], ENT_QUOTES); ?></td>
	<td><a href='export_stcm.php?release=<?php echo (int) $r["id"]; ?>'>STCM CSV</a></td>
</tr>
<?php endforeach; ?>
<?php if( !count($releases) ): ?>
<tr><td colspan='8'><i>No releases yet.</i></td></tr>
<?php endif; ?>
</table>

</body>
</html>
