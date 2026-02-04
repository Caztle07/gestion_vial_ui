<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding("UTF-8");
setlocale(LC_ALL,'es_ES.UTF-8');

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

header('Content-Type: application/json; charset=utf-8');
mysqli_set_charset($conn, "utf8");

error_reporting(E_ALL);
ini_set('display_errors', 1);

$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
if ($rol !== "inspector") {
  echo json_encode(['success' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
  exit;
}

// helpers copiados de tu cronicas.php
function subirArchivos($files, $destDir, $permitidas, $prefix = 'file_') {
  $guardados = [];
  $errores   = [];
  $map       = [];

  if (!isset($files["name"]) || empty($files["name"]) || (is_array($files["name"]) && empty($files["name"][0]))) {
    return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
  }

  if (!is_dir($destDir)) {
    if (!mkdir($destDir, 0775, true)) {
      $errores[] = "No se pudo crear el directorio destino: $destDir";
      return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
    }
  }

  $names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
  foreach ($names as $i => $name) {
    $tmp  = is_array($files["tmp_name"]) ? ($files["tmp_name"][$i] ?? '') : ($files["tmp_name"] ?? '');
    $err  = is_array($files["error"]) ? ($files["error"][$i] ?? UPLOAD_ERR_NO_FILE) : ($files["error"] ?? UPLOAD_ERR_NO_FILE);

    if ($err === UPLOAD_ERR_NO_FILE) continue;

    if ($err !== UPLOAD_ERR_OK) {
      $errores[] = "Error subiendo '$name' (código $err).";
      continue;
    }

    if (!is_uploaded_file($tmp)) {
      $errores[] = "Archivo temporal inválido para '$name'.";
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

    if (move_uploaded_file($tmp, $rutaFinal)) {
      $guardados[] = $seguro;
      $map[$i] = $seguro;
    } else {
      $errores[] = "No se pudo mover '$name' a '$rutaFinal'.";
    }
  }

  return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
}

function getDataBasePath() {
  $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui
  $root = realpath($rootGuess);
  if ($root === false) $root = $rootGuess;

  $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
  if (!is_dir($data)) @mkdir($data, 0775, true);

  $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";
  if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

  if (!is_dir($data) || !is_dir($proyectos)) return null;
  return $data;
}

// aceptar solo el mismo flujo que el form
if ($_SERVER["REQUEST_METHOD"] !== "POST" || ($_POST["accion"] ?? '') !== "nueva") {
  echo json_encode(['success' => false, 'error' => 'Solicitud inválida'], JSON_UNESCAPED_UNICODE);
  exit;
}

$proyecto_id   = intval($_POST["proyecto_id"] ?? 0);
$usuario_id    = intval($_SESSION["id"] ?? 0);
$encargado_id  = intval($_POST["encargado_id"] ?? 0);
$distrito_id   = intval($_POST["distrito_id"] ?? 0);
$estado        = trim($_POST["estado"] ?? 'Pendiente');
$tipos         = $_POST["tipo_id"] ?? [];
$comentarios   = trim($_POST["comentarios"] ?? '');
$observaciones = trim($_POST["observaciones"] ?? '');

$imagenes_desc = $_POST["imagenes_desc"] ?? [];
if (!is_array($imagenes_desc)) $imagenes_desc = [];

if ($proyecto_id <= 0 || empty($tipos)) {
  echo json_encode(['success' => false, 'error' => 'Datos incompletos (proyecto y tipos)'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tipos_json = json_encode($tipos, JSON_UNESCAPED_UNICODE);

// consecutivo por año (igual que tu cronicas.php)
$anio_consec = (int)date('Y');

$sqlMax = "
  SELECT COALESCE(MAX(CAST(SUBSTRING(consecutivo, 4) AS UNSIGNED)),0) AS max_num
  FROM cronicas
  WHERE YEAR(fecha) = ?
";
$stmtMax = $conn->prepare($sqlMax);
$stmtMax->bind_param("i", $anio_consec);
$stmtMax->execute();
$rowMax = $stmtMax->get_result()->fetch_assoc();
$stmtMax->close();

$consec_numero = (int)($rowMax['max_num'] ?? 0) + 1;
$consec_codigo = 'GV-' . sprintf('%03d', $consec_numero);

// insertar cronica (mismo esquema)
$stmt = $conn->prepare("INSERT INTO cronicas
  (consecutivo, proyecto_id, usuario_id, encargado, distrito, estado, tipo, comentarios, observaciones, fecha, estado_registro, imagenes, adjuntos, documentos, firmados)
  VALUES ('', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'activo', '[]', '[]', '[]', '[]')
");
$stmt->bind_param("iiiissss", $proyecto_id, $usuario_id, $encargado_id, $distrito_id, $estado, $tipos_json, $comentarios, $observaciones);

if (!$stmt->execute()) {
  echo json_encode(['success' => false, 'error' => 'No se pudo insertar la crónica'], JSON_UNESCAPED_UNICODE);
  exit;
}
$id = (int)$conn->insert_id;
$stmt->close();

$stmtUpd = $conn->prepare("UPDATE cronicas SET consecutivo=? WHERE id=?");
$stmtUpd->bind_param("si", $consec_codigo, $id);
$stmtUpd->execute();
$stmtUpd->close();

// rutas (igual)
$baseData = getDataBasePath();
if (!$baseData) {
  echo json_encode(['success' => false, 'error' => 'No se pudo acceder/crear /data'], JSON_UNESCAPED_UNICODE);
  exit;
}

$nombreProyecto = (string)(int)$proyecto_id;

$baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $nombreProyecto;
$rutaImg      = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_img";
$rutaAdjuntos = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_adjuntos";
$rutaDocs     = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_docs";
$rutaFirmados = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_firmadas";

if (!is_dir($baseProyecto) && !mkdir($baseProyecto, 0775, true)) {
  echo json_encode(['success' => false, 'error' => 'No se pudo crear carpeta del proyecto en /data/proyectos'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!is_dir($rutaImg)      && !mkdir($rutaImg, 0775, true)) {
  echo json_encode(['success' => false, 'error' => 'No se pudo crear cronicas_img'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!is_dir($rutaAdjuntos) && !mkdir($rutaAdjuntos, 0775, true)) {
  echo json_encode(['success' => false, 'error' => 'No se pudo crear cronicas_adjuntos'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!is_dir($rutaDocs)     && !mkdir($rutaDocs, 0775, true)) {
  echo json_encode(['success' => false, 'error' => 'No se pudo crear cronicas_docs'], JSON_UNESCAPED_UNICODE);
  exit;
}
if (!is_dir($rutaFirmados) && !mkdir($rutaFirmados, 0775, true)) {
  echo json_encode(['success' => false, 'error' => 'No se pudo crear cronicas_firmadas'], JSON_UNESCAPED_UNICODE);
  exit;
}

$rImgs     = subirArchivos($_FILES["imagenes"]     ?? [], $rutaImg,      ["jpg","jpeg","png","gif","webp"], "img_");
$rAdjuntos = subirArchivos($_FILES["adjuntos_img"] ?? [], $rutaAdjuntos, ["jpg","jpeg","png","gif","webp"], "adj_");
$rDocs     = subirArchivos($_FILES["documentos"]   ?? [], $rutaDocs,     ["pdf","doc","docx","xls","xlsx","ppt","pptx"], "doc_");
$rFirmados = subirArchivos($_FILES["firmados"]     ?? [], $rutaFirmados, ["pdf"], "firm_");

$imgsMeta = [];
foreach ($rImgs['map'] as $idx => $filename) {
  $desc = trim((string)($imagenes_desc[$idx] ?? ''));
  $imgsMeta[] = ['file' => $filename, 'desc' => $desc];
}

$adjuntos = $rAdjuntos['guardados'];
$docs     = $rDocs['guardados'];
$firmados = $rFirmados['guardados'];

$stmt2   = $conn->prepare("UPDATE cronicas SET imagenes=?, adjuntos=?, documentos=?, firmados=? WHERE id=?");
$imgJson  = json_encode($imgsMeta, JSON_UNESCAPED_UNICODE);
$adjJson  = json_encode($adjuntos, JSON_UNESCAPED_UNICODE);
$docJson  = json_encode($docs, JSON_UNESCAPED_UNICODE);
$firmJson = json_encode($firmados, JSON_UNESCAPED_UNICODE);

$stmt2->bind_param("ssssi", $imgJson, $adjJson, $docJson, $firmJson, $id);
$stmt2->execute();
$stmt2->close();

$huboErrores = !empty($rImgs['errores']) || !empty($rAdjuntos['errores']) || !empty($rDocs['errores']) || !empty($rFirmados['errores']);

echo json_encode([
  'success' => true,
  'cronica_id' => $id,
  'consecutivo' => $consec_codigo . '-' . $anio_consec,
  'warnings' => $huboErrores ? [
    'imagenes'   => $rImgs['errores'],
    'adjuntos'   => $rAdjuntos['errores'],
    'documentos' => $rDocs['errores'],
    'firmados'   => $rFirmados['errores'],
  ] : []
], JSON_UNESCAPED_UNICODE);
