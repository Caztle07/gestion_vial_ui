<?php
session_start();
require_once "../config/db.php";
require_once "../auth.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id'])) {
  echo json_encode(['success' => false, 'error' => 'No autenticado']);
  exit;
}

mysqli_set_charset($conn, "utf8");

// proyectos igual que tu display
$proyectos = [];
$q1 = $conn->query("
  SELECT 
    p.id, 
    COALESCE(p.nombre,'') AS nombre,
    CONCAT(
      COALESCE(cam.codigo, ''), 
      ' - ', 
      COALESCE(p.nombre, ''), 
      ' (', 
      COALESCE(e.nombre, ''), 
      ')'
    ) AS display_nombre
  FROM proyectos p
  LEFT JOIN caminos cam ON cam.id = p.inventario_id
  LEFT JOIN encargados e ON e.id = p.encargado_id
  WHERE p.estado <> '0'
  ORDER BY cam.codigo, p.nombre
");
while ($r = $q1->fetch_assoc()) $proyectos[] = $r;

// encargados / distritos / tipos
$encargados = [];
$q2 = $conn->query("SELECT id, nombre FROM encargados WHERE activo=1 ORDER BY nombre");
while ($r = $q2->fetch_assoc()) $encargados[] = $r;

$distritos = [];
$q3 = $conn->query("SELECT id, nombre FROM distritos WHERE activo=1 ORDER BY nombre");
while ($r = $q3->fetch_assoc()) $distritos[] = $r;

$tipos = [];
$q4 = $conn->query("SELECT id, nombre FROM tipos_cronica ORDER BY nombre");
while ($r = $q4->fetch_assoc()) $tipos[] = $r;

echo json_encode([
  'success' => true,
  'proyectos' => $proyectos,
  'encargados' => $encargados,
  'distritos' => $distritos,
  'tipos' => $tipos,
  'server_time' => date('c')
], JSON_UNESCAPED_UNICODE);
