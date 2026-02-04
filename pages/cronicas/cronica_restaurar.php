<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// SOLO admin
if (!can_edit("admin")) {
    header("Location: ../papelera/papelera.php?err=" . urlencode("Sin permisos para restaurar crónicas."));
    exit;
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    header("Location: ../papelera/papelera.php?err=" . urlencode("ID de crónica inválido."));
    exit;
}

// =====================
// DATOS PARA LOG
// =====================
$usuario = $_SESSION["usuario"] ?? "desconocido";
$rol     = $_SESSION["rol"] ?? "desconocido";
$ip      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

try {

    // =========================
    // ESTADO ANTES
    // =========================
    $antes = null;
    $stmtA = $conn->prepare("SELECT * FROM cronicas WHERE id = ? LIMIT 1");
    if ($stmtA) {
        $stmtA->bind_param("i", $id);
        $stmtA->execute();
        $resA = $stmtA->get_result();
        if ($resA && $resA->num_rows > 0) {
            $antes = $resA->fetch_assoc();
        }
        $stmtA->close();
    }

    if (!$antes) {
        header("Location: ../papelera/papelera.php?err=" . urlencode("La crónica no existe."));
        exit;
    }

    $conn->begin_transaction();

    // =========================
    // RESTAURAR SOLO ESTA CRÓNICA
    // estado_registro = '1' -> activa
    // =========================
    $stmt = $conn->prepare("UPDATE cronicas SET estado_registro = '1' WHERE id = ?");
    if (!$stmt) {
        throw new Exception("Error al preparar UPDATE cronicas: " . $conn->error);
    }
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    // =========================
    // ESTADO DESPUÉS
    // =========================
    $despues = null;
    $stmtD = $conn->prepare("SELECT * FROM cronicas WHERE id = ? LIMIT 1");
    if ($stmtD) {
        $stmtD->bind_param("i", $id);
        $stmtD->execute();
        $resD = $stmtD->get_result();
        if ($resD && $resD->num_rows > 0) {
            $despues = $resD->fetch_assoc();
        }
        $stmtD->close();
    }

    $conn->commit();

    // =========================
    // GUARDAR LOG
    // =========================
    if ($despues) {
        $detalle = [
            "tipo"        => "cronica",
            "accion"      => "restaurar",
            "cronica_id"  => $id,
            "antes"       => $antes,
            "despues"     => $despues
        ];

        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Si tienes log_accion() lo puedes usar, pero aquí lo dejamos directo
        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'CRONICA_RESTAURAR', ?, ?)
        ");
        if ($log) {
            $log->bind_param("ssss", $usuario, $rol, $jsonDetalle, $ip);
            $log->execute();
            $log->close();
        }
    }

    // Volver a la papelera con mensaje OK
    header("Location: ../papelera/papelera.php?ok=" . urlencode("Crónica restaurada correctamente."));
    exit;

} catch (Exception $e) {
    $conn->rollback();
    echo "<h3>Error al restaurar crónica</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";
}
