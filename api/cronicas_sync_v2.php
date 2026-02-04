<?php
// /gestion_vial_ui/api/cronicas_sync_v2.php
session_start();
require_once "../config/db.php";
require_once "../auth.php";

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id'])) {
  echo json_encode(['success' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
  exit;
}

mysqli_set_charset($conn, "utf8");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ================================
// Helpers
// ================================
function getDataBasePath(): ?string {
  $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . "..";
  $root = realpath($rootGuess);
  if ($root === false) $root = $rootGuess;

  $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
  if (!is_dir($data)) @mkdir($data, 0775, true);

  $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";
  if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

  if (!is_dir($data) || !is_dir($proyectos)) return null;
  return $data;
}

function filesToLists(array $files): array {
  if (!isset($files["name"])) return ["name"=>[], "tmp_name"=>[], "error"=>[], "size"=>[]];

  $names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
  $tmps  = is_array($files["tmp_name"]) ? $files["tmp_name"] : [$files["tmp_name"]];
  $errs  = is_array($files["error"]) ? $files["error"] : [$files["error"]];
  $sizes = isset($files["size"])
    ? (is_array($files["size"]) ? $files["size"] : [$files["size"]])
    : [];

  return ["name"=>$names, "tmp_name"=>$tmps, "error"=>$errs, "size"=>$sizes];
}

function hasAnyFileName(array $names): bool {
  foreach ($names as $n) if (trim((string)$n) !== '') return true;
  return false;
}

function subirArchivos($files, string $destDir, array $permitidas, string $prefix = 'file_', ?int $maxBytes = null): array {
  $guardados = [];
  $errores   = [];
  $map       = [];

  if (!is_array($files)) return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];

  $L = filesToLists($files);
  $names = $L["name"];
  $tmps  = $L["tmp_name"];
  $errs  = $L["error"];
  $sizes = $L["size"];

  if (count($names) === 0 || !hasAnyFileName($names)) {
    return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
  }

  if (!is_dir($destDir)) {
    if (!mkdir($destDir, 0775, true)) {
      $errores[] = "No se pudo crear el directorio destino: $destDir";
      return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
    }
  }

  foreach ($names as $i => $name) {
    $name = (string)$name;
    $tmp  = (string)($tmps[$i] ?? '');
    $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
    $size = isset($sizes[$i]) ? (int)$sizes[$i] : null;

    if (trim($name) === '' || $err === UPLOAD_ERR_NO_FILE) continue;

    if ($err !== UPLOAD_ERR_OK) {
      $errores[] = "Error subiendo '$name' (código $err).";
      continue;
    }

    if ($maxBytes !== null && $size !== null && $size > $maxBytes) {
      $errores[] = "Archivo '$name' excede el tamaño permitido.";
      continue;
    }

    // fetch/FormData igual crea tmp, is_uploaded_file debería ser true,
    // pero dejamos tolerancia por compatibilidad.
    $tmpOk = false;
    if ($tmp !== '' && is_uploaded_file($tmp)) $tmpOk = true;
    elseif ($tmp !== '' && file_exists($tmp) && is_readable($tmp)) $tmpOk = true;

    if (!$tmpOk) {
      $errores[] = "Archivo temporal inválido para '$name'. tmp='$tmp'";
      continue;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $permitidas, true)) {
      $errores[] = "Extensión no permitida para '$name' (.$ext).";
      continue;
    }

    $baseName  = preg_replace("/[^A-Za-z0-9_\-\.]/", "_", pathinfo($name, PATHINFO_FILENAME));
    $seguro    = $prefix . uniqid('', true) . "_" . $baseName . "." . $ext;
    $rutaFinal = rtrim($destDir, "/\\") . DIRECTORY_SEPARATOR . $seguro;

    $moved = false;
    if (@move_uploaded_file($tmp, $rutaFinal)) $moved = true;
    elseif (@rename($tmp, $rutaFinal)) $moved = true;
    elseif (@copy($tmp, $rutaFinal)) { @unlink($tmp); $moved = true; }

    if ($moved) {
      $guardados[] = $seguro;
      $map[(string)$i] = $seguro;
    } else {
      $errores[] = "No se pudo mover '$name' a '$rutaFinal'.";
    }
  }

  return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
}

function pickFiles(array $names): ?array {
  foreach ($names as $k) {
    if (isset($_FILES[$k]) && is_array($_FILES[$k])) return $_FILES[$k];
    $k2 = $k . "[]";
    if (isset($_FILES[$k2]) && is_array($_FILES[$k2])) return $_FILES[$k2];
  }
  return null;
}

function countFilesKey(string $key): int {
  if (!isset($_FILES[$key])) return 0;
  $n = $_FILES[$key]['name'] ?? null;
  if ($n === null) return 0;
  if (is_array($n)) {
    $c = 0;
    foreach ($n as $x) if (trim((string)$x) !== '') $c++;
    return $c;
  }
  return trim((string)$n) !== '' ? 1 : 0;
}

function listFileNames(string $key): array {
  if (!isset($_FILES[$key]) || !isset($_FILES[$key]['name'])) return [];
  $n = $_FILES[$key]['name'];
  if (is_array($n)) return array_values(array_filter(array_map('strval', $n), fn($x)=> trim($x) !== ''));
  $s = trim((string)$n);
  return $s !== '' ? [$s] : [];
}

function listFileErrors(string $key): array {
  if (!isset($_FILES[$key]) || !isset($_FILES[$key]['error'])) return [];
  $e = $_FILES[$key]['error'];
  if (is_array($e)) return array_values($e);
  return [$e];
}

function listFileSizes(string $key): array {
  if (!isset($_FILES[$key]) || !isset($_FILES[$key]['size'])) return [];
  $s = $_FILES[$key]['size'];
  if (is_array($s)) return array_values($s);
  return [$s];
}

// ================================
// Permisos
// ================================
$uid = (int)($_SESSION['id'] ?? 0);
$rol = strtolower(trim((string)($_SESSION["rol"] ?? "vista")));
$puedeCrear = ($rol === "inspector");

if (!$puedeCrear) {
  echo json_encode(['success' => false, 'error' => 'Sin permiso para crear crónicas'], JSON_UNESCAPED_UNICODE);
  exit;
}

// ================================
// Payload
// ================================
$payload = [];
$payloadRaw = $_POST['payload'] ?? '';

if (is_string($payloadRaw) && trim($payloadRaw) !== '') {
  $tmp = json_decode($payloadRaw, true);
  if (is_array($tmp)) $payload = $tmp;
}

if (!is_array($payload) || count($payload) === 0) {
  $payload = [
    'proyecto_id'   => $_POST['proyecto_id']   ?? 0,
    'encargado_id'  => $_POST['encargado_id']  ?? 0,
    'distrito_id'   => $_POST['distrito_id']   ?? 0,
    'estado'        => $_POST['estado']        ?? 'Pendiente',
    'comentarios'   => $_POST['comentarios']   ?? '',
    'observaciones' => $_POST['observaciones'] ?? '',
    'tipo_ids'      => $_POST['tipo_id']       ?? [],
    'imagenes_desc' => $_POST['imagenes_desc'] ?? [],
  ];
}

$proyecto_id   = (int)($payload['proyecto_id'] ?? 0);
$encargado_id  = (int)($payload['encargado_id'] ?? 0);
$distrito_id   = (int)($payload['distrito_id'] ?? 0);
$estado        = trim((string)($payload['estado'] ?? 'Pendiente'));
$comentarios   = (string)($payload['comentarios'] ?? '');
$observaciones = (string)($payload['observaciones'] ?? '');

$tipo_ids = $payload['tipo_ids'] ?? ($payload['tipo_id'] ?? []);
if (!is_array($tipo_ids)) $tipo_ids = [$tipo_ids];
$tipo_ids = array_values(array_filter(array_map('intval', $tipo_ids), fn($x) => $x > 0));

if ($proyecto_id <= 0 || empty($tipo_ids)) {
  echo json_encode(['success' => false, 'error' => 'Faltan campos requeridos: proyecto y tipos'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tipos_json = json_encode($tipo_ids, JSON_UNESCAPED_UNICODE);

// imagenes_desc normalizado (obj o array)
$imagenes_desc = $payload['imagenes_desc'] ?? [];
if (!is_array($imagenes_desc)) $imagenes_desc = [];

$imagenes_desc_norm = [];
foreach ($imagenes_desc as $k => $v) {
  $kk = is_numeric($k) ? (string)((int)$k) : (string)$k;
  $imagenes_desc_norm[$kk] = trim((string)$v);
}

try {
  $anio_consec = (int)date('Y');

  $sqlMax = "
    SELECT COALESCE(
      MAX(CAST(SUBSTRING(consecutivo, 4) AS UNSIGNED)),
      0
    ) AS max_num
    FROM cronicas
    WHERE YEAR(fecha) = ?
  ";
  $stmtMax = $conn->prepare($sqlMax);
  $stmtMax->bind_param("i", $anio_consec);
  $stmtMax->execute();
  $rowMax = $stmtMax->get_result()->fetch_assoc();
  $stmtMax->close();

  $consec_numero = ((int)($rowMax['max_num'] ?? 0)) + 1;
  $consec_codigo = 'GV-' . sprintf('%03d', $consec_numero);

  $stmt = $conn->prepare("
    INSERT INTO cronicas
    (consecutivo, proyecto_id, usuario_id, encargado, distrito, estado, tipo, comentarios, observaciones, fecha, estado_registro, imagenes, adjuntos, documentos, firmados)
    VALUES
    ('', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'activo', '[]', '[]', '[]', '[]')
  ");
  $stmt->bind_param("iiiissss", $proyecto_id, $uid, $encargado_id, $distrito_id, $estado, $tipos_json, $comentarios, $observaciones);

  if (!$stmt->execute()) throw new Exception("No se pudo insertar crónica");
  $cronica_id = (int)$conn->insert_id;
  $stmt->close();

  $stmtUpd = $conn->prepare("UPDATE cronicas SET consecutivo=? WHERE id=?");
  $stmtUpd->bind_param("si", $consec_codigo, $cronica_id);
  $stmtUpd->execute();
  $stmtUpd->close();

  $baseData = getDataBasePath();
  if (!$baseData) throw new Exception("No se pudo acceder/crear la carpeta /data del sistema.");

  $baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . (int)$proyecto_id;
  $rutaImg      = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_img";
  $rutaAdjuntos = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_adjuntos";
  $rutaDocs     = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_docs";
  $rutaFirmados = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_firmadas";

  if (!is_dir($baseProyecto) && !mkdir($baseProyecto, 0775, true)) throw new Exception("No se pudo crear carpeta del proyecto en /data/proyectos.");
  if (!is_dir($rutaImg)      && !mkdir($rutaImg, 0775, true))      throw new Exception("No se pudo crear carpeta cronicas_img.");
  if (!is_dir($rutaAdjuntos) && !mkdir($rutaAdjuntos, 0775, true)) throw new Exception("No se pudo crear carpeta cronicas_adjuntos.");
  if (!is_dir($rutaDocs)     && !mkdir($rutaDocs, 0775, true))     throw new Exception("No se pudo crear carpeta cronicas_docs.");
  if (!is_dir($rutaFirmados) && !mkdir($rutaFirmados, 0775, true)) throw new Exception("No se pudo crear carpeta cronicas_firmadas.");

  // tolerancia extra de keys
  $filesImagenes = pickFiles(["imagenes"]);
  $filesAdjuntos = pickFiles(["adjuntos_img","adjuntos","adjuntos_imagenes"]);
  $filesDocs     = pickFiles(["documentos","docs"]);
  $filesFirmados = pickFiles(["firmados"]);

  $permitImg = ["jpg","jpeg","png","gif","webp","jfif","heic","heif"];

  $rImgs     = subirArchivos($filesImagenes ?? [], $rutaImg,      $permitImg, "img_");
  $rAdjuntos = subirArchivos($filesAdjuntos ?? [], $rutaAdjuntos, $permitImg, "adj_");
  $rDocs     = subirArchivos($filesDocs     ?? [], $rutaDocs,     ["pdf","doc","docx","xls","xlsx","ppt","pptx"], "doc_");
  $rFirmados = subirArchivos($filesFirmados ?? [], $rutaFirmados, ["pdf"], "firm_");

  $imgsMeta = [];
  foreach ($rImgs['map'] as $idx => $filename) {
    $desc = $imagenes_desc_norm[(string)$idx] ?? '';
    $imgsMeta[] = ['file' => $filename, 'desc' => $desc];
  }

  $adjuntos = $rAdjuntos['guardados'];
  $docs     = $rDocs['guardados'];
  $firmados = $rFirmados['guardados'];

  $stmtFiles = $conn->prepare("UPDATE cronicas SET imagenes=?, adjuntos=?, documentos=?, firmados=? WHERE id=?");
  $imgJson  = json_encode($imgsMeta, JSON_UNESCAPED_UNICODE);
  $adjJson  = json_encode($adjuntos, JSON_UNESCAPED_UNICODE);
  $docJson  = json_encode($docs, JSON_UNESCAPED_UNICODE);
  $firmJson = json_encode($firmados, JSON_UNESCAPED_UNICODE);

  $stmtFiles->bind_param("ssssi", $imgJson, $adjJson, $docJson, $firmJson, $cronica_id);
  $stmtFiles->execute();
  $stmtFiles->close();

  $huboErrores = !empty($rImgs['errores']) || !empty($rAdjuntos['errores']) || !empty($rDocs['errores']) || !empty($rFirmados['errores']);

  echo json_encode([
    'success'     => true,
    'cronica_id'  => $cronica_id,
    'consecutivo' => $consec_codigo . '-' . $anio_consec,
    'warnings'    => $huboErrores ? [
      'imagenes'   => $rImgs['errores'],
      'adjuntos'   => $rAdjuntos['errores'],
      'documentos' => $rDocs['errores'],
      'firmados'   => $rFirmados['errores'],
    ] : [],
    'debug' => [
      'files_keys' => array_keys($_FILES ?? []),

      'count_imagenes'     => countFilesKey('imagenes'),
      'count_adjuntos_img' => countFilesKey('adjuntos_img'),
      'count_documentos'   => countFilesKey('documentos'),
      'count_firmados'     => countFilesKey('firmados'),

      'names_imagenes'     => listFileNames('imagenes'),
      'names_adjuntos_img' => listFileNames('adjuntos_img'),

      'errors_imagenes'     => listFileErrors('imagenes'),
      'errors_adjuntos_img' => listFileErrors('adjuntos_img'),

      'sizes_imagenes'      => listFileSizes('imagenes'),
      'sizes_adjuntos_img'  => listFileSizes('adjuntos_img'),

      'imagenes_desc_norm' => $imagenes_desc_norm,
      'imagenes_meta'      => $imgsMeta,

      'adjuntos_guardados' => $adjuntos,
      'errores_adjuntos'   => $rAdjuntos['errores'],
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
