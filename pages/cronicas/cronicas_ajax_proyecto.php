<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8");

function db_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
  return (string)($r['db'] ?? '');
}
function table_exists(mysqli $conn, string $table): bool {
  $db = db_name($conn);
  if ($db === '') return false;
  $stmt = $conn->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=? LIMIT 1");
  $stmt->bind_param("ss", $db, $table);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}
function column_exists(mysqli $conn, string $table, string $column): bool {
  $db = db_name($conn);
  if ($db === '') return false;
  $stmt = $conn->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    LIMIT 1
  ");
  $stmt->bind_param("sss", $db, $table, $column);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_row();
  $stmt->close();
  return $ok;
}

$proyecto_id = (int)($_GET['id'] ?? 0);
if ($proyecto_id <= 0) {
  echo json_encode(['error' => 'ID inválido']);
  exit;
}

$tieneCierre          = column_exists($conn, "proyectos", "cerrado");
$tieneDistritosPuente = table_exists($conn, "proyecto_distritos");

$tieneTiposPuente     = table_exists($conn, "proyecto_tipos");
$tieneTareasCatalogo  = table_exists($conn, "tareas_catalogo");
$tieneTiposCronica    = table_exists($conn, "tipos_cronica");

$tieneMetasCatalogo   = table_exists($conn, "metas_proyecto");
$tieneMetasPuente     = table_exists($conn, "proyecto_metas");
$tieneMetas           = $tieneMetasCatalogo && $tieneMetasPuente;

$selectCierre = $tieneCierre ? "COALESCE(p.cerrado,0) AS cerrado" : "0 AS cerrado";

// 1) Datos base del proyecto + modalidad
$stmt = $conn->prepare("
  SELECT
    p.id,
    p.encargado_id,
    p.distrito_id,
    $selectCierre,
    p.modalidad_id,
    COALESCE(m.nombre,'') AS modalidad_nombre
  FROM proyectos p
  LEFT JOIN modalidades m ON m.id = p.modalidad_id
  WHERE p.id = ?
  LIMIT 1
");
$stmt->bind_param("i", $proyecto_id);
$stmt->execute();
$proy = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proy) {
  echo json_encode(['error' => 'Proyecto no encontrado']);
  exit;
}

// 2) Distritos del proyecto (varios si existe puente)
$distritos = [];
if ($tieneDistritosPuente) {
  $stD = $conn->prepare("
    SELECT d.id, d.nombre
    FROM proyecto_distritos pd
    INNER JOIN distritos d ON d.id = pd.distrito_id
    WHERE pd.proyecto_id = ?
    ORDER BY d.nombre
  ");
  $stD->bind_param("i", $proyecto_id);
  $stD->execute();
  $rsD = $stD->get_result();
  while ($rsD && ($r = $rsD->fetch_assoc())) {
    $distritos[] = ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']];
  }
  $stD->close();
} else {
  // fallback viejo: distrito_id único
  $did = (int)($proy['distrito_id'] ?? 0);
  if ($did > 0) {
    $stD = $conn->prepare("SELECT id, nombre FROM distritos WHERE id=? LIMIT 1");
    $stD->bind_param("i", $did);
    $stD->execute();
    $r = $stD->get_result()->fetch_assoc();
    $stD->close();
    if ($r) $distritos[] = ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']];
  }
}

// 3) Metas del proyecto (reales) metas_proyecto + proyecto_metas
$metas = [];
$metas_txt = "";
if ($tieneMetas) {
  $stM = $conn->prepare("
    SELECT mp.id, mp.nombre
    FROM proyecto_metas pm
    INNER JOIN metas_proyecto mp ON mp.id = pm.meta_id
    WHERE pm.proyecto_id = ?
      AND (mp.activo = 1 OR mp.activo IS NULL)
    ORDER BY mp.nombre
  ");
  $stM->bind_param("i", $proyecto_id);
  $stM->execute();
  $rsM = $stM->get_result();
  $names = [];
  while ($rsM && ($r = $rsM->fetch_assoc())) {
    $metas[] = ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']];
    $names[] = (string)$r['nombre'];
  }
  $stM->close();
  $metas_txt = implode(" | ", $names);
}

// 4) Tipos permitidos del proyecto (para el select de tipos de crónica)
$tipos = [];
if ($tieneTiposPuente) {
  $tablaCatalogo = $tieneTareasCatalogo ? "tareas_catalogo" : ($tieneTiposCronica ? "tipos_cronica" : "");
  if ($tablaCatalogo !== "") {
    $stT = $conn->prepare("
      SELECT tc.id, tc.nombre
      FROM proyecto_tipos pt
      INNER JOIN {$tablaCatalogo} tc ON tc.id = pt.tipo_id
      WHERE pt.proyecto_id = ?
      ORDER BY tc.nombre
    ");
    $stT->bind_param("i", $proyecto_id);
    $stT->execute();
    $rsT = $stT->get_result();
    while ($rsT && ($r = $rsT->fetch_assoc())) {
      $tipos[] = ['id' => (int)$r['id'], 'nombre' => (string)$r['nombre']];
    }
    $stT->close();
  }
}

// Respuesta
echo json_encode([
  'id' => (int)$proy['id'],
  'encargado_id' => (int)($proy['encargado_id'] ?? 0),

  'cerrado' => (int)($proy['cerrado'] ?? 0),
  'proyecto_cerrado' => ((int)($proy['cerrado'] ?? 0) === 1),

  'modalidad_id' => (int)($proy['modalidad_id'] ?? 0),
  'modalidad_nombre' => (string)($proy['modalidad_nombre'] ?? ''),

  'distritos' => $distritos,

  'metas' => $metas,
  'metas_txt' => $metas_txt,

  'tipos' => $tipos
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
