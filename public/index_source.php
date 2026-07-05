<?php
require_once("db.php");
require_once("common.php");
my_session_start();
verify_session();

$pdo = new PDO(
	'mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB . ';charset=utf8mb4',
	$_MY_USER,
	$_MY_PASS,
	[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$is_admin = isset($_SESSION["PHSA_PRIV_ADMIN"]) && $_SESSION["PHSA_PRIV_ADMIN"] === "1";
$msg = "";

##### Admin: recompute the denormalized phsa_mr_sheets counters from ground truth
if( $is_admin && isset($_POST["recompute"]) )
{
	$pdo->exec(
		"update phsa_mr_sheets s
		 left join (
			select d.sheet_id,
				count(*)                                                       as num_items,
				sum(ifnull(d.total_count,0))                                   as total_count,
				sum(d.exclude = 'Out of Scope - Exclude')                      as excluded_num,
				sum(if(d.exclude = 'Out of Scope - Exclude', ifnull(d.total_count,0), 0)) as excluded_count,
				sum(if(ifnull(d.exclude,'')='', ifnull(d.total_count,0), 0))   as total_mappable_count,
				sum(ifnull(d.exclude,'')='' and mp.src_data_id is not null)    as mapped_total,
				sum(if(ifnull(d.exclude,'')='' and mp.src_data_id is not null, ifnull(d.total_count,0), 0)) as mapped_total_by_count,
				sum(ifnull(d.exclude,'')='' and mp.src_data_id is not null and d.map_source='Auto') as mapped_auto,
				sum(if(ifnull(d.exclude,'')='' and mp.src_data_id is not null and d.map_source='Auto', ifnull(d.total_count,0), 0)) as mapped_auto_by_count
			from phsa_mr_data d
			left join (select distinct src_data_id from phsa_all_maps) mp on mp.src_data_id = d.id
			group by d.sheet_id
		 ) agg on agg.sheet_id = s.id
		 set s.num_items            = ifnull(agg.num_items, 0),
			 s.total_count          = ifnull(agg.total_count, 0),
			 s.excluded_num         = ifnull(agg.excluded_num, 0),
			 s.excluded_count       = ifnull(agg.excluded_count, 0),
			 s.total_mappable_count = ifnull(agg.total_mappable_count, 0),
			 s.mapped_total         = ifnull(agg.mapped_total, 0),
			 s.mapped_total_by_count= ifnull(agg.mapped_total_by_count, 0),
			 s.mapped_auto          = ifnull(agg.mapped_auto, 0),
			 s.mapped_auto_by_count = ifnull(agg.mapped_auto_by_count, 0),
			 s.mapped_percent       = if(ifnull(agg.num_items,0) = 0, 0, round(ifnull(agg.mapped_total,0) / agg.num_items * 100))"
	);
	$msg = "Sheet counters recomputed from current data.";
}

##### Live per-sheet progress (computed, not read from the drift-prone counters)
$rows = $pdo->query(
	"select s.id, s.name,
		count(d.id)                                                    as num_items,
		sum(d.exclude = 'Out of Scope - Exclude')                      as excluded,
		sum(d.exclude = 'Question - Pending')                          as question,
		sum(d.exclude in ('SDO Submission - Send','SDO Submitted - Pending')) as sdo,
		sum(ifnull(d.exclude,'')='' and mp.src_data_id is not null)    as mapped,
		sum(ifnull(d.exclude,'')='' and mp.src_data_id is null)        as unmapped,
		sum(ifnull(d.total_count,0))                                   as total_vol,
		sum(if(ifnull(d.exclude,'')='' and mp.src_data_id is not null, ifnull(d.total_count,0), 0)) as mapped_vol
	 from phsa_mr_sheets s
	 join phsa_mr_data d on d.sheet_id = s.id
	 left join (select distinct src_data_id from phsa_all_maps) mp on mp.src_data_id = d.id
	 group by s.id, s.name
	 order by s.name"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<html>
<head>
<link rel="stylesheet" href="styles.css?v=1">
<link rel="icon" href="comet.png">
<title>COMET - Centralized Online Mapping and Export Tool</title>
</head>

<body style='font-family:Arial; background-color:#e0f0ff;'>

<div style="font-size:22px;cursor:pointer; padding:20px 50px;"><a href='index.php'><img src='home.png' width='50' height='50' border='0' /></a></div>

<?php if( $msg ): ?>
<div align='center' style='color:#20a020; font-size:16px; margin-bottom:10px;'><?php echo htmlspecialchars($msg, ENT_QUOTES); ?></div>
<?php endif; ?>

<table border='0' align='center'>
<tr><td align='center' valign='top'>
	<table width='780px' style='height:100%' border='0' cellspacing='1'>
		<tr bgcolor='#78c8ff'><td colspan='4' align='center' style='font-family:Arial; font-size:30px; padding:8px;'>Source Data by Cerner Area</td></tr>
		<tr bgcolor='#a8d8ff' style='font-size:12px; font-weight:bold;'>
			<td style='padding:4px;'>Cerner Area</td>
			<td align='center'>Progress (by item)</td>
			<td align='center'>Mapped / Items</td>
			<td align='center'>Status</td>
		</tr>
		<?php
		foreach( $rows as $ft )
		{
			$num      = (int) $ft["num_items"];
			$mapped   = (int) $ft["mapped"];
			$unmapped = (int) $ft["unmapped"];
			$excluded = (int) $ft["excluded"];
			$question = (int) $ft["question"];
			$sdo      = (int) $ft["sdo"];
			$pct      = $num > 0 ? round($mapped / $num * 100) : 0;

			// stacked bar: mapped (green) / open work (amber) / excluded (grey)
			$mapped_w   = $num > 0 ? round($mapped / $num * 100) : 0;
			$open_w     = $num > 0 ? round(($unmapped + $question) / $num * 100) : 0;
			$excl_w     = max(0, 100 - $mapped_w - $open_w);

			echo "<tr style='background-color:#f4faff;'>";
			echo "<td class='source' style='padding:4px;'><a href='list_mr.php?sheet=" . (int) $ft["id"] . "&start=0' class='domain'>" . htmlspecialchars($ft["name"], ENT_QUOTES) . "</a></td>";

			echo "<td style='padding:4px;'>";
			echo "<div style='width:220px; background:#e0e0e0; border:1px solid #b0b0b0; height:16px; display:flex; font-size:0;'>";
			echo "<div title='Mapped: $mapped' style='width:{$mapped_w}%; background:#4caf50; height:16px;'></div>";
			echo "<div title='Open (unmapped/questions): " . ($unmapped + $question) . "' style='width:{$open_w}%; background:#ffb74d; height:16px;'></div>";
			echo "<div title='Excluded: $excluded' style='width:{$excl_w}%; background:#b0b0b0; height:16px;'></div>";
			echo "</div></td>";

			echo "<td align='center' style='padding:4px; font-size:13px;'>$mapped / $num<br/><b>$pct%</b></td>";

			echo "<td align='center' style='padding:4px; font-size:11px;'>";
			echo "<span style='color:#2e7d32;'>&#9632;</span> $mapped mapped &nbsp; ";
			echo "<span style='color:#ef6c00;'>&#9632;</span> $unmapped open";
			if( $question ) echo " &nbsp; $question ?";
			if( $sdo )      echo " &nbsp; $sdo SDO";
			if( $excluded ) echo " &nbsp; <span style='color:#808080;'>&#9632;</span> $excluded excl";
			echo "</td>";
			echo "</tr>";
		}
		?>
	</table>

	<?php if( $is_admin ): ?>
	<div style='margin-top:15px;' align='right'>
		<form method='post' action='index_source.php' style='display:inline;'>
			<input type='hidden' name='recompute' value='1' />
			<input type='submit' value='Recompute sheet counters'
				onclick="return confirm('Rewrite the stored phsa_mr_sheets counters from current data?');" />
		</form>
	</div>
	<?php endif; ?>
</td></tr>
</table>

</body>
</html>
