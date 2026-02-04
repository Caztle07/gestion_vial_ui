<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

// Login
require_login();

// Permisos
if (!can_edit("proyectos") && !can_edit("proyectos_ver")) {
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(["error" => "Sin permisos"]);
  exit;
}

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// filtros
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';

// base: activos (igual que tu lógica)
$where = "p.estado <> 0";
$params = [];
$types = "";

// filtro estado
if ($estado !== '' && in_array($estado, ['1','2','3','4'], true)) {
  $where .= " AND p.estado = ?";
  $params[] = $estado;
  $types .= "s";
}

// filtro texto
if ($q !== '') {
  $where .= " AND (
    p.nombre LIKE ? OR
    i.nombre LIKE ? OR
    i.codigo LIKE ? OR
    d.nombre LIKE ? OR
    e.nombre LIKE ?
  )";
  $like = "%$q%";
  array_push($params, $like, $like, $like, $like, $like);
  $types .= "sssss";
}

$sql = "
  SELECT
    p.id,
    p.nombre,
    p.estado,
    p.fecha_inicio,
    p.fecha_fin,
    i.codigo,
    i.nombre AS camino,
    d.nombre AS distrito,
    e.nombre AS encargado
  FROM proyectos p
  LEFT JOIN caminos i ON i.id = p.inventario_id
  LEFT JOIN distritos d ON d.id = p.distrito_id
  LEFT JOIN encargados e ON e.id = p.encargado_id
  WHERE $where
  ORDER BY p.id DESC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
  http_response_code(500);
  echo json_encode(["error" => "Error preparando consulta"]);
  exit;
}

if ($types !== '') {
  $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$res = $stmt->get_result();

$events = [];

while ($row = $res->fetch_assoc()) {
  $id = (int)$row['id'];

  $inicio = $row['fecha_inicio'] ?? '';
  $fin = $row['fecha_fin'] ?? '';

  // regla:
  // - si no hay inicio, no se muestra
  if (empty($inicio)) continue;

  // si hay fin: incluir último día completo => end exclusivo = fin + 1 día
  $endExclusivo = null;
  if (!empty($fin)) {
    $dt = new DateTime($fin);
    $dt->modify('+1 day');
    $endExclusivo = $dt->format('Y-m-d');
  }

  $estadoVal = (string)($row['estado'] ?? '');

  // colores por estado
$color = "#6c757d"; // default
if ($estadoVal === "1") $color = "#0d6efd"; // pendiente
if ($estadoVal === "2") $color = "#fd7e14"; // en ejecución
if ($estadoVal === "3") $color = "#198754"; // finalizado
if ($estadoVal === "4") $color = "#6c757d"; // suspendido (gris)


  $codigo = trim(($row['codigo'] ?? ''));

  // título visible
  $titulo = $codigo !== ''
    ? ($codigo . " - " . ($row['nombre'] ?? 'Proyecto'))
    : ($row['nombre'] ?? 'Proyecto');

  // descripción (aparece en “more info” si luego haces modal)
  $extra = [
    "distrito" => $row["distrito"] ?? "",
    "encargado" => $row["encargado"] ?? ""
  ];

  $event = [
    "id" => $id,
    "title" => $titulo,
    "start" => $inicio,
    "url" => "proyecto_visor.php?id=" . $id,
    "color" => $color,
    "extendedProps" => $extra
  ];

  if ($endExclusivo) {
    $event["end"] = $endExclusivo;
  }

  $events[] = $event;
}

$stmt->close();

echo json_encode($events, JSON_UNESCAPED_UNICODE);
