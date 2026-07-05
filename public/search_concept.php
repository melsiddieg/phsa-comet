<?php
/**
 * AJAX concept search over the OMOP vocabulary (name + synonyms + exact code).
 *
 * POST params:
 *   kw            search term or concept code (required)
 *   sheet_id      numeric sheet id - supplies the default vocabulary filter (required)
 *   domain        domain_id filter, or "all" (default "all")
 *   vocab         vocabulary_id filter, "sheet" = sheet's allowed vocabularies (default), or "all"
 *   standard_only "1" (default) = only standard concepts
 *   valid_only    "1" (default) = only concepts with no invalid_reason
 *
 * Returns JSON: { vocab_release, results: [ {concept_id, concept_name, concept_code,
 *   domain_id, vocabulary_id, concept_class_id, standard_concept, invalid_reason,
 *   valid_end_date, score, matched_on, synonym_name} ] }
 */

require_once("db.php");
require_once("common.php");
my_session_start();

header('Content-Type: application/json; charset=utf-8');

if (!verify_session(false)) {
    http_response_code(401);
    echo json_encode(["error" => "Session expired"]);
    exit;
}

if (!isset($_POST["kw"]) || trim($_POST["kw"]) === "" || !isset($_POST["sheet_id"]) || !is_numeric($_POST["sheet_id"])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing kw or sheet_id"]);
    exit;
}

$kw            = trim($_POST["kw"]);
$sheet_id      = (int) $_POST["sheet_id"];
$domain        = isset($_POST["domain"]) ? trim($_POST["domain"]) : "all";
$vocab         = isset($_POST["vocab"]) ? trim($_POST["vocab"]) : "sheet";
$standard_only = !isset($_POST["standard_only"]) || $_POST["standard_only"] === "1";
$valid_only    = !isset($_POST["valid_only"]) || $_POST["valid_only"] === "1";

$pdo = new PDO(
    'mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB . ';charset=utf8mb4',
    $_MY_USER,
    $_MY_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

### Resolve the vocabulary filter
$vocab_list = [];
if ($vocab === "sheet") {
    $stmt = $pdo->prepare("select vocabulary from phsa_mr_sheet_vocabularies where sheet_id = ?");
    $stmt->execute([$sheet_id]);
    $vocab_list = $stmt->fetchAll(PDO::FETCH_COLUMN);
} elseif ($vocab !== "all") {
    $vocab_list = [$vocab];
}

### Shared WHERE tail + params
function filter_sql(array $vocab_list, string $domain, bool $standard_only, bool $valid_only, string $alias, array &$params): string
{
    $sql = "";
    if (count($vocab_list)) {
        $sql .= " and $alias.vocabulary_id in (" . implode(',', array_fill(0, count($vocab_list), '?')) . ")";
        array_push($params, ...$vocab_list);
    }
    if ($domain !== "all" && $domain !== "") {
        $sql .= " and $alias.domain_id = ?";
        $params[] = $domain;
    }
    if ($standard_only) {
        $sql .= " and $alias.standard_concept = 'S'";
    }
    if ($valid_only) {
        $sql .= " and $alias.invalid_reason is null";
    }
    return $sql;
}

$select_cols = "c.concept_id, c.concept_name, c.concept_code, c.domain_id, c.vocabulary_id,
                c.concept_class_id, c.standard_concept, c.invalid_reason, c.valid_end_date";

$results = []; // concept_id => row (best score wins)

function merge_result(array &$results, array $row, float $score, string $matched_on, string $synonym_name = ""): void
{
    $id = $row["concept_id"];
    if (!isset($results[$id]) || $score > $results[$id]["score"]) {
        $row["score"]        = round($score, 3);
        $row["matched_on"]   = $matched_on;
        $row["synonym_name"] = $synonym_name;
        $results[$id] = $row;
    }
}

### 1) Exact concept_code match - always ranked first
$params = [$kw];
$sql = "select $select_cols from omop_concept c where c.concept_code = ?"
     . filter_sql($vocab_list, $domain, $standard_only, $valid_only, "c", $params);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
while (($ft = $stmt->fetch(PDO::FETCH_ASSOC))) {
    merge_result($results, $ft, 1000.0, "code");
}

### 2) Fulltext over concept names
$params = [$kw, $kw];
$sql = "select $select_cols, match(c.concept_name) against (?) as ft_score
        from omop_concept c
        where match(c.concept_name) against (?)"
     . filter_sql($vocab_list, $domain, $standard_only, $valid_only, "c", $params)
     . " order by ft_score desc limit 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
while (($ft = $stmt->fetch(PDO::FETCH_ASSOC))) {
    $score = (float) $ft["ft_score"];
    unset($ft["ft_score"]);
    merge_result($results, $ft, $score, "name");
}

### 3) Fulltext over synonyms
$params = [$kw, $kw];
$sql = "select $select_cols, s.concept_synonym_name, match(s.concept_synonym_name) against (?) as ft_score
        from omop_concept_synonym s
        join omop_concept c on c.concept_id = s.concept_id
        where match(s.concept_synonym_name) against (?)"
     . filter_sql($vocab_list, $domain, $standard_only, $valid_only, "c", $params)
     . " order by ft_score desc limit 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
while (($ft = $stmt->fetch(PDO::FETCH_ASSOC))) {
    $score = (float) $ft["ft_score"];
    $syn   = $ft["concept_synonym_name"];
    unset($ft["ft_score"], $ft["concept_synonym_name"]);
    merge_result($results, $ft, $score, "synonym", $syn);
}

usort($results, fn($a, $b) => $b["score"] <=> $a["score"]);
$results = array_slice(array_values($results), 0, 50);

### Current vocabulary release for display
$vocab_release = "";
try {
    $rs = $pdo->query("select athena_release from comet_vocab_meta order by id desc limit 1");
    $vocab_release = (string) $rs->fetchColumn();
} catch (PDOException $e) {
    // table missing - leave blank
}

echo json_encode(["vocab_release" => $vocab_release, "results" => $results]);
