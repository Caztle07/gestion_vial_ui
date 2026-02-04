<?php
session_start();
require_once "../config/db.php";
require_once "../auth.php";

if (!isset($_SESSION['id'])) { http_response_code(401); exit; }

$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
$puedeVer = ($rol === "admin" || $rol === "vista" || $rol === "visor");
if (!$puedeVer) { http_response_code(403); exit; }

mysqli_set_charset($conn, "utf8");

function getDataBasePath(): ?string {
  $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui
  $root = realpath($rootGuess);
  if ($root === false) $root = $rootGuess;

  $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
  $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";

  if (!is_dir($data) || !is_dir($proyectos)) return null;
  return $data;
}

$cronica_id = (int)($_GET["cronica_id"] ?? 0);
$tipo = strtolower(trim((string)($_GET["tipo"] ?? "evidencia"))); // evidencia | adjunto
$file = trim((string)($_GET["file"] ?? ""));

if ($cronica_id <= 0 || $file === "") { http_response_code(400); exit; }

// sanitizar filename
$file = str_replace(["..", "/", "\\", "\0"], "", $file);

// obtener proyecto_id de la crónica
$stmt = $conn->prepare("SELECT proyecto_id FROM cronicas WHERE id=? LIMIT 1");
$stmt->bind_param("i", $cronica_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) { http_response_code(404); exit; }

$proyecto_id = (int)($row["proyecto_id"] ?? 0);
if ($proyecto_id <= 0) { http_response_code(404); exit; }

$baseData = getDataBasePath();
if (!$baseData) { http_response_code(500); exit; }

$baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $proyecto_id;

$folder = "cronicas_img";
if ($tipo === "adjunto") $folder = "cronicas_adjuntos";

$path = $baseProyecto . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $file;

// validar existencia
if (!is_file($path)) { http_response_code(404); exit; }

// content-type básico por extensión
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = "application/octet-stream";
if ($ext === "jpg" || $ext === "jpeg") $mime = "image/jpeg";
if ($ext === "png") $mime = "image/png";
if ($ext === "gif") $mime = "image/gif";
if ($ext === "webp") $mime = "image/webp";

header("Content-Type: " . $mime);
header("Content-Length: " . filesize($path));
header("Cache-Control: private, max-age=86400");

readfile($path);
exit;
