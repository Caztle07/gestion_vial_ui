<?php
require_once "../../config/db.php";
require_once "../../auth.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// Solo admin
if (($_SESSION["rol"] ?? "") !== "admin") {
    header("Location: caminos.php?err=" . urlencode("Sin permisos para eliminar caminos."));
    exit;
}

$id = intval($_GET["id"] ?? 0);
if ($id <= 0) {
    header("Location: caminos.php?err=" . urlencode("ID inválido."));
    exit;
}

// ==========================
// DATOS PARA LOG
// ==========================
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// Obtener datos ANTES de eliminar
$antes = [];
$sel = $conn->prepare("SELECT * FROM caminos WHERE id = ?");
if ($sel) {
    $sel->bind_param("i", $id);
    $sel->execute();
    $res = $sel->get_result();
    if ($row = $res->fetch_assoc()) {
        $antes = $row;
    }
    $sel->close();
}

// Eliminar físico
$stmt = $conn->prepare("DELETE FROM caminos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

// Registrar LOG de eliminación
$detalle = [
    "tipo"      => "camino",
    "accion"    => "eliminar",
    "camino_id" => $id,
    "antes"     => $antes,
    "despues"   => null
];

$jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$log = $conn->prepare("
    INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
    VALUES (?, ?, 'CAMINO_ELIMINAR', ?, ?)
");
if ($log) {
    $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
    $log->execute();
    $log->close();
}

header("Location: caminos.php?ok=" . urlencode("Camino eliminado."));
exit;
