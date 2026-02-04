<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Solo ADMIN puede enviar a papelera (por rol)
$rol = strtolower(trim((string)($_SESSION["rol"] ?? "vista")));
if ($rol !== "admin") {
    header("Location: proyectos.php?err=" . urlencode("Sin permisos para eliminar proyectos."));
    exit;
}

// 3) Charset / headers
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// ==========================
// Helpers
// ==========================
function getDataBasePath(): ?string {
    // .../gestion_vial_ui/pages/proyectos -> subimos a /gestion_vial_ui
    $rootGuess = __DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".."; // .../gestion_vial_ui/pages
    $rootGuess = realpath($rootGuess) ?: $rootGuess;

    $root = realpath($rootGuess . DIRECTORY_SEPARATOR . "..") ?: ($rootGuess . DIRECTORY_SEPARATOR . ".."); // .../gestion_vial_ui

    $data = rtrim($root, "/\\") . DIRECTORY_SEPARATOR . "data";
    if (!is_dir($data)) @mkdir($data, 0775, true);

    $proyectos = $data . DIRECTORY_SEPARATOR . "proyectos";
    if (!is_dir($proyectos)) @mkdir($proyectos, 0775, true);

    if (!is_dir($data) || !is_dir($proyectos)) return null;
    return $data;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: proyectos.php?err=" . urlencode("ID de proyecto inválido."));
    exit;
}

// ===============================
// CARGAR ESTADO ANTES (para log)
// ===============================
$proyecto_antes = null;
$cronicas_antes = [];

$stmtProy = $conn->prepare("SELECT * FROM proyectos WHERE id = ?");
$stmtProy->bind_param("i", $id);
$stmtProy->execute();
$resProy = $stmtProy->get_result();
if ($resProy && $resProy->num_rows > 0) {
    $proyecto_antes = $resProy->fetch_assoc();
}
$stmtProy->close();

$stmtCron = $conn->prepare("SELECT id, estado_registro FROM cronicas WHERE proyecto_id = ?");
$stmtCron->bind_param("i", $id);
$stmtCron->execute();
$resCron = $stmtCron->get_result();
while ($rowC = $resCron->fetch_assoc()) {
    $cronicas_antes[] = $rowC;
}
$stmtCron->close();

if (!$proyecto_antes) {
    header("Location: proyectos.php?err=" . urlencode("Proyecto no encontrado."));
    exit;
}

try {
    $conn->begin_transaction();

    // 1) MANDAR CRÓNICAS A PAPELERA
    // Tu BD: estado_registro enum('activo','papelera')
    $stmt = $conn->prepare("UPDATE cronicas SET estado_registro = 'papelera' WHERE proyecto_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 2) MANDAR PROYECTO A PAPELERA
    // Tu listado filtra estado <> 0, entonces: estado=0 para ocultar en activos
    // activo=0 para que aparezca en papelera (según tu lógica)
    $stmt = $conn->prepare("UPDATE proyectos SET estado = 0, activo = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    // ===========================
    // NO BORRAR CARPETA AQUÍ
    // ===========================
    // Motivo: esto es "papelera", no eliminación definitiva.
    // Si borras la carpeta, pierdes evidencias, fototeca y no podrás restaurar.

    // ===========================
    // LOG: PROYECTO A PAPELERA
    // ===========================
    if (function_exists('log_accion')) {
        $detalle_log = json_encode([
            'proyecto_id' => $id,
            'accion'      => 'enviar_a_papelera',
            'antes'       => [
                'proyecto' => $proyecto_antes,
                'cronicas' => $cronicas_antes,
            ],
            'despues'     => [
                'proyecto' => [
                    'estado' => 0,
                    'activo' => 0
                ],
                'cronicas' => [
                    'estado_registro' => 'papelera',
                ],
            ],
            'nota' => 'No se borran archivos en disco al enviar a papelera (solo en eliminación definitiva).'
        ], JSON_UNESCAPED_UNICODE);

        log_accion($conn, 'PROYECTO_EN_PAPELERA', $detalle_log);
    }

    header("Location: proyectos.php?ok=" . urlencode("Proyecto enviado a papelera."));
    exit;

} catch (Exception $e) {
    $conn->rollback();

    if (function_exists('log_accion')) {
        $detalle_error = json_encode([
            'proyecto_id' => $id,
            'error'       => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        log_accion($conn, 'ERROR_PROYECTO_EN_PAPELERA', $detalle_error);
    }

    header("Location: proyectos.php?err=" . urlencode("Error enviando a papelera: " . $e->getMessage()));
    exit;
}
