<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../auth.php";

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8");

/* Solo usuarios autenticados pueden buscar */
if (!is_logged_in()) {
  echo json_encode(['results'=>[]]);
  exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$sql = "SELECT id, codigo, nombre
        FROM caminos
        WHERE activo=1";
$params = [];
$types  = "";

if ($q !== "") {
  $sql .= " AND (codigo LIKE ? OR nombre LIKE ?)";
  $like = "%{$q}%";
  $params = [$like, $like];
  $types  = "ss";
}
$sql .= " ORDER BY nombre LIMIT 20";

$stmt = $conn->prepare($sql);
if ($types) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$res = $stmt->get_result();

$items = [];
while ($r = $res->fetch_assoc()) {
  $items[] = [
    "id"   => $r['id'],
    "text" => $r['codigo']." - ".$r['nombre']
  ];
}

echo json_encode([
  "results" => $items,
  "pagination" => ["more" => false]
], JSON_UNESCAPED_UNICODE);
