<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Cabecera y charset
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// 3) Solo admin puede restaurar proyectos
if (!can_edit("admin")) {
    header("Location: ../papelera/papelera.php?err=" . urlencode("Sin permisos para restaurar proyectos."));
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header("Location: ../papelera/papelera.php?err=" . urlencode("ID de proyecto invalido."));
    exit;
}

// =========================
// CAPTURAR ESTADO *ANTES*
// =========================
$antes = [
    "proyecto" => [],
    "cronicas" => []
];

// Proyecto antes
$q1 = $conn->prepare("SELECT id, nombre, activo, estado FROM proyectos WHERE id = ?");
$q1->bind_param("i", $id);
$q1->execute();
$res1 = $q1->get_result();
if ($res1->num_rows > 0) {
    $antes["proyecto"] = $res1->fetch_assoc();
}
$q1->close();

// Cronicas antes
$q2 = $conn->prepare("SELECT estado_registro FROM cronicas WHERE proyecto_id = ?");
$q2->bind_param("i", $id);
$q2->execute();
$res2 = $q2->get_result();
$tmp = [];
while ($rowC = $res2->fetch_assoc()) {
    $tmp[] = $rowC;
}
$antes["cronicas"] = $tmp;
$q2->close();

try {
    $conn->begin_transaction();

    // 1) Restaurar proyecto
    $stmt = $conn->prepare("UPDATE proyectos SET activo = 1, estado = '1' WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Error al preparar UPDATE proyectos: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // 2) Restaurar cronicas
    $stmt = $conn->prepare("UPDATE cronicas SET estado_registro = '1' WHERE proyecto_id = ?");
    if (!$stmt) {
        throw new Exception("Error al preparar UPDATE cronicas: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // =========================
    // CAPTURAR ESTADO *DESPUES*
    // =========================
    $despues = [
        "proyecto" => [],
        "cronicas" => []
    ];

    // Proyecto despues
    $q3 = $conn->prepare("SELECT id, nombre, activo, estado FROM proyectos WHERE id = ?");
    $q3->bind_param("i", $id);
    $q3->execute();
    $res3 = $q3->get_result();
    if ($res3->num_rows > 0) {
        $despues["proyecto"] = $res3->fetch_assoc();
    }
    $q3->close();

    // Cronicas despues
    $q4 = $conn->prepare("SELECT estado_registro FROM cronicas WHERE proyecto_id = ?");
    $q4->bind_param("i", $id);
    $q4->execute();
    $res4 = $q4->get_result();
    $tmp2 = [];
    while ($rowC2 = $res4->fetch_assoc()) {
        $tmp2[] = $rowC2;
    }
    $despues["cronicas"] = $tmp2;
    $q4->close();

    // =========================
    // GUARDAR LOG (nuevo helper)
    // =========================
    if (function_exists('log_accion')) {
        $detalle = [
            "proyecto_id" => $id,
            "accion"      => "restaurar_proyecto",
            "antes"       => $antes,
            "despues"     => $despues
        ];

        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        log_accion($conn, 'PROYECTO_RESTAURAR', $jsonDetalle);
    }

    // Confirmar cambios
    $conn->commit();

    // Redirigir con exito
    header("Location: ../papelera/papelera.php?ok=" . urlencode("Proyecto restaurado correctamente."));
    exit;

} catch (Exception $e) {

    $conn->rollback();
    echo "<h3>Error al restaurar proyecto</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}
