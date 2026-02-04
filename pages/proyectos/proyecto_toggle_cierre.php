<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

if (!can_edit("proyectos")) {
    header("Location: proyectos.php?err=" . urlencode("Sin permisos para cerrar/reabrir proyectos."));
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

mysqli_set_charset($conn, "utf8");

// CSRF
$csrf = $_POST['_csrf'] ?? '';
if (function_exists('csrf_check') && !csrf_check($csrf)) {
    header("Location: proyectos.php?err=" . urlencode("CSRF inválido o sesión expirada."));
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$accion = strtolower(trim((string)($_POST['accion'] ?? '')));

if ($id <= 0 || !in_array($accion, ['cerrar','reabrir'], true)) {
    header("Location: proyectos.php?err=" . urlencode("Solicitud inválida."));
    exit;
}

// Verificar columnas existen (compatibilidad)
function db_name(mysqli $conn): string {
    $r = $conn->query("SELECT DATABASE() AS db")->fetch_assoc();
    return (string)($r['db'] ?? '');
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

if (!column_exists($conn, "proyectos", "cerrado")) {
    header("Location: proyecto_visor.php?id={$id}&err=" . urlencode("La BD no tiene soporte de cierre (falta columna 'cerrado')."));
    exit;
}

$uid = (int)($_SESSION["id"] ?? 0);

try {
    $conn->begin_transaction();

    // Traer estado actual (para log y control)
    $stmt = $conn->prepare("SELECT id, nombre, COALESCE(cerrado,0) AS cerrado FROM proyectos WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $p = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$p) {
        $conn->rollback();
        header("Location: proyectos.php?err=" . urlencode("Proyecto no encontrado."));
        exit;
    }

    $antes = (int)$p['cerrado'];

    if ($accion === 'cerrar') {
        if ($antes === 1) {
            $conn->commit();
            header("Location: proyecto_visor.php?id={$id}&ok=" . urlencode("El proyecto ya estaba cerrado."));
            exit;
        }

        // cerrado_por / cerrado_en si existen
        $tPor = column_exists($conn, "proyectos", "cerrado_por");
        $tEn  = column_exists($conn, "proyectos", "cerrado_en");

        if ($tPor && $tEn) {
            $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=1, cerrado_por=?, cerrado_en=NOW() WHERE id=? LIMIT 1");
            $stmtU->bind_param("ii", $uid, $id);
        } elseif ($tPor) {
            $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=1, cerrado_por=? WHERE id=? LIMIT 1");
            $stmtU->bind_param("ii", $uid, $id);
        } elseif ($tEn) {
            $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=1, cerrado_en=NOW() WHERE id=? LIMIT 1");
            $stmtU->bind_param("i", $id);
        } else {
            $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=1 WHERE id=? LIMIT 1");
            $stmtU->bind_param("i", $id);
        }

        $stmtU->execute();
        $stmtU->close();

        $conn->commit();

        if (function_exists('log_accion')) {
            log_accion($conn, 'PROYECTO_CERRAR', json_encode([
                'proyecto_id' => $id,
                'accion' => 'cerrar',
                'antes' => ['cerrado' => $antes],
                'despues' => ['cerrado' => 1, 'cerrado_por' => $uid, 'cerrado_en' => date('Y-m-d H:i:s')]
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        header("Location: proyecto_visor.php?id={$id}&ok=" . urlencode("Proyecto cerrado correctamente."));
        exit;
    }

    // reabrir
    if ($antes === 0) {
        $conn->commit();
        header("Location: proyecto_visor.php?id={$id}&ok=" . urlencode("El proyecto ya estaba abierto."));
        exit;
    }

    $tPor = column_exists($conn, "proyectos", "cerrado_por");
    $tEn  = column_exists($conn, "proyectos", "cerrado_en");

    if ($tPor && $tEn) {
        $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=0, cerrado_por=NULL, cerrado_en=NULL WHERE id=? LIMIT 1");
        $stmtU->bind_param("i", $id);
    } elseif ($tPor) {
        $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=0, cerrado_por=NULL WHERE id=? LIMIT 1");
        $stmtU->bind_param("i", $id);
    } elseif ($tEn) {
        $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=0, cerrado_en=NULL WHERE id=? LIMIT 1");
        $stmtU->bind_param("i", $id);
    } else {
        $stmtU = $conn->prepare("UPDATE proyectos SET cerrado=0 WHERE id=? LIMIT 1");
        $stmtU->bind_param("i", $id);
    }

    $stmtU->execute();
    $stmtU->close();

    $conn->commit();

    if (function_exists('log_accion')) {
        log_accion($conn, 'PROYECTO_REABRIR', json_encode([
            'proyecto_id' => $id,
            'accion' => 'reabrir',
            'antes' => ['cerrado' => $antes],
            'despues' => ['cerrado' => 0]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    header("Location: proyecto_visor.php?id={$id}&ok=" . urlencode("Proyecto reabierto correctamente."));
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    header("Location: proyecto_visor.php?id={$id}&err=" . urlencode("Error: " . $e->getMessage()));
    exit;
}
