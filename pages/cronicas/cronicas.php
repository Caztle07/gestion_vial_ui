<?php
ini_set('default_charset','UTF-8');
mb_internal_encoding("UTF-8");
setlocale(LC_ALL,'es_ES.UTF-8');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

// SIEMPRE antes de cualquier HTML
require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// =====================================
// ROLES / PERMISOS
// =====================================
$rol = strtolower(trim($_SESSION["rol"] ?? "vista"));
$uid = (int)($_SESSION["id"] ?? 0);

// SOLO inspector puede crear crónicas
$puedeCrear = ($rol === "inspector");

// Nadie edita / borra crónicas desde esta pantalla (tu configuración)
$puedeEditar = false;

// Permiso para anular (ajusta si querés)
$puedeAnular = (function_exists('can_edit') && (can_edit("admin") || can_edit("cronicas"))) || ($rol === "admin");

// Motivos de anulación
$MOTIVOS_ANULACION = [
  "error_datos"          => "Error en datos (proyecto/distrito/encargado)",
  "evidencia_incorrecta" => "Evidencia incorrecta o incompleta",
  "duplicada"            => "Crónica duplicada",
  "proyecto_equivocado"  => "Registrada en el proyecto equivocado",
  "digitacion"           => "Error de digitación / estructura",
  "correccion_mayor"     => "Corrección mayor (se creará reemplazo)",
  "solicitud_admin"      => "Solicitud administrativa / encargado",
];

// ======================================================
// HELPERS
// ======================================================
function e($s) {
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Helpers DB (detectar si existe tabla/columna)
function db_name(mysqli $conn): string {
  $r = $conn->query("SELECT DATABASE() AS db");
  $row = $r ? $r->fetch_assoc() : [];
  return (string)($row['db'] ?? '');
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

/**
 * Selecciona el catálogo correcto para los tipos:
 * - Preferir tareas_catalogo
 * - Fallback a tipos_cronica
 */
function tipos_catalogo_tabla(mysqli $conn): string {
  return table_exists($conn, "tareas_catalogo") ? "tareas_catalogo" : "tipos_cronica";
}

/**
 * Retorna [cerradoBool, mensaje]
 * Compatible: si no existe la columna, no bloquea.
 */
function proyecto_cerrado(mysqli $conn, int $proyecto_id): array {
  if ($proyecto_id <= 0) return [false, ''];

  if (!column_exists($conn, "proyectos", "cerrado")) {
    return [false, ''];
  }

  $stmt = $conn->prepare("SELECT COALESCE(cerrado,0) AS cerrado FROM proyectos WHERE id=? LIMIT 1");
  $stmt->bind_param("i", $proyecto_id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$row) return [false, ''];

  $cerrado = ((int)($row['cerrado'] ?? 0) === 1);
  if ($cerrado) {
    return [true, "Este proyecto está CERRADO. Debes reabrirlo para poder registrar nuevas crónicas."];
  }
  return [false, ""];
}

/**
 * SUBIDA
 * Devuelve:
 * - guardados: lista de nombres guardados
 * - errores: lista de errores por archivo
 * - map: [index_original => nombre_guardado]
 */
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

  if (!is_writable($destDir)) {
    $errores[] = "La carpeta no tiene permisos de escritura: $destDir";
    return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
  }

  // Hardening: bloquear ejecución PHP
  $ht = rtrim($destDir, "/\\") . DIRECTORY_SEPARATOR . ".htaccess";
  if (!file_exists($ht)) {
    @file_put_contents($ht, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
  }

  $bloqueadas = ['php','phtml','php3','php4','php5','php7','phar','cgi','pl','exe','sh','bat','cmd'];

  $names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
  foreach ($names as $i => $name) {

    $tmp = is_array($files["tmp_name"]) ? ($files["tmp_name"][$i] ?? '') : ($files["tmp_name"] ?? '');
    $err = is_array($files["error"])    ? ($files["error"][$i] ?? UPLOAD_ERR_NO_FILE) : ($files["error"] ?? UPLOAD_ERR_NO_FILE);

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
    if (in_array($ext, $bloqueadas, true)) {
      $errores[] = "Extensión bloqueada para '$name' (.$ext).";
      continue;
    }
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
      $last = error_get_last();
      $extra = $last && isset($last['message']) ? (" Detalle: " . $last['message']) : "";
      $errores[] = "No se pudo mover '$name' a '$rutaFinal'." . $extra;
    }
  }

  return ['guardados' => $guardados, 'errores' => $errores, 'map' => $map];
}

/**
 * ✅ RUTA BASE /pages/data
 */
function getDataBasePath() {
  $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../pages
  $root = realpath($rootGuess);
  if ($root === false) $root = $rootGuess;

  $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
  if (!is_dir($data)) @mkdir($data, 0775, true);

  $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";
  if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

  if (!is_dir($data) || !is_dir($proyectos)) return null;
  return $data;
}

function get_tipos_nombres(mysqli $conn, array $ids): array {
  $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
  if (empty($ids)) return [];

  $tabla = tipos_catalogo_tabla($conn);

  $place = implode(",", array_fill(0, count($ids), "?"));
  $types = str_repeat("i", count($ids));

  $stmt = $conn->prepare("SELECT id, nombre FROM {$tabla} WHERE id IN ($place)");
  $stmt->bind_param($types, ...$ids);
  $stmt->execute();
  $res = $stmt->get_result();

  $map = [];
  while ($res && ($r = $res->fetch_assoc())) {
    $map[(int)$r['id']] = (string)$r['nombre'];
  }
  $stmt->close();

  $out = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) $out[] = $map[$id];
  }
  return $out;
}

// ======================================================
// ANULAR CRÓNICA
// ======================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? '') === "anular") {

  if (!$puedeAnular) {
    header("Location: cronicas.php?err=" . urlencode("Sin permisos para anular crónicas."));
    exit;
  }

  if (function_exists('csrf_check')) {
    $csrf = $_POST['_csrf'] ?? '';
    if (!csrf_check($csrf)) {
      header("Location: cronicas.php?err=" . urlencode("CSRF inválido o sesión expirada."));
      exit;
    }
  }

  $cronica_id = (int)($_POST["cronica_id"] ?? 0);
  $motivo_key = trim((string)($_POST["motivo_anulacion"] ?? ""));
  $detalle    = trim((string)($_POST["detalle_anulacion"] ?? ""));

  if ($cronica_id <= 0) {
    header("Location: cronicas.php?err=" . urlencode("ID de crónica inválido."));
    exit;
  }

  if ($motivo_key === "" || !array_key_exists($motivo_key, $MOTIVOS_ANULACION)) {
    header("Location: cronicas.php?err=" . urlencode("Debe seleccionar un motivo válido."));
    exit;
  }

  if ($motivo_key === "correccion_mayor" && mb_strlen($detalle) < 10) {
    header("Location: cronicas.php?err=" . urlencode("En 'Corrección mayor' el detalle es obligatorio (mínimo 10 caracteres)."));
    exit;
  }

  $stmtChk = $conn->prepare("SELECT * FROM cronicas WHERE id=? LIMIT 1");
  $stmtChk->bind_param("i", $cronica_id);
  $stmtChk->execute();
  $antes = $stmtChk->get_result()->fetch_assoc();
  $stmtChk->close();

  if (!$antes) {
    header("Location: cronicas.php?err=" . urlencode("Crónica no encontrada."));
    exit;
  }

  $estadoReg = strtolower(trim((string)($antes["estado_registro"] ?? "activo")));
  if ($estadoReg === "anulada") {
    header("Location: cronicas.php?ok=" . urlencode("La crónica ya estaba anulada."));
    exit;
  }

  $motivo_label = $MOTIVOS_ANULACION[$motivo_key];

  $stmtUp = $conn->prepare("
    UPDATE cronicas
    SET
      estado_registro = 'anulada',
      anulada_por = ?,
      anulada_fecha = NOW(),
      anulada_motivo = ?,
      anulada_detalle = ?
    WHERE id = ?
    LIMIT 1
  ");
  $stmtUp->bind_param("issi", $uid, $motivo_label, $detalle, $cronica_id);
  $stmtUp->execute();
  $stmtUp->close();

  if (function_exists('log_accion')) {
    $detalle_log = json_encode([
      'cronica_id' => $cronica_id,
      'accion'     => 'anular',
      'antes'      => $antes,
      'despues'    => [
        'estado_registro' => 'anulada',
        'anulada_por'     => $uid,
        'anulada_motivo'  => $motivo_label,
        'anulada_detalle' => $detalle,
        'anulada_fecha'   => date('Y-m-d H:i:s'),
      ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    log_accion($conn, 'CRONICA_ANULAR', $detalle_log);
  }

  header("Location: cronicas.php?ok=" . urlencode("Crónica anulada correctamente."));
  exit;
}

// ======================================================
// GUARDAR NUEVA CRÓNICA
// ======================================================
$mensajeErrorPermiso = "";
$mensajeErrorCampos  = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["accion"] ?? '') === "nueva") {

  if (!$puedeCrear) {
    $mensajeErrorPermiso = "Sin permiso para crear crónicas.";
  } else {

    if (function_exists('csrf_check')) {
      $csrf = $_POST['_csrf'] ?? '';
      if (!csrf_check($csrf)) {
        header("Location: cronicas.php?err=" . urlencode("CSRF inválido o sesión expirada."));
        exit;
      }
    }

    $proyecto_id   = (int)($_POST["proyecto_id"] ?? 0);
    $usuario_id    = (int)($_SESSION["id"] ?? 0);
    $encargado_id  = (int)($_POST["encargado_id"] ?? 0);
    $distrito_id   = (int)($_POST["distrito_id"] ?? 0);

    // ✅ estado fijo
    $estado = "Pendiente";

    $tipos         = $_POST["tipo_id"] ?? [];
    $comentarios   = trim((string)($_POST["comentarios"] ?? ''));
    $observaciones = trim((string)($_POST["observaciones"] ?? ''));

    // Descripciones por imagen (solo evidencia)
    $imagenes_desc = $_POST["imagenes_desc"] ?? [];
    if (!is_array($imagenes_desc)) $imagenes_desc = [];

    if ($proyecto_id <= 0 || empty($tipos)) {
      $mensajeErrorCampos = "Complete todos los campos requeridos (proyecto y tipos).";
    } else {

      // bloqueo por cerrado (si existe columna)
      [$estaCerrado, $msgCerrado] = proyecto_cerrado($conn, $proyecto_id);
      if ($estaCerrado) {
        header("Location: cronicas.php?err=" . urlencode($msgCerrado));
        exit;
      }

      $tipos_json = json_encode($tipos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      // Consecutivo por año
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

      // Insert crónica
      $stmt = $conn->prepare("
        INSERT INTO cronicas
          (consecutivo, proyecto_id, usuario_id, encargado, distrito, estado, tipo, comentarios, observaciones, fecha, estado_registro, imagenes, adjuntos, documentos, firmados)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'activo', '[]', '[]', '[]', '[]')
      ");

      $stmt->bind_param(
        "siiiissss",
        $consec_codigo,
        $proyecto_id,
        $usuario_id,
        $encargado_id,
        $distrito_id,
        $estado,
        $tipos_json,
        $comentarios,
        $observaciones
      );
      $stmt->execute();
      $id = (int)$conn->insert_id;
      $stmt->close();

      // ==========================
      // RUTAS /pages/data
      // ==========================
      $baseData = getDataBasePath();
      if (!$baseData) {
        header("Location: cronicas.php?err=" . urlencode("No se pudo acceder/crear la carpeta /data del sistema. Revise permisos."));
        exit;
      }

      $nombreProyecto = (string)(int)$proyecto_id;

      $baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $nombreProyecto;
      $rutaImg      = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_img";
      $rutaAdjuntos = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_adjuntos";
      $rutaDocs     = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_docs";
      $rutaFirmados = $baseProyecto . DIRECTORY_SEPARATOR . "cronicas_firmadas";

      if (!is_dir($baseProyecto) && !mkdir($baseProyecto, 0775, true)) {
        header("Location: cronicas.php?err=" . urlencode("No se pudo crear carpeta del proyecto en /data/proyectos. Revise permisos."));
        exit;
      }
      foreach ([$rutaImg, $rutaAdjuntos, $rutaDocs, $rutaFirmados] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
          header("Location: cronicas.php?err=" . urlencode("No se pudo crear carpeta: " . basename($dir) . ". Revise permisos."));
          exit;
        }
      }

      // SUBIDA
      $rImgs     = subirArchivos($_FILES["imagenes"]     ?? [], $rutaImg,      ["jpg","jpeg","png","gif","webp"], "img_");
      $rAdjuntos = subirArchivos($_FILES["adjuntos_img"] ?? [], $rutaAdjuntos, ["jpg","jpeg","png","gif","webp"], "adj_");
      $rDocs     = subirArchivos($_FILES["documentos"]   ?? [], $rutaDocs,     ["pdf","doc","docx","xls","xlsx","ppt","pptx"], "doc_");
      $rFirmados = subirArchivos($_FILES["firmados"]     ?? [], $rutaFirmados, ["pdf"], "firm_");

      // ✅ IMÁGENES (EVIDENCIA) CON DESCRIPCIÓN
      $imgsMeta = [];
      foreach ($rImgs['map'] as $idx => $filename) {
        $desc = trim((string)($imagenes_desc[$idx] ?? ''));
        $imgsMeta[] = ['file' => $filename, 'desc' => $desc];
      }

      // ✅ ADJUNTOS SIN DESCRIPCIÓN (solo nombres)
      $adjuntos = $rAdjuntos['guardados'];
      $docs     = $rDocs['guardados'];
      $firmados = $rFirmados['guardados'];

      // Guardar JSONs en BD
      $stmt2 = $conn->prepare("UPDATE cronicas SET imagenes=?, adjuntos=?, documentos=?, firmados=? WHERE id=?");

      $imgJson  = json_encode($imgsMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $adjJson  = json_encode($adjuntos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $docJson  = json_encode($docs,     JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $firmJson = json_encode($firmados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

      $stmt2->bind_param("ssssi", $imgJson, $adjJson, $docJson, $firmJson, $id);
      $stmt2->execute();

      if ($stmt2->error) {
        $err = $stmt2->error;
        $stmt2->close();
        header("Location: cronicas.php?err=" . urlencode("Crónica creada, pero falló guardar evidencias en BD: $err"));
        exit;
      }
      $stmt2->close();

      if (function_exists('log_accion')) {
        $detalle_log = json_encode([
          'cronica_id'     => $id,
          'accion'         => 'crear',
          'proyecto_id'    => $proyecto_id,
          'usuario_id'     => $usuario_id,
          'encargado_id'   => $encargado_id,
          'distrito_id'    => $distrito_id,
          'estado'         => $estado,
          'tipos'          => $tipos,
          'comentarios'    => $comentarios,
          'observaciones'  => $observaciones,
          'imagenes'       => $imgsMeta,
          'adjuntos'       => $adjuntos,
          'documentos'     => $docs,
          'firmados'       => $firmados,
          'errores_subida' => [
            'imagenes'   => $rImgs['errores'],
            'adjuntos'   => $rAdjuntos['errores'],
            'documentos' => $rDocs['errores'],
            'firmados'   => $rFirmados['errores'],
          ],
          'anio_consec'    => $anio_consec,
          'consec_numero'  => $consec_numero,
          'consec_codigo'  => $consec_codigo,
          'ruta_guardado'  => $baseProyecto,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        log_accion($conn, 'CRONICA_CREAR', $detalle_log);
      }

      $huboErrores = !empty($rImgs['errores']) || !empty($rAdjuntos['errores']) || !empty($rDocs['errores']) || !empty($rFirmados['errores']);
      if ($huboErrores) {
        header("Location: cronicas.php?ok=" . urlencode("Crónica creada. Algunos archivos no se pudieron subir; revise extensiones/permisos."));
        exit;
      }

      header("Location: cronicas.php?ok=" . urlencode("Crónica guardada correctamente."));
      exit;
    }
  }
}

// ======================================================
// LISTAS / PROYECTOS (con modalidad, metas y distritos igual que proyectos.php)
// ======================================================
$colCerradoExiste     = column_exists($conn, "proyectos", "cerrado");
$tienePuenteCaminos   = table_exists($conn, "proyecto_caminos");
$tienePuenteDistritos = table_exists($conn, "proyecto_distritos");

// Metas (N:N)
$tieneMetasCatalogo = table_exists($conn, "metas_proyecto");
$tieneMetasPuente   = table_exists($conn, "proyecto_metas");
$tieneMetas         = $tieneMetasCatalogo && $tieneMetasPuente;

$tablaTipos = tipos_catalogo_tabla($conn);

// ✅ SELECT/JOIN CAMINOS (para display)
if ($tienePuenteCaminos) {
  $selectCaminosDisplay = "COALESCE(GROUP_CONCAT(DISTINCT cam2.codigo ORDER BY cam2.codigo SEPARATOR ' | '), COALESCE(cam.codigo,''))";
  $joinCaminos   = "
    LEFT JOIN proyecto_caminos pc ON pc.proyecto_id = p.id
    LEFT JOIN caminos cam2 ON cam2.id = pc.camino_id
    LEFT JOIN caminos cam ON cam.id = p.inventario_id
  ";
} else {
  $selectCaminosDisplay = "COALESCE(cam.codigo,'')";
  $joinCaminos   = "LEFT JOIN caminos cam ON cam.id = p.inventario_id";
}

// ✅ SELECT/JOIN DISTRITOS (para traerlos a crónica)
if ($tienePuenteDistritos) {
  $selectDistritoIds   = "COALESCE(GROUP_CONCAT(DISTINCT d2.id ORDER BY d2.id SEPARATOR ','), '') AS distrito_ids";
  $selectDistritoNames = "COALESCE(GROUP_CONCAT(DISTINCT d2.nombre ORDER BY d2.id SEPARATOR '||'), '') AS distrito_nombres";
  $joinDistritos = "
    LEFT JOIN proyecto_distritos pd ON pd.proyecto_id = p.id
    LEFT JOIN distritos d2 ON d2.id = pd.distrito_id
  ";
  $selectDistritoDefault = "0 AS distrito_default_id"; // lo sacamos en PHP (primer distrito del array)
} else {
  $selectDistritoIds   = "COALESCE(CAST(d.id AS CHAR), '') AS distrito_ids";
  $selectDistritoNames = "COALESCE(d.nombre, '') AS distrito_nombres";
  $joinDistritos = "LEFT JOIN distritos d ON d.id = p.distrito_id";
  $selectDistritoDefault = "COALESCE(p.distrito_id,0) AS distrito_default_id";
}

// ✅ METAS (texto)
if ($tieneMetas) {
  $selectMetas = "COALESCE(GROUP_CONCAT(DISTINCT mp2.nombre ORDER BY mp2.nombre SEPARATOR ' | '), '-') AS metas_txt";
  $joinMetas = "
    LEFT JOIN proyecto_metas pm2 ON pm2.proyecto_id = p.id
    LEFT JOIN metas_proyecto mp2 ON mp2.id = pm2.meta_id AND (mp2.activo = 1 OR mp2.activo IS NULL)
  ";
} else {
  $selectMetas = "'-' AS metas_txt";
  $joinMetas = "";
}

// ✅ TIPOS DEL PROYECTO (para filtrar tipos en crónica)
$selectTiposIds = "COALESCE(GROUP_CONCAT(DISTINCT tc.id ORDER BY tc.nombre SEPARATOR ','), '') AS tipo_ids";
$selectTiposNom = "COALESCE(GROUP_CONCAT(DISTINCT tc.nombre ORDER BY tc.nombre SEPARATOR '||'), '') AS tipo_nombres";

$sqlProyectos = "
  SELECT
    p.id,
    p.encargado_id,
    m.nombre AS modalidad,
    $selectMetas,
    $selectDistritoIds,
    $selectDistritoNames,
    $selectDistritoDefault,
    $selectTiposIds,
    $selectTiposNom,
    " . ($colCerradoExiste ? "COALESCE(p.cerrado,0) AS cerrado," : "0 AS cerrado,") . "
    CONCAT(
      $selectCaminosDisplay,
      ' - ',
      COALESCE(p.nombre, ''),
      ' (',
      COALESCE(e.nombre, ''),
      ')',
      " . ($colCerradoExiste ? "IF(COALESCE(p.cerrado,0)=1, ' [CERRADO]', '')" : "''") . "
    ) AS display_nombre
  FROM proyectos p
  $joinCaminos
  $joinDistritos
  $joinMetas
  LEFT JOIN encargados      e  ON e.id = p.encargado_id
  LEFT JOIN modalidades     m  ON m.id = p.modalidad_id
  LEFT JOIN proyecto_tipos  pt ON pt.proyecto_id = p.id
  LEFT JOIN tareas_catalogo tc ON tc.id = pt.tipo_id
  WHERE
    (p.activo IS NULL OR p.activo = 1)
    AND (p.estado IS NULL OR (p.estado <> '0' AND p.estado <> 0))
  GROUP BY
    p.id, p.encargado_id, m.nombre " . ($colCerradoExiste ? ", p.cerrado" : "") . "
  ORDER BY display_nombre ASC
";
$proyectosRes = $conn->query($sqlProyectos);

$encargados = $conn->query("SELECT id,nombre FROM encargados WHERE activo=1 ORDER BY nombre");
$distritos  = $conn->query("SELECT id,nombre FROM distritos WHERE activo=1 ORDER BY nombre");
$tipos      = $conn->query("SELECT id,nombre FROM {$tablaTipos} ORDER BY nombre");

$listaProyectosRaw  = $proyectosRes ? $proyectosRes->fetch_all(MYSQLI_ASSOC) : [];
$listaEncargados = $encargados ? $encargados->fetch_all(MYSQLI_ASSOC) : [];
$listaDistritos  = $distritos ? $distritos->fetch_all(MYSQLI_ASSOC) : [];
$listaTipos      = $tipos ? $tipos->fetch_all(MYSQLI_ASSOC) : [];

// ✅ normalizar data por proyecto para JS (distritos/tipos como arrays)
$proyectosMap = [];
foreach ($listaProyectosRaw as $p) {
  $pid = (int)($p['id'] ?? 0);
  if ($pid <= 0) continue;

  $distIds = array_filter(explode(',', (string)($p['distrito_ids'] ?? '')), fn($x) => trim($x) !== '');
  $distNoms = array_filter(explode('||', (string)($p['distrito_nombres'] ?? '')), fn($x) => true);

  $distritosArr = [];
  $max = min(count($distIds), count($distNoms));
  for ($i=0; $i<$max; $i++) {
    $did = (int)$distIds[$i];
    if ($did <= 0) continue;
    $distritosArr[] = ['id'=>$did, 'nombre'=>(string)$distNoms[$i]];
  }

  $tipoIds = array_filter(explode(',', (string)($p['tipo_ids'] ?? '')), fn($x) => trim($x) !== '');
  $tipoNoms = array_filter(explode('||', (string)($p['tipo_nombres'] ?? '')), fn($x) => true);

  $tiposArr = [];
  $max2 = min(count($tipoIds), count($tipoNoms));
  for ($i=0; $i<$max2; $i++) {
    $tid = (int)$tipoIds[$i];
    if ($tid <= 0) continue;
    $tiposArr[] = ['id'=>$tid, 'nombre'=>(string)$tipoNoms[$i]];
  }

  // distrito default:
  $distDefault = (int)($p['distrito_default_id'] ?? 0);
  if ($tienePuenteDistritos && $distDefault <= 0 && !empty($distritosArr)) {
    $distDefault = (int)$distritosArr[0]['id'];
  }

  $proyectosMap[(string)$pid] = [
    'id' => $pid,
    'display_nombre' => (string)($p['display_nombre'] ?? ''),
    'encargado_id' => (int)($p['encargado_id'] ?? 0),
    'modalidad' => (string)($p['modalidad'] ?? ''),
    'metas_txt' => (string)($p['metas_txt'] ?? '-'),
    'distritos' => $distritosArr,         // array
    'distrito_id' => $distDefault,         // default
    'tipos' => $tiposArr,                  // array
    'cerrado' => (int)($p['cerrado'] ?? 0),
  ];
}

// ======================================================
// FILTRO DE REGISTRO
// ======================================================
$ver = strtolower(trim((string)($_GET["ver"] ?? "activos")));
$permitidosVer = ["activos","anuladas","papelera","todas"];
if (!in_array($ver, $permitidosVer, true)) $ver = "activos";

$whereEstado = "c.estado_registro='activo'";
$tituloListado = "Listado de Crónicas Activas";

if ($ver === "anuladas") {
  $whereEstado = "c.estado_registro='anulada'";
  $tituloListado = "Listado de Crónicas Anuladas";
} elseif ($ver === "papelera") {
  $whereEstado = "c.estado_registro='papelera'";
  $tituloListado = "Listado de Crónicas en Papelera";
} elseif ($ver === "todas") {
  $whereEstado = "c.estado_registro IN ('activo','anulada','papelera')";
  $tituloListado = "Listado de Crónicas (Todas)";
}

// ======================================================
// LISTADO CRÓNICAS
// ======================================================
$where = $whereEstado;

if (!($rol === "admin" || $rol === "encargado" || $rol === "inspector")) {
  $where .= " AND c.usuario_id=" . (int)$uid;
}

$sqlCronicas = "
SELECT
  c.*,
  p.nombre AS proyecto_nombre,
  e.nombre AS encargado_nombre,
  d.nombre AS distrito_nombre
FROM cronicas c
INNER JOIN proyectos p
  ON p.id = c.proyecto_id
 AND (p.estado IS NULL OR (p.estado <> '0' AND p.estado <> 0))
 AND (p.activo = 1 OR p.activo IS NULL)
LEFT JOIN encargados e ON e.id = c.encargado
LEFT JOIN distritos  d ON d.id = c.distrito
WHERE $where
ORDER BY c.id DESC
";
$cronicas = $conn->query($sqlCronicas);

// ======================================================
// HTML
// ======================================================
include "../../includes/header.php";

$mensaje_ok  = isset($_GET['ok'])  ? trim($_GET['ok'])  : '';
$mensaje_err = isset($_GET['err']) ? trim($_GET['err']) : '';
?>
<div class="container py-4">

  <?php if ($mensaje_ok !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-check-circle-fill me-2"></i>
      <?= e($mensaje_ok) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($mensaje_err !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2"></i>
      <?= e($mensaje_err) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
    </div>
  <?php endif; ?>

  <?php if ($mensajeErrorPermiso !== ''): ?>
    <div class="alert alert-danger shadow-sm"><?= e($mensajeErrorPermiso) ?></div>
  <?php endif; ?>

  <?php if ($mensajeErrorCampos !== ''): ?>
    <div class="alert alert-danger shadow-sm"><?= e($mensajeErrorCampos) ?></div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h3 class="fw-bold text-primary mb-0"><i class="bi bi-journal-text"></i> Crónicas de Proyectos</h3>

    <div class="d-flex gap-2 flex-wrap">
      <?php if ($puedeCrear): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNuevaCronica">
          <i class="bi bi-plus-circle"></i> Nueva Crónica
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body py-3">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="text-muted">Filtrar por registro:</div>
        <div class="btn-group" role="group" aria-label="Filtro registro">
          <a class="btn btn-outline-primary <?= ($ver==='activos' ? 'active' : '') ?>" href="cronicas.php?ver=activos">Activas</a>
          <a class="btn btn-outline-primary <?= ($ver==='anuladas' ? 'active' : '') ?>" href="cronicas.php?ver=anuladas">Anuladas</a>
          <a class="btn btn-outline-primary <?= ($ver==='papelera' ? 'active' : '') ?>" href="cronicas.php?ver=papelera">Papelera</a>
          <a class="btn btn-outline-primary <?= ($ver==='todas' ? 'active' : '') ?>" href="cronicas.php?ver=todas">Todas</a>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><?= e($tituloListado) ?></span>
      <span class="badge bg-secondary"><?= $cronicas ? (int)$cronicas->num_rows : 0 ?> registro(s)</span>
    </div>

    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0 text-center">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Consecutivo</th>
            <th>Proyecto</th>
            <th>Encargado</th>
            <th>Distrito</th>
            <th>Tipos</th>
            <th>Obs.</th>
            <th>Registro</th>
            <th>Fecha</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($cronicas && $cronicas->num_rows > 0): ?>
          <?php while($c = $cronicas->fetch_assoc()):
            $tiposArr = json_decode($c["tipo"] ?? "[]", true);
            if (!is_array($tiposArr)) $tiposArr = [];

            $tiposTexto = "-";
            if (!empty($tiposArr)) {
              $nombres = get_tipos_nombres($conn, $tiposArr);
              if (!empty($nombres)) $tiposTexto = implode(", ", $nombres);
            }

            $obsRaw   = trim($c["observaciones"] ?? '');
            $tieneObs = ($obsRaw !== '');

            $consecutivoBase = $c['consecutivo'] ?? '';
            $anioConsec = !empty($c['fecha']) ? date('Y', strtotime($c['fecha'])) : date('Y');
            $consecutivoMostrar = $consecutivoBase ? ($consecutivoBase . '-' . $anioConsec) : '';

            $estadoRegRow = strtolower(trim((string)($c["estado_registro"] ?? "activo")));
            $badgeReg = "bg-success";
            $txtReg = "Activa";
            if ($estadoRegRow === "anulada")  { $badgeReg = "bg-danger";    $txtReg = "Anulada"; }
            if ($estadoRegRow === "papelera") { $badgeReg = "bg-secondary"; $txtReg = "Papelera"; }

            $puedeAnularFila = $puedeAnular && ($estadoRegRow === "activo");
          ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><?= e($consecutivoMostrar) ?></td>
              <td><?= e($c['proyecto_nombre'] ?? '') ?></td>
              <td><?= e($c['encargado_nombre'] ?? '') ?></td>
              <td><?= e($c['distrito_nombre'] ?? '') ?></td>
              <td><?= e($tiposTexto) ?></td>

              <td>
                <?php if ($tieneObs): ?>
                  <a href="cronica_detalle.php?id=<?= (int)$c['id'] ?>#observaciones"
                     class="badge bg-info text-dark text-decoration-none"
                     title="Ver observaciones internas">Obs</a>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>

              <td>
                <span class="badge <?= e($badgeReg) ?>"><?= e($txtReg) ?></span>
              </td>

              <td><?= !empty($c['fecha']) ? e(date('d/m/Y', strtotime($c['fecha']))) : "-" ?></td>

              <td class="d-flex justify-content-center gap-1 flex-wrap">
                <a href="cronica_detalle.php?id=<?= (int)$c['id'] ?>"
                   class="btn btn-outline-primary btn-sm"
                   title="Ver detalle"><i class="bi bi-eye"></i></a>

                <a href="cronicas_print.php?id=<?= (int)$c['id'] ?>"
                   class="btn btn-outline-dark btn-sm"
                   title="Imprimir crónica"
                   target="_blank"><i class="bi bi-printer"></i></a>

                <?php if ($puedeAnularFila): ?>
                  <button
                    type="button"
                    class="btn btn-outline-danger btn-sm"
                    title="Anular"
                    data-bs-toggle="modal"
                    data-bs-target="#modalAnular"
                    data-id="<?= (int)$c['id'] ?>"
                    data-consec="<?= e($consecutivoMostrar) ?>"
                  ><i class="bi bi-x-octagon"></i></button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr>
            <td colspan="10" class="text-muted py-4">No hay crónicas para mostrar con este filtro.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($puedeAnular): ?>
<!-- MODAL ANULAR -->
<div class="modal fade" id="modalAnular" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title">Anular crónica</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" novalidate>
        <input type="hidden" name="accion" value="anular">

        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php endif; ?>

        <input type="hidden" name="cronica_id" id="anular_cronica_id" value="">

        <div class="modal-body">
          <div class="alert alert-warning mb-3">
            Esta acción no elimina la crónica. La marca como anulada y quedará solo para consulta.
          </div>

          <div class="mb-2 text-muted" id="anular_info"></div>

          <div class="mb-3">
            <label class="form-label">Motivo (obligatorio)</label>
            <select name="motivo_anulacion" class="form-select" required>
              <option value="">Seleccione...</option>
              <?php foreach ($MOTIVOS_ANULACION as $k => $label): ?>
                <option value="<?= e($k) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Detalle (recomendado)</label>
            <textarea name="detalle_anulacion" class="form-control" rows="3" placeholder="Explique brevemente el motivo (si aplica)"></textarea>
            <div class="form-text">Si selecciona “Corrección mayor”, el detalle es obligatorio.</div>
          </div>

          <div class="form-check">
            <input class="form-check-input" type="checkbox" value="1" id="confirmAnular" required>
            <label class="form-check-label" for="confirmAnular">
              Confirmo que esta crónica quedará anulada y no se podrá modificar.
            </label>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Anular crónica</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL NUEVA CRÓNICA -->
<div class="modal fade" id="modalNuevaCronica" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Nueva Crónica</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" enctype="multipart/form-data" id="formNuevaCronica" novalidate>
        <input type="hidden" name="accion" value="nueva">

        <?php if (function_exists('csrf_token')): ?>
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <?php endif; ?>

        <div class="modal-body">

          <div id="alertProyectoCerrado" class="alert alert-danger d-none">
            Este proyecto está CERRADO. No se pueden registrar nuevas crónicas hasta que sea reabierto.
          </div>

          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Proyecto</label>
              <select name="proyecto_id" class="form-select select2" onchange="cargarDatosProyecto(this.value)" required>
                <option value="">Seleccione...</option>
                <?php foreach($proyectosMap as $pid => $p): ?>
                  <option value="<?= (int)$pid ?>"><?= e($p['display_nombre'] ?? '') ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Seleccione un proyecto.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Modalidad</label>
              <input type="text" id="modalidadProyecto" class="form-control" readonly>
            </div>

            <div class="col-12">
              <label class="form-label">Metas del proyecto</label>
              <textarea id="metasProyecto" class="form-control" rows="2" readonly></textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label">Encargado</label>
              <select name="encargado_id" class="form-select select2 lock">
                <?php foreach($listaEncargados as $en): ?>
                  <option value="<?= (int)$en['id'] ?>"><?= e($en['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label">Distrito</label>
              <select name="distrito_id" class="form-select select2" id="selectDistrito">
                <?php foreach($listaDistritos as $d): ?>
                  <option value="<?= (int)$d['id'] ?>"><?= e($d['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="hintDistritosProyecto"></div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Tipos de crónica</label>
              <select name="tipo_id[]" id="tiposCronica" class="form-select select2" multiple required>
                <?php foreach($listaTipos as $t): ?>
                  <option value="<?= (int)$t['id'] ?>"><?= e($t['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="invalid-feedback">Seleccione al menos un tipo.</div>
            </div>

            <div class="col-12">
              <label class="form-label">Comentarios (se imprimen en la crónica)</label>
              <textarea name="comentarios" id="editorComentarios" class="form-control"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label">Observaciones internas (no se imprimen)</label>
              <textarea name="observaciones" class="form-control" rows="3" placeholder="Notas internas para el expediente"></textarea>
            </div>

            <div class="col-12">
              <label class="form-label">Imágenes (evidencia) (puedes seleccionar varias)</label>
              <input type="file" id="imagenesInput" name="imagenes[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp" class="form-control">
              <div class="form-text">Para seleccionar varias: mantén presionado Ctrl (o Shift) al elegir archivos.</div>
            </div>

            <div class="col-12">
              <div id="imagenesDescs" class="row g-2"></div>
            </div>

            <div class="col-12">
              <label class="form-label">Archivos adjuntos (capturas o imágenes adicionales)</label>
              <input type="file" id="adjuntosInput" name="adjuntos_img[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp" class="form-control">
              <div class="form-text">Aquí van capturas o imágenes que no son evidencia principal. Se guardan aparte (sin descripción).</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Documentos (PDF, DOCX, XLSX, PPTX)</label>
              <input type="file" id="documentosInput" name="documentos[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="form-control">
            </div>

            <div class="col-md-6">
              <label class="form-label">Firmados (solo PDF)</label>
              <input type="file" id="firmadosInput" name="firmados[]" multiple accept=".pdf" class="form-control">
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<style>
.ck-editor__editable { min-height: 150px !important; font-size: 16px; }
.select2-container { width: 100% !important; z-index: 2000; }
.lock + .select2 .select2-selection { background:#f8f9fa; cursor:not-allowed; }
</style>

<script>
let editor;
let proyectoCerradoActual = false;

// ✅ mapa de proyectos (modalidad, metas, distritos, tipos) generado desde PHP (misma lógica de proyectos.php)
window.PROYECTOS_MAP = <?= json_encode($proyectosMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

$(function() {
  $('.select2').select2({ dropdownParent: $('#modalNuevaCronica'), allowClear: true });

  // Bloquear solo los .lock (encargado sí)
  $('.lock').each(function(){
    const $el = $(this);
    $el.on('select2:opening select2:unselecting select2:clear', e => e.preventDefault());
    $el.next('.select2').find('.select2-selection').on('mousedown keydown', e => e.preventDefault());
  });

  ClassicEditor.create(document.querySelector('#editorComentarios')).then(e => editor = e);

  $('#formNuevaCronica').on('submit', function(e){
    if (proyectoCerradoActual) {
      e.preventDefault();
      e.stopPropagation();
      alert('Este proyecto está CERRADO. Debes reabrirlo para registrar nuevas crónicas.');
      return false;
    }
    if (editor) this.comentarios.value = editor.getData().trim();
    if (!this.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
    this.classList.add('was-validated');
  });

  // Descripciones por imagen (solo evidencia)
  const inputImgs = document.getElementById('imagenesInput');
  const contDescs = document.getElementById('imagenesDescs');

  if (inputImgs && contDescs) {
    inputImgs.addEventListener('change', function() {
      contDescs.innerHTML = '';
      const files = Array.from(inputImgs.files || []);
      if (files.length === 0) return;

      files.forEach((f, idx) => {
        const col = document.createElement('div');
        col.className = 'col-12';
        col.innerHTML = `
          <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <div class="fw-semibold">${escapeHtml(f.name)}</div>
                <span class="badge bg-secondary">Imagen ${idx+1}</span>
              </div>
              <input type="text" class="form-control" name="imagenes_desc[${idx}]"
                     placeholder="Descripción de la imagen (opcional)" maxlength="200">
              <div class="form-text">Ejemplo: “Antes”, “Durante”, “Después”.</div>
            </div>
          </div>
        `;
        contDescs.appendChild(col);
      });
    });
  }

  function escapeHtml(str){
    return String(str)
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;')
      .replaceAll('"','&quot;')
      .replaceAll("'","&#039;");
  }

  const modalAnular = document.getElementById('modalAnular');
  if (modalAnular) {
    modalAnular.addEventListener('show.bs.modal', function (event) {
      const btn = event.relatedTarget;
      const id = btn.getAttribute('data-id') || '';
      const consec = btn.getAttribute('data-consec') || '';

      const inputId = document.getElementById('anular_cronica_id');
      const info = document.getElementById('anular_info');

      if (inputId) inputId.value = id;
      if (info) info.textContent = 'Crónica: ' + (consec ? consec : ('#' + id));
    });
  }
});

function setProyectoCerradoUI(cerrado){
  proyectoCerradoActual = !!cerrado;

  const alertBox = document.getElementById('alertProyectoCerrado');
  const form = document.getElementById('formNuevaCronica');
  const btn = form ? form.querySelector('button[type="submit"]') : null;

  if (proyectoCerradoActual) {
    if (alertBox) alertBox.classList.remove('d-none');
    if (btn) btn.disabled = true;
  } else {
    if (alertBox) alertBox.classList.add('d-none');
    if (btn) btn.disabled = false;
  }
}

function cargarDatosProyecto(id){
  const modInp  = document.getElementById('modalidadProyecto');
  const metasTx = document.getElementById('metasProyecto');
  const hintDis = document.getElementById('hintDistritosProyecto');

  const $enc = $("[name='encargado_id']");
  const $dis = $("#selectDistrito");  // select single
  const $tip = $("#tiposCronica");    // select multiple

  // Reset visual rápido
  setProyectoCerradoUI(false);
  if (modInp)  modInp.value = '';
  if (metasTx) metasTx.value = '';
  if (hintDis) hintDis.textContent = '';

  // Si no hay proyecto seleccionado, restaurar catálogos base (los que vienen en el HTML)
  if (!id) {
    // restaurar tipos (catálogo completo)
    $tip.find('option').prop('disabled', false);
    $tip.val(null).trigger('change');

    // distrito queda como esté (catálogo completo)
    $dis.val("").trigger("change");
    return;
  }

  const url = "cronicas_ajax_proyecto.php?id=" + encodeURIComponent(id) + "&_ts=" + Date.now();

  fetch(url, { cache: "no-store" })
    .then(r => r.json())
    .then(data => {
      if (!data || data.error) {
        console.error("AJAX proyecto:", data && data.error ? data.error : "sin data");
        return;
      }

      // 1) Encargado (lo seteamos si viene)
      if (data.encargado_id) {
        $enc.val(String(data.encargado_id)).trigger('change');
      }

      // 2) Modalidad y metas (readonly)
      if (modInp)  modInp.value  = (data.modalidad_nombre || '').toString();
      if (metasTx) metasTx.value = (data.metas_txt || '').toString();

      // 3) Distritos: SOLO los del proyecto, y que el usuario elija 1
      if (Array.isArray(data.distritos) && data.distritos.length > 0) {
        $dis.empty();
        $dis.append(new Option("Seleccione...", "", true, false));

        data.distritos.forEach(d => {
          $dis.append(new Option(d.nombre, String(d.id), false, false));
        });

        // No autoseleccionar
        $dis.val("").trigger("change");

        if (hintDis && data.distritos.length > 1) {
          hintDis.textContent = "Distritos del proyecto: " + data.distritos.map(x => x.nombre).join(" | ");
        } else if (hintDis) {
          hintDis.textContent = "";
        }
      } else {
        // Si el proyecto no trae distritos (raro), dejamos el catálogo y limpiamos selección
        if (hintDis) hintDis.textContent = "";
        $dis.val("").trigger("change");
      }

      // 4) Tipos: SOLO los permitidos del proyecto
      if (Array.isArray(data.tipos) && data.tipos.length > 0) {
        // Deshabilitar todo y habilitar solo los permitidos
        const allowed = new Set(data.tipos.map(t => String(t.id)));

        $tip.find('option').each(function(){
          const val = String($(this).val() || "");
          $(this).prop('disabled', !allowed.has(val));
        });

        // limpiar selección actual para obligar a elegir
        $tip.val(null).trigger('change');
      } else {
        // si no trae tipos, dejamos catálogo completo habilitado (o lo podés bloquear si querés)
        $tip.find('option').prop('disabled', false);
        $tip.val(null).trigger('change');
      }

      // 5) Cerrado
      const cerrado = (data.proyecto_cerrado === true || data.proyecto_cerrado === 1 || data.proyecto_cerrado === "1");
      setProyectoCerradoUI(cerrado);
    })
    .catch(err => {
      console.error("Error cargando proyecto:", err);
      setProyectoCerradoUI(false);
    });

}
</script>

<script src="/gestion_vial_ui/js/offline_v2.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('formNuevaCronica');
  if (!form) return;

  form.addEventListener('submit', async function(e) {
    if (proyectoCerradoActual) {
      e.preventDefault();
      e.stopPropagation();
      alert('Este proyecto está CERRADO. Debes reabrirlo para registrar nuevas crónicas.');
      return;
    }

    if (navigator.onLine) return;

    e.preventDefault();

    const ok = confirm('Estás sin conexión.\n¿Deseas guardar la crónica localmente y sincronizar cuando haya internet?');
    if (!ok) return;

    const btn = form.querySelector('button[type="submit"]');
    const old = btn.innerHTML;
    btn.innerHTML = 'Guardando offline...';
    btn.disabled = true;

    try {
      const record = await window.offlineSystem.saveOffline(form, (typeof editor !== 'undefined' ? editor : null));
      alert('Crónica guardada offline. Se sincroniza automáticamente al volver internet.');

      if (window.offlineCronicasV2) {
        await window.offlineCronicasV2.renderOfflineRow(record);
      }

      form.reset();
      if (editor) editor.setData('');

      const cont = document.getElementById('imagenesDescs');
      if (cont) cont.innerHTML = '';

      const modInp  = document.getElementById('modalidadProyecto');
      const metasTx = document.getElementById('metasProyecto');
      const hintDis = document.getElementById('hintDistritosProyecto');
      if (modInp)  modInp.value = '';
      if (metasTx) metasTx.value = '';
      if (hintDis) hintDis.textContent = '';

      const modal = bootstrap.Modal.getInstance(document.getElementById('modalNuevaCronica'));
      if (modal) modal.hide();

    } catch (err) {
      alert('Error guardando offline: ' + (err && err.message ? err.message : 'desconocido'));
    } finally {
      btn.innerHTML = old;
      btn.disabled = false;
    }
  });
});

function forzarSincronizacion() {
  if (window.offlineSystem) {
    window.offlineSystem.syncNow();
  } else {
    alert('Sistema offline no inicializado');
  }
}

window.addEventListener('load', function() {
  const pendientes = JSON.parse(localStorage.getItem('cronicas_pendientes') || '[]');
  if (pendientes.length > 0 && navigator.onLine) {
    const sincronizar = confirm(`Tienes ${pendientes.length} crónica(s) pendientes de sincronizar.\n¿Deseas sincronizar ahora?`);
    if (sincronizar) forzarSincronizacion();
  }
});
</script>

<div style="position: fixed; bottom: 20px; right: 20px; z-index: 1000;">
  <button onclick="forzarSincronizacion()" class="btn btn-warning btn-lg shadow">
    🔄 Sincronizar
  </button>
</div>

<?php include "../../includes/footer.php"; ?>
