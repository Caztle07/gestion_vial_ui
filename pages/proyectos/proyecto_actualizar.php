<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

$id            = isset($_POST['id']) ? intval($_POST['id']) : 0;
$nombre        = isset($_POST['nombre']) ? trim($_POST['nombre']) : '';
$descripcion   = isset($_POST['descripcion']) ? trim($_POST['descripcion']) : '';
$inventario_id = isset($_POST['inventario_id']) ? intval($_POST['inventario_id']) : 0;
$distrito_id   = isset($_POST['distrito_id']) ? intval($_POST['distrito_id']) : 0;
$fecha_inicio  = isset($_POST['fecha_inicio']) && $_POST['fecha_inicio'] !== '' ? $_POST['fecha_inicio'] : null;
$fecha_fin     = isset($_POST['fecha_fin'])    && $_POST['fecha_fin']    !== '' ? $_POST['fecha_fin']    : null;
$encargado_id  = isset($_POST['encargado_id']) ? intval($_POST['encargado_id']) : 0;
$modalidad_id  = isset($_POST['modalidad_id']) ? intval($_POST['modalidad_id']) : 0;
$estado        = isset($_POST['estado']) ? trim($_POST['estado']) : 'Pendiente';
$avance        = isset($_POST['avance']) ? intval($_POST['avance']) : 0;

// Nunca permitir que aquí se mande "0" como estado (eso es papelera)
if ($estado === '0' || $estado === '') {
    $estado = 'Pendiente';
}

/* ============================
   FUNCIÓN PARA SUBIR DOCS GENERALES
   ============================ */
function subirDocsGenerales($files, $destDir) {
    $guardados = [];

    // Extensiones PERMITIDAS
    $permitidas = ['pdf','doc','docx','xls','xlsx'];

    if (!isset($files["name"]) || empty($files["name"][0])) {
        return $guardados;
    }

    // Crear carpeta si no existe
    if (!is_dir($destDir)) {
        mkdir($destDir, 0777, true);
    }

    // Si queremos, aquí podrías crear un .htaccess para evitar ejecución de PHP en esa carpeta
    $htaccessPath = rtrim($destDir, "/") . "/.htaccess";
    if (!file_exists($htaccessPath)) {
        @file_put_contents($htaccessPath, "php_flag engine off\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar\n");
    }

    foreach ($files["name"] as $i => $name) {
        $tmp = $files["tmp_name"][$i] ?? '';
        if (!is_uploaded_file($tmp)) {
            continue;
        }

        // EXTENSIÓN
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Bloquear directamente extensiones peligrosas
        $peligrosas = ['php','phtml','php3','php4','php5','php7','phar','cgi','pl','exe','sh','bat','cmd'];
        if (in_array($ext, $peligrosas, true)) {
            continue;
        }

        // Solo las permitidas
        if (!in_array($ext, $permitidas, true)) {
            continue;
        }

        // Validar tipo MIME si fileinfo está disponible
        $mimeOk = true;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp);
            finfo_close($finfo);

            // Lista de MIMEs aceptables
            $mimesPermitidos = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];

            if (!in_array($mime, $mimesPermitidos, true)) {
                $mimeOk = false;
            }
        }

        if (!$mimeOk) {
            continue;
        }

        // Nombre seguro: timestamp + nombre sanitizado
        $nombreLimpio = preg_replace("/[^A-Za-z0-9_\-\.]/", "_", $name);
        $seguro       = time() . "_" . $nombreLimpio;
        $dest         = rtrim($destDir, "/") . "/" . $seguro;

        if (move_uploaded_file($tmp, $dest)) {
            $guardados[] = $seguro;
        }
    }

    return $guardados;
}


/* ============================
   VALIDACIONES
   ============================ */
$errores = [];

if ($id <= 0)                        $errores[] = "ID de proyecto inválido.";
if ($nombre === '')                  $errores[] = "El nombre es obligatorio.";
if ($descripcion === '')             $errores[] = "La descripción es obligatoria.";
if ($inventario_id <= 0)             $errores[] = "Debe seleccionar un camino.";
if ($distrito_id <= 0)               $errores[] = "Debe seleccionar un distrito.";
if ($encargado_id <= 0)              $errores[] = "Debe seleccionar un encargado.";
if ($modalidad_id <= 0)              $errores[] = "Debe seleccionar una modalidad.";
if ($fecha_inicio === null || $fecha_inicio === '')
                                     $errores[] = "Debe ingresar una fecha de inicio.";
if ($fecha_fin === null || $fecha_fin === '')
                                     $errores[] = "Debe ingresar una fecha de fin.";
if ($fecha_inicio && $fecha_fin && $fecha_inicio > $fecha_fin)
                                     $errores[] = "La fecha de inicio no puede ser posterior a la de fin.";

if (!empty($errores)) {
    $mensaje = implode(" ", $errores);
    header("Location: proyectos.php?err=" . urlencode($mensaje));
    exit;
}

/* =========================================
   CARGAR ESTADO ANTES (PARA LOG DE CAMBIO)
   ========================================= */
$proyecto_antes = null;
if ($id > 0) {
    $stmtAntes = $conn->prepare("SELECT * FROM proyectos WHERE id = ?");
    if ($stmtAntes) {
        $stmtAntes->bind_param("i", $id);
        $stmtAntes->execute();
        $resAntes = $stmtAntes->get_result();
        if ($resAntes && $resAntes->num_rows > 0) {
            $proyecto_antes = $resAntes->fetch_assoc();
        }
        $stmtAntes->close();
    }
}

/* ============================
   UPDATE DEL PROYECTO
   ============================ */
$sql = "UPDATE proyectos
        SET nombre = ?, descripcion = ?, inventario_id = ?, distrito_id = ?,
            fecha_inicio = ?, fecha_fin = ?, encargado_id = ?, modalidad_id = ?,
            estado = ?, avance = ?
        WHERE id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    header("Location: proyectos.php?err=" . urlencode($conn->error));
    exit;
}

/* TIPOS: s s i i s s i i s i i */
$stmt->bind_param(
    "ssiissiisii",
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
    $id
);

if (!$stmt->execute()) {
    header("Location: proyectos.php?err=" . urlencode($stmt->error));
    exit;
}

$stmt->close();

/* ============================
   DOCS GENERALES DEL PROYECTO
   ============================ */
// Ruta base
$baseData = "/var/www/html/gestion_vial_ui/data";
if (!is_dir($baseData)) {
    mkdir($baseData, 0777, true);
}

// nombre de carpeta por proyecto (usa el nombre actualizado)
if ($nombre === '') {
    $nombreProyecto = "proyecto_" . (int)$id;
} else {
    $nombreProyecto = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombre);
}

$baseProyecto = rtrim($baseData, "/") . "/proyectos/" . $nombreProyecto;
$rutaDocsGen  = $baseProyecto . "/docs_generales";

if (!is_dir($baseProyecto)) {
    mkdir($baseProyecto, 0777, true);
}
if (!is_dir($rutaDocsGen)) {
    mkdir($rutaDocsGen, 0777, true);
}

// Subir nuevos docs (si vienen)
subirDocsGenerales($_FILES['docs_generales'] ?? [], $rutaDocsGen);


/* ============================
   LOG DE ACTUALIZACIÓN
   ============================ */
if (function_exists('log_accion')) {
    $despues = [
        'id'            => $id,
        'nombre'        => $nombre,
        'descripcion'   => $descripcion,
        'inventario_id' => $inventario_id,
        'distrito_id'   => $distrito_id,
        'fecha_inicio'  => $fecha_inicio,
        'fecha_fin'     => $fecha_fin,
        'encargado_id'  => $encargado_id,
        'modalidad_id'  => $modalidad_id,
        'estado'        => $estado,
        'avance'        => $avance,
    ];

    $detalle_log = json_encode([
        'proyecto_id' => $id,
        'accion'      => 'actualizar',
        'antes'       => $proyecto_antes,
        'despues'     => $despues,
    ], JSON_UNESCAPED_UNICODE);

    // Registra quién hizo el cambio, el contenido "antes" y "después",
    // y la fecha/hora la pone la BD en logs_acciones.
    log_accion($conn, 'PROYECTO_ACTUALIZAR', $detalle_log);
}

header("Location: proyectos.php?ok=1");
exit;
