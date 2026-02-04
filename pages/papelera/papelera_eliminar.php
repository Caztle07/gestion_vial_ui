<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Headers / charset
header("Content-Type: text/html; charset=utf-8");
mysqli_set_charset($conn, "utf8");

// 3) SOLO ADMIN según can_edit
if (!can_edit("admin")) {
    header("Location: papelera.php?err=" . urlencode("Sin permisos"));
    exit;
}

// =====================
// DATOS PARA LOG
// =====================
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// =====================
// DATOS DEL FORM
// =====================
$cronicas  = isset($_POST["cronicas"])  ? (array)$_POST["cronicas"]  : [];
$proyectos = isset($_POST["proyectos"]) ? (array)$_POST["proyectos"] : [];

$user     = trim($_POST["auth_user"] ?? "");
$pass     = trim($_POST["auth_pass"] ?? "");
$confirma = trim($_POST["confirm"]   ?? "");

// =====================
// VALIDAR CONFIRMO
// =====================
if ($confirma !== "CONFIRMO") {
    header("Location: papelera.php?err=" . urlencode("Debe escribir CONFIRMO exactamente."));
    exit;
}

// =====================
// VALIDAR USUARIO ADMIN
// =====================
$stmt = $conn->prepare("SELECT id, usuario, password FROM usuarios WHERE usuario=? AND rol='admin' LIMIT 1");
$stmt->bind_param("s", $user);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    header("Location: papelera.php?err=" . urlencode("Usuario admin no válido."));
    exit;
}

$u    = $res->fetch_assoc();
$hash = $u["password"];

// Aceptar contraseña hasheada o texto plano
$okPass = password_verify($pass, $hash) || ($pass === $hash);

if (!$okPass) {
    header("Location: papelera.php?err=" . urlencode("Contraseña incorrecta."));
    exit;
}

// =====================
// FUNCIONES AUXILIARES
// =====================

// Borra carpeta recursivamente
function borrarDir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === "." || $item === "..") continue;
        $ruta = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($ruta)) {
            borrarDir($ruta);
        } else {
            @unlink($ruta);
        }
    }
    @rmdir($dir);
}

// Ruta base fija para carpetas de proyectos
$baseProy = __DIR__ . "/../../data/proyectos";

// =====================================
// 1) ELIMINAR CRÓNICAS SELECCIONADAS
// =====================================
if (!empty($cronicas)) {
    foreach ($cronicas as $id) {
        $id = intval($id);
        if ($id <= 0) continue;

        // Obtenemos crónica + nombre del proyecto (ANTES de borrar)
        $sql = "
            SELECT c.*, p.nombre AS proy
            FROM cronicas c
            LEFT JOIN proyectos p ON p.id = c.proyecto_id
            WHERE c.id = $id
            LIMIT 1
        ";
        $q = $conn->query($sql);
        if (!$q || $q->num_rows === 0) continue;

        $row  = $q->fetch_assoc();   // estado "antes"
        $proy = $row["proy"] ?? "";

        // Nombre de carpeta del proyecto
        $folder = preg_replace('/[^A-Za-z0-9_\-]/', '_', $proy);
        $path   = rtrim($baseProy, "/") . "/" . $folder;

        // Borrar imágenes de la crónica
        $imgs = json_decode($row["imagenes"] ?? "[]", true);
        if (is_array($imgs)) {
            foreach ($imgs as $f) {
                @unlink($path . "/cronicas_img/" . $f);
            }
        }

        // Borrar documentos de la crónica
        $docs = json_decode($row["documentos"] ?? "[]", true);
        if (is_array($docs)) {
            foreach ($docs as $f) {
                @unlink($path . "/cronicas_docs/" . $f);
            }
        }

        // Borrar crónica de la BD
        $conn->query("DELETE FROM cronicas WHERE id = $id");

        // ===== LOG: CRÓNICA ELIMINADA DEFINITIVAMENTE =====
        $detalleCron = [
            "tipo"           => "cronica",
            "accion"         => "eliminar_definitivo",
            "cronica_id"     => $id,
            "proyecto_nombre"=> $proy,
            "antes"          => $row,
            "despues"        => null
        ];

        $jsonDetalleCron = json_encode($detalleCron, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logC = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'CRONICA_ELIMINAR_DEFINITIVO', ?, ?)
        ");
        if ($logC) {
            $logC->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalleCron, $ipLog);
            $logC->execute();
            $logC->close();
        }
    }
}

// =====================================
// 2) ELIMINAR PROYECTOS SELECCIONADOS
// =====================================
if (!empty($proyectos)) {
    foreach ($proyectos as $pid) {
        $pid = intval($pid);
        if ($pid <= 0) continue;

        // 2.1 Obtener proyecto COMPLETO (ANTES) + nombre para carpeta
        $qProy = $conn->query("SELECT * FROM proyectos WHERE id = $pid LIMIT 1");
        if (!$qProy || $qProy->num_rows === 0) continue;

        $rowProy = $qProy->fetch_assoc();
        $proyNom = $rowProy["nombre"] ?? "";
        $folder  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $proyNom);
        $path    = rtrim($baseProy, "/") . "/" . $folder;

        $antesProyecto = $rowProy;

        // (Opcional) también podemos guardar IDs de cronicas previas
        $cronicasAntes = [];
        $qCronAntes = $conn->query("
            SELECT id, imagenes, documentos
            FROM cronicas
            WHERE proyecto_id = $pid
        ");
        if ($qCronAntes && $qCronAntes->num_rows > 0) {
            while ($cA = $qCronAntes->fetch_assoc()) {
                $cronicasAntes[] = $cA;
            }
        }

        // 2.2 BORRAR TABLAS HIJAS QUE TIENEN FK A proyectos.id

        // avances.obra_id -> proyectos.id
        try { $conn->query("DELETE FROM avances WHERE obra_id = $pid"); } catch (Exception $e) {}

        // proyecto_tipos.proyecto_id -> proyectos.id
        try { $conn->query("DELETE FROM proyecto_tipos WHERE proyecto_id = $pid"); } catch (Exception $e) {}

        // proyecto_distritos.proyecto_id -> proyectos.id
        try { $conn->query("DELETE FROM proyecto_distritos WHERE proyecto_id = $pid"); } catch (Exception $e) {}

        // 2.3 BORRAR TODAS LAS CRÓNICAS del proyecto (bd + archivos)
        $qCron = $conn->query("
            SELECT id, imagenes, documentos
            FROM cronicas
            WHERE proyecto_id = $pid
        ");

        if ($qCron && $qCron->num_rows > 0) {
            while ($c = $qCron->fetch_assoc()) {
                $imgs = json_decode($c["imagenes"] ?? "[]", true);
                if (is_array($imgs)) {
                    foreach ($imgs as $f) {
                        @unlink($path . "/cronicas_img/" . $f);
                    }
                }

                $docs = json_decode($c["documentos"] ?? "[]", true);
                if (is_array($docs)) {
                    foreach ($docs as $f) {
                        @unlink($path . "/cronicas_docs/" . $f);
                    }
                }
            }
            // Borrar crónicas del proyecto en la BD
            $conn->query("DELETE FROM cronicas WHERE proyecto_id = $pid");
        }

        // 2.4 BORRAR CARPETA COMPLETA DEL PROYECTO (si existe)
        borrarDir($path);

        // 2.5 BORRAR REGISTRO DEL PROYECTO
        $conn->query("DELETE FROM proyectos WHERE id = $pid");

        // ===== LOG: PROYECTO ELIMINADO DEFINITIVAMENTE =====
        $detalleProy = [
            "tipo"           => "proyecto",
            "accion"         => "eliminar_definitivo",
            "proyecto_id"    => $pid,
            "antes"          => $antesProyecto,
            "cronicas_antes" => $cronicasAntes,
            "despues"        => null
        ];

        $jsonDetalleProy = json_encode($detalleProy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logP = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'PROYECTO_ELIMINAR_DEFINITIVO', ?, ?)
        ");
        if ($logP) {
            $logP->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalleProy, $ipLog);
            $logP->execute();
            $logP->close();
        }
    }
}

// =====================================
// 3) REDIRIGIR CON OK
// =====================================
header("Location: papelera.php?ok=1");
exit;
