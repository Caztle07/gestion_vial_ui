<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

if (!can_edit("proyectos")) {
    echo "<h2>❌ No tiene permiso para crear/guardar proyectos.</h2>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// 🔐 Validar CSRF
$csrf = $_POST['_csrf'] ?? '';
if (!csrf_check($csrf)) {
    die("CSRF token inválido o sesión expirada.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: proyectos.php");
    exit;
}

// ==========================
// HELPERS
// ==========================
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

function getDataBasePath(): ?string {
    $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . "..";
    $rootGuess = realpath($rootGuess) ?: $rootGuess;

    $root = realpath($rootGuess . DIRECTORY_SEPARATOR . "..") ?: ($rootGuess . DIRECTORY_SEPARATOR . "..");

    $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
    if (!is_dir($data)) @mkdir($data, 0775, true);

    $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";
    if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

    if (!is_dir($data) || !is_dir($proyectos)) return null;
    return $data;
}

function filesToLists(array $files): array {
    if (!isset($files["name"])) {
        return ["name"=>[], "tmp_name"=>[], "error"=>[], "size"=>[]];
    }

    $names = is_array($files["name"]) ? $files["name"] : [$files["name"]];
    $tmps  = is_array($files["tmp_name"]) ? $files["tmp_name"] : [$files["tmp_name"]];
    $errs  = is_array($files["error"]) ? $files["error"] : [$files["error"]];
    $sizes = isset($files["size"])
        ? (is_array($files["size"]) ? $files["size"] : [$files["size"]])
        : [];

    return ["name"=>$names, "tmp_name"=>$tmps, "error"=>$errs, "size"=>$sizes];
}

function subirDocsGenerales(array $files, string $destDir, ?int $maxBytes = null): array {
    $guardados = [];
    $errores   = [];

    $permitidas = ['pdf','doc','docx','xls','xlsx','ppt','pptx'];
    $bloqueadas = ['php','phtml','php3','php4','php5','php7','phar','cgi','pl','exe','sh','bat','cmd'];

    $L = filesToLists($files);
    $names = $L["name"];
    $tmps  = $L["tmp_name"];
    $errs  = $L["error"];
    $sizes = $L["size"];

    if (count($names) === 0 || (count($names) === 1 && trim((string)$names[0]) === '')) {
        return ["guardados"=>$guardados, "errores"=>$errores];
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0775, true)) {
        return ["guardados"=>$guardados, "errores"=>["No se pudo crear carpeta: $destDir"]];
    }

    $htaccessPath = rtrim($destDir, "/\\") . DIRECTORY_SEPARATOR . ".htaccess";
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
    }

    foreach ($names as $i => $name) {
        $name = (string)$name;
        $tmp  = (string)($tmps[$i] ?? '');
        $err  = (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE);
        $size = isset($sizes[$i]) ? (int)$sizes[$i] : null;

        if ($err === UPLOAD_ERR_NO_FILE) continue;

        if ($err !== UPLOAD_ERR_OK) { $errores[] = "Error subiendo '$name' (código $err)."; continue; }
        if ($maxBytes !== null && $size !== null && $size > $maxBytes) { $errores[] = "Archivo '$name' excede tamaño permitido."; continue; }
        if (!is_uploaded_file($tmp)) { $errores[] = "Archivo temporal inválido para '$name'."; continue; }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $bloqueadas, true)) { $errores[] = "Extensión bloqueada para '$name'."; continue; }
        if (!in_array($ext, $permitidas, true)) { $errores[] = "Extensión no permitida para '$name'."; continue; }

        $baseName  = preg_replace("/[^A-Za-z0-9_\-\.]/", "_", pathinfo($name, PATHINFO_FILENAME));
        $seguro    = "doc_" . uniqid('', true) . "_" . $baseName . "." . $ext;
        $dest      = rtrim($destDir, "/\\") . DIRECTORY_SEPARATOR . $seguro;

        if (move_uploaded_file($tmp, $dest)) $guardados[] = $seguro;
        else $errores[] = "No se pudo mover '$name'.";
    }

    return ["guardados"=>$guardados, "errores"=>$errores];
}

function slugify_simple(string $s): string {
    $s = trim($s);
    if ($s === '') return 'proyecto';
    $s = preg_replace('/[^A-Za-z0-9_\-]/', '_', $s);
    $s = preg_replace('/_+/', '_', $s);
    return trim($s, '_');
}

function validar_ids_existentes(mysqli $conn, string $tabla, array $ids, string $whereExtra = ''): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (empty($ids)) return [];

    $place = implode(",", array_fill(0, count($ids), "?"));
    $sql = "SELECT id FROM {$tabla} WHERE id IN ($place) " . ($whereExtra ? " AND $whereExtra" : "");
    $stmt = $conn->prepare($sql);

    $types = str_repeat("i", count($ids));
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $res = $stmt->get_result();

    $ok = [];
    while ($row = $res->fetch_assoc()) $ok[] = (int)$row['id'];
    $stmt->close();

    sort($ok);
    return $ok;
}

// ==========================
// DATOS DEL FORMULARIO
// ==========================
$nombre      = trim($_POST['nombre'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');

$caminosSel = isset($_POST['caminos']) ? (array)$_POST['caminos'] : [];
$caminosSel = array_values(array_unique(array_filter(array_map('intval', $caminosSel), fn($x) => $x > 0)));

$distritosSel = isset($_POST['distritos']) ? (array)$_POST['distritos'] : [];
$distritosSel = array_values(array_unique(array_filter(array_map('intval', $distritosSel), fn($x) => $x > 0)));

$distrito_id_single = (($_POST['distrito_id'] ?? '') !== '') ? (int)$_POST['distrito_id'] : 0;
if (empty($distritosSel) && $distrito_id_single > 0) $distritosSel = [$distrito_id_single];

$encargado_id = (($_POST['encargado_id'] ?? '') !== '') ? (int)$_POST['encargado_id'] : 0;
$modalidad_id = (($_POST['modalidad_id'] ?? '') !== '') ? (int)$_POST['modalidad_id'] : 0;

$estado       = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;
$fecha_inicio = $_POST['fecha_inicio'] ?? '';
$fecha_fin    = $_POST['fecha_fin']    ?? '';
$avance       = isset($_POST['avance']) ? (int)$_POST['avance'] : 0;

$monto_inicial = (isset($_POST['monto_inicial']) && $_POST['monto_inicial'] !== '') ? (float)$_POST['monto_inicial'] : null;

// tipos (multi)
$tipos = isset($_POST['tipo_id']) ? (array)$_POST['tipo_id'] : [];
$tipos = array_values(array_unique(array_filter(array_map('intval', $tipos), fn($x) => $x > 0)));

// ✅ metas (multi)
$metas = isset($_POST['metas']) ? (array)$_POST['metas'] : [];
$metas = array_values(array_unique(array_filter(array_map('intval', $metas), fn($x) => $x > 0)));

// ==========================
// VALIDACIONES
// ==========================
$errores = [];

if ($nombre === '')      $errores[] = "Falta nombre.";
if ($descripcion === '') $errores[] = "Falta descripción.";

if (empty($caminosSel))   $errores[] = "Debe seleccionar al menos un camino.";
if (empty($distritosSel)) $errores[] = "Debe seleccionar al menos un distrito.";

if ($fecha_inicio === '' || $fecha_fin === '') $errores[] = "Debe ingresar fecha de inicio y fecha de fin.";
elseif ($fecha_inicio > $fecha_fin) $errores[] = "La fecha de inicio no puede ser posterior a la de fin.";

if (!in_array($estado, [1,2,3,4], true)) $estado = 1;
if ($modalidad_id <= 0) $errores[] = "Debe seleccionar una modalidad.";
if ($encargado_id <= 0) $errores[] = "Debe seleccionar un encargado.";

// ✅ validar metas solo si existen tablas
$tieneMetasCatalogo = table_exists($conn, "metas_proyecto");
$tieneMetasPuente   = table_exists($conn, "proyecto_metas");
$tieneMetas         = $tieneMetasCatalogo && $tieneMetasPuente;

if ($tieneMetas) {
    if (empty($metas)) $errores[] = "Debe seleccionar al menos una meta.";
}

if (!empty($errores)) {
    header("Location: proyectos.php?err=" . urlencode(implode(" | ", $errores)));
    exit;
}

// validar modalidad exista
$stmtValMod = $conn->prepare("SELECT id FROM modalidades WHERE id = ? LIMIT 1");
$stmtValMod->bind_param("i", $modalidad_id);
$stmtValMod->execute();
$existeMod = $stmtValMod->get_result()->num_rows > 0;
$stmtValMod->close();
if (!$existeMod) { header("Location: proyectos.php?err=" . urlencode("Modalidad inválida.")); exit; }

// validar encargado exista
$stmtValEnc = $conn->prepare("SELECT id FROM encargados WHERE id = ? LIMIT 1");
$stmtValEnc->bind_param("i", $encargado_id);
$stmtValEnc->execute();
$existeEnc = $stmtValEnc->get_result()->num_rows > 0;
$stmtValEnc->close();
if (!$existeEnc) { header("Location: proyectos.php?err=" . urlencode("Encargado inválido.")); exit; }

// validar caminos existan
$idsCamOk = validar_ids_existentes($conn, "caminos", $caminosSel);
$tmpCam = $caminosSel; sort($tmpCam);
if ($idsCamOk !== $tmpCam) { header("Location: proyectos.php?err=" . urlencode("Uno o más caminos seleccionados no existen.")); exit; }

// validar distritos existan y activos
$idsDisOk = validar_ids_existentes($conn, "distritos", $distritosSel, "activo = 1");
$tmpDis = $distritosSel; sort($tmpDis);
if ($idsDisOk !== $tmpDis) { header("Location: proyectos.php?err=" . urlencode("Uno o más distritos seleccionados no existen o están inactivos.")); exit; }

// validar tipos contra tareas_catalogo
if (!empty($tipos)) {
    $idsTiposOk = validar_ids_existentes($conn, "tareas_catalogo", $tipos);
    $tmpTipos = $tipos; sort($tmpTipos);
    if ($idsTiposOk !== $tmpTipos) {
        header("Location: proyectos.php?err=" . urlencode("Uno o más tipos seleccionados no existen en el catálogo."));
        exit;
    }
}

// ✅ validar metas contra metas_proyecto (activo=1)
if ($tieneMetas && !empty($metas)) {
    $idsMetasOk = validar_ids_existentes($conn, "metas_proyecto", $metas, "activo = 1");
    $tmpMetas = $metas; sort($tmpMetas);
    if ($idsMetasOk !== $tmpMetas) {
        header("Location: proyectos.php?err=" . urlencode("Una o más metas seleccionadas no existen o están inactivas."));
        exit;
    }
}

// ==========================
// AÑO CONSECUTIVO
// ==========================
$anio_proyecto = (int)date('Y', strtotime($fecha_inicio));

// compatibilidad columnas viejas
$inventario_id = (int)$caminosSel[0];
$distrito_id   = (int)$distritosSel[0];

$tienePuenteCaminos   = table_exists($conn, "proyecto_caminos");
$tienePuenteDistritos = table_exists($conn, "proyecto_distritos");
$tieneTablaMontos     = table_exists($conn, "proyectos_montos");

try {
    $conn->begin_transaction();

    $sqlMax = "
        SELECT COALESCE(MAX(consecutivo_numero), 0) AS max_num
        FROM proyectos
        WHERE YEAR(fecha_inicio) = ?
    ";
    $stmtMax = $conn->prepare($sqlMax);
    $stmtMax->bind_param("i", $anio_proyecto);
    $stmtMax->execute();
    $rowMax = $stmtMax->get_result()->fetch_assoc();
    $stmtMax->close();

    $consecutivo_numero = ((int)($rowMax['max_num'] ?? 0)) + 1;

    $sql = "INSERT INTO proyectos
            (nombre, descripcion, inventario_id, distrito_id,
             fecha_inicio, fecha_fin, encargado_id, modalidad_id,
             estado, avance, activo, created_at, consecutivo_numero,
             cerrado, cerrado_por, cerrado_en)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), ?, 0, NULL, NULL)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "ssiissiiiii",
        $nombre,
        $descripcion,
        $inventario_id,
        $distrito_id,
        $fecha_inicio,
        $fecha_fin,
        $encargado_id,
        $modalidad_id,
        $estado,
        $avance,
        $consecutivo_numero
    );
    $stmt->execute();
    $proyecto_id = (int)$conn->insert_id;
    $stmt->close();

    if ($tienePuenteCaminos) {
        $sqlPC  = "INSERT INTO proyecto_caminos (proyecto_id, camino_id) VALUES (?, ?)";
        $stmtPC = $conn->prepare($sqlPC);
        foreach ($caminosSel as $camino_id) {
            $stmtPC->bind_param("ii", $proyecto_id, $camino_id);
            $stmtPC->execute();
        }
        $stmtPC->close();
    }

    if ($tienePuenteDistritos) {
        $sqlPD  = "INSERT INTO proyecto_distritos (proyecto_id, distrito_id) VALUES (?, ?)";
        $stmtPD = $conn->prepare($sqlPD);
        foreach ($distritosSel as $did) {
            $stmtPD->bind_param("ii", $proyecto_id, $did);
            $stmtPD->execute();
        }
        $stmtPD->close();
    }

    if (!empty($tipos)) {
        $sqlTipo  = "INSERT INTO proyecto_tipos (proyecto_id, tipo_id) VALUES (?, ?)";
        $stmtTipo = $conn->prepare($sqlTipo);
        foreach ($tipos as $tipo_id) {
            $stmtTipo->bind_param("ii", $proyecto_id, $tipo_id);
            $stmtTipo->execute();
        }
        $stmtTipo->close();
    }

    // ✅ INSERT METAS (si existe)
    if ($tieneMetas && !empty($metas)) {
        $sqlPM  = "INSERT INTO proyecto_metas (proyecto_id, meta_id) VALUES (?, ?)";
        $stmtPM = $conn->prepare($sqlPM);
        foreach ($metas as $meta_id) {
            $stmtPM->bind_param("ii", $proyecto_id, $meta_id);
            $stmtPM->execute();
        }
        $stmtPM->close();
    }

    if ($monto_inicial !== null && $monto_inicial >= 0) {
        $stmtUp = $conn->prepare("UPDATE proyectos SET monto_invertido = ? WHERE id = ?");
        $stmtUp->bind_param("di", $monto_inicial, $proyecto_id);
        $stmtUp->execute();
        $stmtUp->close();

        if ($tieneTablaMontos) {
            $nota  = "Monto inicial";
            $fecha = $fecha_inicio;
            $stmtM = $conn->prepare("
                INSERT INTO proyectos_montos (proyecto_id, fecha, monto, nota, creado_en)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmtM->bind_param("isds", $proyecto_id, $fecha, $monto_inicial, $nota);
            $stmtM->execute();
            $stmtM->close();
        }
    }

    // ==========================
    // SUBIR DOCS GENERALES (carpeta por ID)
    // ==========================
    $baseData = getDataBasePath();
    if (!$baseData) throw new Exception("No se pudo acceder/crear la carpeta /data del sistema.");

    $baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . (string)$proyecto_id;
    $rutaDocsGen  = $baseProyecto . DIRECTORY_SEPARATOR . "docs_generales";

    if (!is_dir($baseProyecto) && !mkdir($baseProyecto, 0775, true)) {
        throw new Exception("No se pudo crear carpeta del proyecto en /data/proyectos/{$proyecto_id}.");
    }

    $resDocs = ["guardados"=>[], "errores"=>[]];
    if (isset($_FILES['docs_generales']) && is_array($_FILES['docs_generales'])) {
        $resDocs = subirDocsGenerales($_FILES['docs_generales'], $rutaDocsGen, 15 * 1024 * 1024);
    }

    $conn->commit();

    if (function_exists('log_accion')) {
        $carpetaSlug = slugify_simple($nombre);
        $detalle_log = json_encode([
            'proyecto_id'  => $proyecto_id,
            'accion'       => 'crear',
            'datos_nuevos' => [
                'nombre'               => $nombre,
                'descripcion'          => $descripcion,
                'caminos'              => $caminosSel,
                'distritos'            => $distritosSel,
                'inventario_id'        => $inventario_id,
                'distrito_id'          => $distrito_id,
                'encargado_id'         => $encargado_id,
                'modalidad_id'         => $modalidad_id,
                'estado'               => $estado,
                'fecha_inicio'         => $fecha_inicio,
                'fecha_fin'            => $fecha_fin,
                'avance'               => $avance,
                'consecutivo_numero'   => $consecutivo_numero,
                'anio_proyecto'        => $anio_proyecto,
                'tipos'                => $tipos,
                'metas'                => ($tieneMetas ? $metas : []),
                'monto_inicial'        => $monto_inicial,
                'docs_generales'       => $resDocs['guardados'],
                'docs_warnings'        => $resDocs['errores'],
                'carpeta_proyecto_id'  => (string)$proyecto_id,
                'carpeta_slug_info'    => $carpetaSlug
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        log_accion($conn, 'PROYECTO_CREAR', $detalle_log);
    }

    if (!empty($resDocs['errores'])) {
        header("Location: proyectos.php?ok=" . urlencode("Proyecto creado. Algunos documentos no se pudieron subir."));
    } else {
        header("Location: proyectos.php?ok=" . urlencode("Proyecto creado correctamente."));
    }
    exit;

} catch (Exception $e) {
    $conn->rollback();

    echo "<h2>Error al guardar el proyecto</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</pre>";
    exit;
}
