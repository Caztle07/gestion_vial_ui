<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario debe estar logueado
require_login();

// 2) Solo roles que pueden editar proyectos (admin + ingeniero)
if (!can_edit("proyectos")) {
    header('Content-Type: text/html; charset=utf-8');
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>❌ No tiene permiso para editar proyectos.</div>";
    include "../../includes/footer.php";
    exit;
}

// 3) Charset / headers (antes de sacar HTML)
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// helper e()
if (!function_exists('e')) {
    function e($s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

// ID puede venir por GET (url) o por POST (hidden)
$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>ID de proyecto inválido</div>";
    include "../../includes/footer.php";
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

// =======================
// FLAGS / COMPATIBILIDAD
// =======================
$tienePuenteCaminos   = table_exists($conn, "proyecto_caminos");
$tienePuenteDistritos = table_exists($conn, "proyecto_distritos");

// ✅ METAS (compatibilidad)
$tieneMetasCatalogo = table_exists($conn, "metas_proyecto");
$tieneMetasPuente   = table_exists($conn, "proyecto_metas");
$tieneMetas         = $tieneMetasCatalogo && $tieneMetasPuente;

// =======================
// GUARDAR CAMBIOS (POST)
// =======================
$errorGuardar = '';
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $csrf = $_POST['_csrf'] ?? '';
    if (!csrf_check($csrf)) {
        $errorGuardar = "CSRF token inválido o sesión expirada.";
    } else {
        try {
            $conn->begin_transaction();

            $nombre      = trim($_POST['nombre'] ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');

            // CAMINOS MULTI
            $caminosSel = isset($_POST['caminos']) ? (array)$_POST['caminos'] : [];
            $caminosSel = array_values(array_unique(array_filter(array_map('intval', $caminosSel), fn($x) => $x > 0)));
            $inventario_id = !empty($caminosSel) ? (int)$caminosSel[0] : 0;

            // DISTRITOS MULTI
            $distritosSel = isset($_POST['distritos']) ? (array)$_POST['distritos'] : [];
            $distritosSel = array_values(array_unique(array_filter(array_map('intval', $distritosSel), fn($x) => $x > 0)));
            $distrito_id = (!empty($distritosSel)) ? (int)$distritosSel[0] : (int)($_POST['distrito_id'] ?? 0);

            $encargado_id = (($_POST['encargado_id'] ?? '') !== '') ? (int)$_POST['encargado_id'] : 0;
            $modalidad_id = (($_POST['modalidad_id'] ?? '') !== '') ? (int)$_POST['modalidad_id'] : 0;

            $fecha_inicio = $_POST['fecha_inicio'] ?? '';
            $fecha_fin    = $_POST['fecha_fin']    ?? '';

            $estado = isset($_POST['estado']) ? (int)$_POST['estado'] : 1;
            $avance = isset($_POST['avance']) ? (int)$_POST['avance'] : 0;

            $monto_invertido = isset($_POST['monto_invertido']) ? (float)$_POST['monto_invertido'] : 0.0;
            if ($monto_invertido < 0) $monto_invertido = 0;

            // tipos (multi)
            $tipos = isset($_POST['tipos']) && is_array($_POST['tipos'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['tipos']), fn($x) => $x > 0)))
                : [];

            // ✅ metas (multi)
            $metas = isset($_POST['metas']) && is_array($_POST['metas'])
                ? array_values(array_unique(array_filter(array_map('intval', $_POST['metas']), fn($x) => $x > 0)))
                : [];

            // Validaciones
            $errores = [];
            if ($nombre === '')              $errores[] = "El nombre es obligatorio.";
            if ($descripcion === '')         $errores[] = "La descripción es obligatoria.";
            if (empty($caminosSel))          $errores[] = "Debe seleccionar al menos un camino.";

            if ($tienePuenteDistritos) {
                if (empty($distritosSel))   $errores[] = "Debe seleccionar al menos un distrito.";
            } else {
                if ($distrito_id <= 0)      $errores[] = "Debe seleccionar un distrito.";
            }

            if ($encargado_id <= 0)          $errores[] = "Debe seleccionar un encargado.";
            if ($modalidad_id <= 0)          $errores[] = "Debe seleccionar una modalidad.";
            if (empty($tipos))               $errores[] = "Debe seleccionar al menos un tipo de proyecto.";
            if ($fecha_inicio === '')        $errores[] = "Debe ingresar una fecha de inicio.";
            if ($fecha_fin === '')           $errores[] = "Debe ingresar una fecha de fin.";
            if ($fecha_inicio !== '' && $fecha_fin !== '' && $fecha_inicio > $fecha_fin)
                                           $errores[] = "La fecha de inicio no puede ser posterior a la de fin.";
            if (!in_array($estado, [1,2,3,4], true))
                                           $errores[] = "Estado de proyecto inválido.";

            // ✅ exigir metas solo si existe feature
            if ($tieneMetas && empty($metas)) {
                $errores[] = "Debe seleccionar al menos una meta.";
            }

            // Validar caminos existan
            if (!empty($caminosSel)) {
                $place = implode(",", array_fill(0, count($caminosSel), "?"));
                $sqlVal = "SELECT id FROM caminos WHERE id IN ($place)";
                $stmtVal = $conn->prepare($sqlVal);
                $typesVal = str_repeat("i", count($caminosSel));
                $stmtVal->bind_param($typesVal, ...$caminosSel);
                $stmtVal->execute();
                $resVal = $stmtVal->get_result();
                $idsOk = [];
                while ($r = $resVal->fetch_assoc()) $idsOk[] = (int)$r['id'];
                $stmtVal->close();

                sort($idsOk);
                $tmp = $caminosSel; sort($tmp);
                if ($idsOk !== $tmp) $errores[] = "Uno o más caminos seleccionados no existen.";
            }

            // Validar distritos existan
            if ($tienePuenteDistritos && !empty($distritosSel)) {
                $placeD = implode(",", array_fill(0, count($distritosSel), "?"));
                $sqlValD = "SELECT id FROM distritos WHERE id IN ($placeD) AND activo = 1";
                $stmtValD = $conn->prepare($sqlValD);
                $typesValD = str_repeat("i", count($distritosSel));
                $stmtValD->bind_param($typesValD, ...$distritosSel);
                $stmtValD->execute();
                $resValD = $stmtValD->get_result();
                $idsOkD = [];
                while ($r = $resValD->fetch_assoc()) $idsOkD[] = (int)$r['id'];
                $stmtValD->close();

                sort($idsOkD);
                $tmpD = $distritosSel; sort($tmpD);
                if ($idsOkD !== $tmpD) $errores[] = "Uno o más distritos seleccionados no existen o están inactivos.";
            }

            // ✅ validar metas existan y estén activas
            if ($tieneMetas && !empty($metas)) {
                $placeM = implode(",", array_fill(0, count($metas), "?"));
                $sqlValM = "SELECT id FROM metas_proyecto WHERE id IN ($placeM) AND activo = 1";
                $stmtValM = $conn->prepare($sqlValM);
                $typesValM = str_repeat("i", count($metas));
                $stmtValM->bind_param($typesValM, ...$metas);
                $stmtValM->execute();
                $resValM = $stmtValM->get_result();
                $idsOkM = [];
                while ($r = $resValM->fetch_assoc()) $idsOkM[] = (int)$r['id'];
                $stmtValM->close();

                sort($idsOkM);
                $tmpM = $metas; sort($tmpM);
                if ($idsOkM !== $tmpM) $errores[] = "Una o más metas seleccionadas no existen o están inactivas.";
            }

            if (!empty($errores)) throw new Exception(implode(" | ", $errores));

            // cargar antes (para log)
            $proyecto_antes  = null;
            $tipos_antes     = [];
            $caminos_antes   = [];
            $distritos_antes = [];
            $metas_antes     = [];

            $stmtAntes = $conn->prepare("SELECT * FROM proyectos WHERE id = ?");
            $stmtAntes->bind_param("i", $id);
            $stmtAntes->execute();
            $resAntes = $stmtAntes->get_result();
            if ($resAntes && $resAntes->num_rows > 0) $proyecto_antes = $resAntes->fetch_assoc();
            $stmtAntes->close();

            $stmtTA = $conn->prepare("SELECT tipo_id FROM proyecto_tipos WHERE proyecto_id = ?");
            $stmtTA->bind_param("i", $id);
            $stmtTA->execute();
            $resTA = $stmtTA->get_result();
            while ($row = $resTA->fetch_assoc()) $tipos_antes[] = (int)$row['tipo_id'];
            $stmtTA->close();

            if ($tienePuenteCaminos) {
                $stmtCA = $conn->prepare("SELECT camino_id FROM proyecto_caminos WHERE proyecto_id = ?");
                $stmtCA->bind_param("i", $id);
                $stmtCA->execute();
                $resCA = $stmtCA->get_result();
                while ($row = $resCA->fetch_assoc()) $caminos_antes[] = (int)$row['camino_id'];
                $stmtCA->close();
            }

            if ($tienePuenteDistritos) {
                $stmtDA = $conn->prepare("SELECT distrito_id FROM proyecto_distritos WHERE proyecto_id = ?");
                $stmtDA->bind_param("i", $id);
                $stmtDA->execute();
                $resDA = $stmtDA->get_result();
                while ($row = $resDA->fetch_assoc()) $distritos_antes[] = (int)$row['distrito_id'];
                $stmtDA->close();
            }

            if ($tieneMetas) {
                $stmtMA = $conn->prepare("SELECT meta_id FROM proyecto_metas WHERE proyecto_id = ?");
                $stmtMA->bind_param("i", $id);
                $stmtMA->execute();
                $resMA = $stmtMA->get_result();
                while ($row = $resMA->fetch_assoc()) $metas_antes[] = (int)$row['meta_id'];
                $stmtMA->close();
            }

            // UPDATE proyectos
            $sql = "UPDATE proyectos
                    SET nombre = ?, descripcion = ?, inventario_id = ?, distrito_id = ?,
                        fecha_inicio = ?, fecha_fin = ?,
                        encargado_id = ?, modalidad_id = ?, estado = ?, avance = ?,
                        monto_invertido = ?
                    WHERE id = ?";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param(
                "ssiissiisidi",
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
                $monto_invertido,
                $id
            );
            $stmt->execute();
            $stmt->close();

            // CAMINOS: reset + insert
            if ($tienePuenteCaminos) {
                $stmtDelC = $conn->prepare("DELETE FROM proyecto_caminos WHERE proyecto_id = ?");
                $stmtDelC->bind_param("i", $id);
                $stmtDelC->execute();
                $stmtDelC->close();

                $stmtInsC = $conn->prepare("INSERT INTO proyecto_caminos (proyecto_id, camino_id) VALUES (?, ?)");
                foreach ($caminosSel as $camino_id) {
                    $stmtInsC->bind_param("ii", $id, $camino_id);
                    $stmtInsC->execute();
                }
                $stmtInsC->close();
            }

            // DISTRITOS: reset + insert
            if ($tienePuenteDistritos) {
                $stmtDelD = $conn->prepare("DELETE FROM proyecto_distritos WHERE proyecto_id = ?");
                $stmtDelD->bind_param("i", $id);
                $stmtDelD->execute();
                $stmtDelD->close();

                $stmtInsD = $conn->prepare("INSERT INTO proyecto_distritos (proyecto_id, distrito_id) VALUES (?, ?)");
                foreach ($distritosSel as $did) {
                    $stmtInsD->bind_param("ii", $id, $did);
                    $stmtInsD->execute();
                }
                $stmtInsD->close();
            }

            // TIPOS: reset + insert
            $stmtDel = $conn->prepare("DELETE FROM proyecto_tipos WHERE proyecto_id = ?");
            $stmtDel->bind_param("i", $id);
            $stmtDel->execute();
            $stmtDel->close();

            $stmtTipo = $conn->prepare("INSERT INTO proyecto_tipos (proyecto_id, tipo_id) VALUES (?, ?)");
            foreach ($tipos as $tipo_id) {
                $stmtTipo->bind_param("ii", $id, $tipo_id);
                $stmtTipo->execute();
            }
            $stmtTipo->close();

            // ✅ METAS: reset + insert
            if ($tieneMetas) {
                $stmtDelM = $conn->prepare("DELETE FROM proyecto_metas WHERE proyecto_id = ?");
                $stmtDelM->bind_param("i", $id);
                $stmtDelM->execute();
                $stmtDelM->close();

                if (!empty($metas)) {
                    $stmtInsM = $conn->prepare("INSERT INTO proyecto_metas (proyecto_id, meta_id) VALUES (?, ?)");
                    foreach ($metas as $meta_id) {
                        $stmtInsM->bind_param("ii", $id, $meta_id);
                        $stmtInsM->execute();
                    }
                    $stmtInsM->close();
                }
            }

            // DOCS GENERALES
            $baseData = getDataBasePath();
            if (!$baseData) throw new Exception("No se pudo acceder/crear la carpeta /data del sistema.");

            $baseProyecto = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $id;
            $rutaDocsGen  = $baseProyecto . DIRECTORY_SEPARATOR . "docs_generales";

            if (!is_dir($baseProyecto) && !mkdir($baseProyecto, 0775, true)) {
                throw new Exception("No se pudo crear carpeta del proyecto en /data/proyectos.");
            }

            $resDocs = ["guardados"=>[], "errores"=>[]];
            if (isset($_FILES['docs_generales']) && is_array($_FILES['docs_generales'])) {
                $resDocs = subirDocsGenerales($_FILES['docs_generales'], $rutaDocsGen, 15 * 1024 * 1024);
            }

            $conn->commit();

            if (function_exists('log_accion')) {
                $detalle_log = json_encode([
                    'proyecto_id' => $id,
                    'accion'      => 'actualizar',
                    'antes'       => [
                        'proyecto'  => $proyecto_antes,
                        'tipos'     => $tipos_antes,
                        'caminos'   => $caminos_antes,
                        'distritos' => $distritos_antes,
                        'metas'     => $metas_antes,
                    ],
                    'despues'     => [
                        'nombre'          => $nombre,
                        'descripcion'     => $descripcion,
                        'inventario_id'   => $inventario_id,
                        'caminos'         => $caminosSel,
                        'distrito_id'     => $distrito_id,
                        'distritos'       => $distritosSel,
                        'fecha_inicio'    => $fecha_inicio,
                        'fecha_fin'       => $fecha_fin,
                        'encargado_id'    => $encargado_id,
                        'modalidad_id'    => $modalidad_id,
                        'estado'          => $estado,
                        'avance'          => $avance,
                        'monto_invertido' => $monto_invertido,
                        'tipos'           => $tipos,
                        'metas'           => ($tieneMetas ? $metas : []),
                        'docs_subidos'    => $resDocs['guardados'],
                        'docs_warnings'   => $resDocs['errores'],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                log_accion($conn, 'PROYECTO_ACTUALIZAR', $detalle_log);
            }

            header("Location: proyecto_editar.php?id=" . $id . "&ok=1");
            exit;

        } catch (Exception $e) {
            $conn->rollback();

            if (function_exists('log_accion')) {
                $detalle_error = json_encode([
                    'proyecto_id' => $id,
                    'error'       => $e->getMessage()
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                log_accion($conn, 'ERROR_PROYECTO_EDITAR', $detalle_error);
            }

            $errorGuardar = $e->getMessage();
        }
    }
}

// =======================
// CARGAR DATOS proyecto (GET)
// =======================
$q = $conn->prepare("SELECT p.* FROM proyectos p WHERE p.id = ?");
$q->bind_param("i", $id);
$q->execute();
$proy = $q->get_result()->fetch_assoc();
$q->close();

if (!$proy) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-3'>Proyecto no encontrado</div>";
    include "../../includes/footer.php";
    exit;
}

// =======================
// CAMINOS ASIGNADOS
// =======================
$caminosAsignados = [];
$caminosAsignadosTxt = [];

if ($tienePuenteCaminos) {
    $stmtCA = $conn->prepare("
        SELECT c.id, c.codigo, IFNULL(c.nombre,'') AS nombre
        FROM proyecto_caminos pc
        INNER JOIN caminos c ON c.id = pc.camino_id
        WHERE pc.proyecto_id = ?
        ORDER BY c.codigo
    ");
    $stmtCA->bind_param("i", $id);
    $stmtCA->execute();
    $resCA = $stmtCA->get_result();
    while ($r = $resCA->fetch_assoc()) {
        $cid = (int)$r['id'];
        $caminosAsignados[$cid] = true;
        $caminosAsignadosTxt[] = trim(($r['codigo'] ?? '') . ' - ' . ($r['nombre'] ?? ''));
    }
    $stmtCA->close();
}
if (empty($caminosAsignados) && (int)($proy['inventario_id'] ?? 0) > 0) {
    $cid = (int)$proy['inventario_id'];
    $caminosAsignados[$cid] = true;
}

// =======================
// DISTRITOS ASIGNADOS (multi)
// =======================
$distritosAsignados = [];
$distritosAsignadosTxt = [];

if ($tienePuenteDistritos) {
    $stmtDA = $conn->prepare("
        SELECT d.id, d.nombre
        FROM proyecto_distritos pd
        INNER JOIN distritos d ON d.id = pd.distrito_id
        WHERE pd.proyecto_id = ?
        ORDER BY d.nombre
    ");
    $stmtDA->bind_param("i", $id);
    $stmtDA->execute();
    $resDA = $stmtDA->get_result();
    while ($r = $resDA->fetch_assoc()) {
        $did = (int)$r['id'];
        $distritosAsignados[$did] = true;
        $distritosAsignadosTxt[] = (string)($r['nombre'] ?? '');
    }
    $stmtDA->close();
}
if (empty($distritosAsignados) && (int)($proy['distrito_id'] ?? 0) > 0) {
    $did = (int)$proy['distrito_id'];
    $distritosAsignados[$did] = true;
}

// =======================
// ✅ METAS ASIGNADAS + CATÁLOGO
// =======================
$metasCatalogo = null;
$metasAsignadas = [];

if ($tieneMetasCatalogo) {
    $metasCatalogo = $conn->query("SELECT id, nombre, descripcion FROM metas_proyecto WHERE activo = 1 ORDER BY nombre");
}

if ($tieneMetas) {
    $stmtMA = $conn->prepare("SELECT meta_id FROM proyecto_metas WHERE proyecto_id = ?");
    $stmtMA->bind_param("i", $id);
    $stmtMA->execute();
    $resMA = $stmtMA->get_result();
    while ($row = $resMA->fetch_assoc()) $metasAsignadas[(int)$row['meta_id']] = true;
    $stmtMA->close();
}

// =======================
// RUTA DOCS GENERALES (listar existentes)
// =======================
$baseData = getDataBasePath();
$docsGenerales = [];
$rutaDocsGenWeb = '';
if ($baseData) {
    $rutaDocsGenFS  = rtrim($baseData, "/\\") . DIRECTORY_SEPARATOR . "proyectos" . DIRECTORY_SEPARATOR . $id . DIRECTORY_SEPARATOR . "docs_generales";
    $rutaDocsGenWeb = "../../data/proyectos/" . $id . "/docs_generales";

    if (is_dir($rutaDocsGenFS)) {
        foreach (scandir($rutaDocsGenFS) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (is_file($rutaDocsGenFS . DIRECTORY_SEPARATOR . $f)) $docsGenerales[] = $f;
        }
    }
}

// =======================
// combos
// =======================
$caminos     = $conn->query("SELECT id, codigo, IFNULL(nombre,'') AS nom, IFNULL(descripcion,'') AS descp FROM caminos ORDER BY codigo");
$distritos   = $conn->query("SELECT id, nombre FROM distritos WHERE activo = 1 ORDER BY nombre");
$encargados  = $conn->query("SELECT id, nombre FROM encargados ORDER BY nombre");
$modalidades = $conn->query("SELECT id, nombre FROM modalidades ORDER BY nombre");

// TIPOS DE PROYECTO
$tipos_proyecto = $conn->query("SELECT id, nombre FROM tareas_catalogo ORDER BY nombre");

// tipos asignados
$asignados = [];
$stmtAsig = $conn->prepare("SELECT tipo_id FROM proyecto_tipos WHERE proyecto_id = ?");
$stmtAsig->bind_param("i", $id);
$stmtAsig->execute();
$resAsig = $stmtAsig->get_result();
while ($aa = $resAsig->fetch_assoc()) $asignados[(int)$aa['tipo_id']] = true;
$stmtAsig->close();

// Mapa de estados
$estadosMapa = [
    1 => 'Pendiente',
    2 => 'En ejecución',
    3 => 'Finalizado',
    4 => 'Suspendido'
];

include "../../includes/header.php";
?>
<style>
.ck-editor__editable{min-height:150px!important;}
.select2-container{z-index:99999!important;}
</style>

<div class="container py-4" style="max-width:1100px;">
  <?php if(isset($_GET['ok'])): ?>
    <div class="alert alert-success">Cambios guardados correctamente.</div>
  <?php endif; ?>

  <?php if ($errorGuardar !== ''): ?>
    <div class="alert alert-danger">
      <strong>Error al guardar:</strong><br>
      <?= e($errorGuardar) ?>
    </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h4 class="fw-bold text-primary mb-0">
        Editar Proyecto #<?= (int)$id ?>
      </h4>
      <small class="text-muted">
        <strong>Nombre:</strong> <?= e($proy['nombre'] ?: '—') ?> |
        <strong>Camino(s):</strong>
        <?= !empty($caminosAsignadosTxt) ? e(implode(' | ', $caminosAsignadosTxt)) : '—' ?>
        <?php if (!empty($distritosAsignadosTxt)): ?>
          | <strong>Distrito(s):</strong> <?= e(implode(' | ', $distritosAsignadosTxt)) ?>
        <?php endif; ?>
      </small>
    </div>
    <div>
      <a href="proyectos.php" class="btn btn-secondary btn-sm">Volver</a>
    </div>
  </div>

  <form method="POST"
        class="card p-4 shadow-sm"
        onsubmit="return validarProyecto(this);"
        enctype="multipart/form-data">

    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int)$proy['id'] ?>">

    <div class="row g-3">

      <div class="col-md-6">
        <label class="form-label">Nombre</label>
        <input name="nombre" class="form-control form-control-sm" required
               value="<?= e($proy['nombre']) ?>">
      </div>

      <div class="col-12">
        <label class="form-label">Descripción detallada</label>
        <textarea name="descripcion" id="descripcion_editar" class="form-control" required><?= e($proy['descripcion'] ?? '') ?></textarea>
      </div>

      <div class="col-md-6 col-lg-6">
        <label class="form-label">Camino(s) (Inventario)</label>
        <select name="caminos[]" class="form-select form-select-sm select2-caminos" multiple required>
          <?php mysqli_data_seek($caminos,0); while($c=$caminos->fetch_assoc()):
            $label = trim($c['nom']) !== '' ? $c['nom'] : $c['descp'];
            $texto = trim($c['codigo'].' - '.($label ?: ''));
            $cid = (int)$c['id'];
          ?>
          <option value="<?= $cid ?>" <?= isset($caminosAsignados[$cid]) ? 'selected' : '' ?>>
            <?= e($texto) ?>
          </option>
          <?php endwhile; ?>
        </select>
        <small class="text-muted">Puede seleccionar 1 o varios caminos.</small>
      </div>

      <div class="col-md-6 col-lg-6">
        <label class="form-label">Distrito(s)</label>
        <?php if ($tienePuenteDistritos): ?>
          <select name="distritos[]" class="form-select form-select-sm select2-distritos" multiple required>
            <?php mysqli_data_seek($distritos,0); while($r=$distritos->fetch_assoc()):
              $did = (int)$r['id'];
            ?>
              <option value="<?= $did ?>" <?= isset($distritosAsignados[$did]) ? 'selected' : '' ?>>
                <?= e($r['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
          <small class="text-muted">Puede seleccionar 1 o varios distritos.</small>
        <?php else: ?>
          <select name="distrito_id" class="form-select form-select-sm" required>
            <option value="">Seleccione...</option>
            <?php mysqli_data_seek($distritos,0); while($r=$distritos->fetch_assoc()): ?>
              <option value="<?= (int)$r['id'] ?>" <?= ((int)$proy['distrito_id']===(int)$r['id'])?'selected':'' ?>>
                <?= e($r['nombre']) ?>
              </option>
            <?php endwhile; ?>
          </select>
        <?php endif; ?>
      </div>

      <div class="col-md-6 col-lg-3">
        <label class="form-label">Encargado</label>
        <select name="encargado_id" class="form-select form-select-sm" required>
          <option value="">Seleccione...</option>
          <?php mysqli_data_seek($encargados,0); while($r=$encargados->fetch_assoc()): ?>
          <option value="<?= (int)$r['id'] ?>" <?= ((int)$proy['encargado_id']===(int)$r['id'])?'selected':'' ?>>
            <?= e($r['nombre']) ?>
          </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="col-md-4 col-lg-3">
        <label class="form-label">Modalidad</label>
        <select name="modalidad_id" class="form-select form-select-sm" required>
          <option value="">Seleccione...</option>
          <?php mysqli_data_seek($modalidades,0); while($r=$modalidades->fetch_assoc()): ?>
          <option value="<?= (int)$r['id'] ?>" <?= ((int)$proy['modalidad_id']===(int)$r['id'])?'selected':'' ?>>
            <?= e($r['nombre']) ?>
          </option>
          <?php endwhile; ?>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label">Tipos del proyecto (multi selección)</label>
        <select name="tipos[]" class="form-select select2-multi" multiple required>
          <?php mysqli_data_seek($tipos_proyecto,0); while($t=$tipos_proyecto->fetch_assoc()): ?>
            <option value="<?= (int)$t['id'] ?>" <?= isset($asignados[(int)$t['id']])?'selected':'' ?>>
              <?= e($t['nombre']) ?>
            </option>
          <?php endwhile; ?>
        </select>
        <small class="text-muted">Puede seleccionar varios</small>
      </div>

      <!-- ✅ METAS (solo si existe en BD) -->
      <?php if ($tieneMetas && $metasCatalogo): ?>
      <div class="col-12">
        <label class="form-label">Metas del proyecto (multi selección)</label>
        <select name="metas[]" class="form-select select2-metas" multiple required>
          <?php mysqli_data_seek($metasCatalogo,0); while($m=$metasCatalogo->fetch_assoc()):
              $mid = (int)$m['id'];
              $label = (string)$m['nombre'];
              $desc  = trim((string)($m['descripcion'] ?? ''));
              $texto = $desc !== '' ? ($label . " — " . $desc) : $label;
          ?>
            <option value="<?= $mid ?>" <?= isset($metasAsignadas[$mid]) ? 'selected' : '' ?>>
              <?= e($texto) ?>
            </option>
          <?php endwhile; ?>
        </select>
        <small class="text-muted">Puede seleccionar una o varias metas.</small>
      </div>
      <?php endif; ?>

      <div class="col-md-4 col-lg-3">
        <label class="form-label">Fecha inicio</label>
        <input type="date" name="fecha_inicio" class="form-control form-control-sm"
               required value="<?= e($proy['fecha_inicio']) ?>">
      </div>

      <div class="col-md-4 col-lg-3">
        <label class="form-label">Fecha fin</label>
        <input type="date" name="fecha_fin" class="form-control form-control-sm"
               required value="<?= e($proy['fecha_fin']) ?>">
      </div>

      <div class="col-md-3 col-lg-2">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select form-select-sm" required>
          <?php foreach($estadosMapa as $valor => $texto): ?>
            <option value="<?= (int)$valor ?>" <?= ((int)$proy['estado'] === (int)$valor) ? 'selected' : '' ?>>
              <?= e($texto) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-3 col-lg-2">
        <label class="form-label">Avance</label>
        <input type="range" name="avance" min="0" max="100"
               value="<?= (int)$proy['avance'] ?>"
               class="form-range"
               oninput="this.nextElementSibling.value=this.value+'%'">
        <output><?= (int)$proy['avance'] ?>%</output>
      </div>

      <div class="col-md-4 col-lg-3">
        <label class="form-label">Monto invertido (CRC)</label>
        <input type="number"
               name="monto_invertido"
               class="form-control form-control-sm"
               step="0.01"
               min="0"
               value="<?= e((string)($proy['monto_invertido'] ?? '0')) ?>">
        <small class="text-muted">Puede sumarlo, restarlo o ponerlo en 0.</small>
      </div>

      <div class="col-12">
        <label class="form-label">Documentos generales del proyecto (PDF, Word, Excel)</label>
        <input type="file"
               name="docs_generales[]"
               multiple
               accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
               class="form-control form-control-sm">

        <?php if (!empty($docsGenerales) && $rutaDocsGenWeb !== ''): ?>
          <div class="mt-2">
            <strong>Archivos ya adjuntos:</strong>
            <ul class="list-unstyled small mb-0">
              <?php foreach ($docsGenerales as $doc): ?>
                <li>
                  <i class="bi bi-file-earmark-text"></i>
                  <a href="<?= $rutaDocsGenWeb . '/' . rawurlencode($doc) ?>" target="_blank">
                    <?= e($doc) ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <small class="text-muted">Aún no hay documentos generales almacenados.</small>
        <?php endif; ?>
      </div>

      <div class="col-12 text-end">
        <a href="proyectos.php" class="btn btn-secondary btn-sm">Volver</a>
        <button type="submit" class="btn btn-primary btn-sm">Actualizar</button>
      </div>

    </div>
  </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.ckeditor.com/ckeditor5/41.2.1/classic/ckeditor.js"></script>

<script>
$(function () {
  $('.select2-caminos').select2({width:'100%', placeholder:'Seleccione uno o varios caminos', closeOnSelect:false});
  $('.select2-distritos').select2({width:'100%', placeholder:'Seleccione uno o varios distritos', closeOnSelect:false});
  $('.select2-multi').select2({width:'100%', placeholder:'Seleccione tipos', closeOnSelect:false});

  // ✅ metas
  $('.select2-metas').select2({width:'100%', placeholder:'Seleccione metas', closeOnSelect:false});

  const areaEditar = document.getElementById('descripcion_editar');
  if(areaEditar){ ClassicEditor.create(areaEditar).catch(console.error); }
});

function validarProyecto(form){
  const nombre      = form.nombre.value.trim();
  const descripcion = form.descripcion.value.trim();

  const caminosSel = form.querySelectorAll('select[name="caminos[]"] option:checked').length;

  const distritosMulti = form.querySelector('select[name="distritos[]"]');
  const distritoSingle = form.querySelector('select[name="distrito_id"]');

  const distritosSel = distritosMulti
    ? distritosMulti.querySelectorAll('option:checked').length
    : (distritoSingle ? (distritoSingle.value ? 1 : 0) : 0);

  const encargado  = form.encargado_id.value;
  const modalidad  = form.modalidad_id.value;

  const tipos = form.querySelectorAll('select[name="tipos[]"] option:checked').length;

  // ✅ metas: solo si existe select
  const metasSelect = form.querySelector('select[name="metas[]"]');
  const metasSel = metasSelect ? metasSelect.querySelectorAll('option:checked').length : null;

  const inicio = form.fecha_inicio.value;
  const fin    = form.fecha_fin.value;

  if(!nombre){alert("El nombre es obligatorio.");return false;}
  if(!descripcion){alert("La descripción es obligatoria.");return false;}
  if(caminosSel===0){alert("Debe seleccionar al menos un camino.");return false;}
  if(distritosSel===0){alert("Debe seleccionar al menos un distrito.");return false;}
  if(!encargado){alert("Debe seleccionar un encargado.");return false;}
  if(!modalidad){alert("Debe seleccionar una modalidad.");return false;}
  if(tipos===0){alert("Debe seleccionar al menos un tipo de proyecto.");return false;}

  if(metasSel !== null && metasSel === 0){
    alert("Debe seleccionar al menos una meta.");
    return false;
  }

  if(!inicio){alert("Debe ingresar una fecha de inicio.");return false;}
  if(!fin){alert("Debe ingresar una fecha de fin.");return false;}
  if(inicio>fin){alert("La fecha de inicio no puede ser posterior a la de fin.");return false;}
  return true;
}
</script>

<?php include "../../includes/footer.php"; ?>
