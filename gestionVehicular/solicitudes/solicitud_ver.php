<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
$uid = (int)($_SESSION["id"] ?? 0);

$rolesPermitidos = ["adminvehicular", "solicitante", "financiero", "dashboard"];
if (!in_array($rol, $rolesPermitidos, true)) {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    header("Location: solicitudes.php");
    exit;
}

// Cargar solicitud
$stmt = $conn->prepare("
    SELECT s.*, u.nombre AS solicitante, v.placa
    FROM solicitudes_vehiculo s
    LEFT JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN vehiculos v ON v.id = s.vehiculo_id
    WHERE s.id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$sol = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sol) {
    header("Location: solicitudes.php");
    exit;
}

// El solicitante solo puede ver sus solicitudes
if ($rol === "solicitante" && (int)$sol["usuario_id"] !== $uid) {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$errores = [];
$mensajeOk = "";

// Acciones del adminVehicular: aprobar / rechazar
if ($rol === "adminvehicular" && $_SERVER["REQUEST_METHOD"] === "POST") {
    $accion  = $_POST["accion"] ?? "";
    $coment  = trim($_POST["comentario_admin"] ?? "");
    $vehId   = isset($_POST["vehiculo_id"]) ? (int)$_POST["vehiculo_id"] : 0;

    if ($accion === "aprobar") {
        if ($vehId <= 0) {
            $errores[] = "Debe seleccionar un vehículo para aprobar.";
        } elseif ($sol["estado"] !== "pendiente") {
            $errores[] = "La solicitud ya no está pendiente.";
        } else {
            // Asignar vehículo y aprobar
            $stmt = $conn->prepare("
                UPDATE solicitudes_vehiculo
                SET estado = 'aprobada',
                    vehiculo_id = ?,
                    comentario_admin = ?
                WHERE id = ?
            ");
            $stmt->bind_param("isi", $vehId, $coment, $id);
            $stmt->execute();
            $stmt->close();

            $mensajeOk = "Solicitud aprobada correctamente.";
            $sol["estado"] = "aprobada";
            $sol["vehiculo_id"] = $vehId;
            $sol["comentario_admin"] = $coment;

            // Cargar placa del vehículo
            $stmt = $conn->prepare("SELECT placa FROM vehiculos WHERE id = ?");
            $stmt->bind_param("i", $vehId);
            $stmt->execute();
            $tmp = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $sol["placa"] = $tmp["placa"] ?? $sol["placa"];
        }
    } elseif ($accion === "rechazar") {
        if ($sol["estado"] !== "pendiente" && $sol["estado"] !== "aprobada") {
            $errores[] = "La solicitud no puede ser rechazada en este estado.";
        } else {
            $stmt = $conn->prepare("
                UPDATE solicitudes_vehiculo
                SET estado = 'rechazada',
                    comentario_admin = ?
                WHERE id = ?
            ");
            $stmt->bind_param("si", $coment, $id);
            $stmt->execute();
            $stmt->close();

            $mensajeOk = "Solicitud rechazada.";
            $sol["estado"] = "rechazada";
            $sol["comentario_admin"] = $coment;
        }
    }
}

// Vehículos disponibles para aprobar
$vehiculosDisp = [];
if ($rol === "adminvehicular") {
    $rsVeh = $conn->query("
        SELECT id, placa, marca, modelo
        FROM vehiculos
        WHERE estado IN ('disponible','mantenimiento') = 1 OR estado = 'disponible'
    ");
    if ($rsVeh) {
        while ($v = $rsVeh->fetch_assoc()) {
            $vehiculosDisp[] = $v;
        }
    }
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <a href="solicitudes.php" class="btn btn-secondary mb-3">&larr; Volver</a>

    <h3 class="mb-1">Solicitud #<?= (int)$sol["id"] ?></h3>
    <p class="text-muted mb-3">
        Solicitante: <?= e($sol["solicitante"] ?? "") ?> |
        Estado: <strong><?= e($sol["estado"]) ?></strong>
    </p>

    <?php if ($mensajeOk): ?>
        <div class="alert alert-success"><?= e($mensajeOk) ?></div>
    <?php endif; ?>
    <?php if ($errores): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errores as $err): ?>
                    <li><?= e($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Datos del viaje</div>
                <div class="card-body small">
                    <p class="mb-1">
                        <strong>Fecha salida:</strong>
                        <?= e(date("d/m/Y", strtotime($sol["fecha_salida"]))) ?>
                        <?= e(substr($sol["hora_salida"],0,5)) ?>
                    </p>
                    <p class="mb-1">
                        <strong>Fecha regreso estimada:</strong>
                        <?= e(date("d/m/Y", strtotime($sol["fecha_regreso_prevista"]))) ?>
                        <?= e(substr($sol["hora_regreso"],0,5)) ?>
                    </p>
                    <p class="mb-1"><strong>Lugar salida:</strong> <?= e($sol["lugar_salida"] ?? "") ?></p>
                    <p class="mb-1"><strong>Destino:</strong> <?= e($sol["destino"] ?? "") ?></p>
                    <p class="mb-1"><strong>Motivo:</strong> <?= nl2br(e($sol["motivo"] ?? "")) ?></p>
                    <p class="mb-1"><strong>Días uso continuo:</strong> <?= (int)($sol["dias_uso"] ?? 0) ?></p>
                    <p class="mb-1"><strong>Personas adicionales:</strong> <?= e($sol["personas_adicionales"] ?? "") ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Vehículo y comentarios</div>
                <div class="card-body small">
                    <p class="mb-1"><strong>Vehículo asignado:</strong> <?= e($sol["placa"] ?? "Por asignar") ?></p>
                    <p class="mb-1"><strong>Comentario del funcionario:</strong></p>
                    <p><?= nl2br(e($sol["comentario_funcionario"] ?? "")) ?: "<span class='text-muted'>Sin comentarios.</span>" ?></p>
                    <p class="mb-1"><strong>Comentario de administración:</strong></p>
                    <p><?= nl2br(e($sol["comentario_admin"] ?? "")) ?: "<span class='text-muted'>Sin comentarios.</span>" ?></p>
                </div>
            </div>
        </div>
    </div>

    <?php if ($rol === "adminvehicular" && in_array($sol["estado"], ["pendiente","aprobada","rechazada"], true)): ?>
        <div class="card mb-4">
            <div class="card-header fw-semibold">Acciones de administración</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row mb-3">
                        <div class="col-md-5">
                            <label class="form-label">Vehículo a asignar (para aprobar)</label>
                            <select name="vehiculo_id" class="form-select">
                                <option value="">-- Seleccione --</option>
                                <?php foreach ($vehiculosDisp as $v): ?>
                                    <option value="<?= (int)$v["id"] ?>" <?= ($sol["vehiculo_id"] == $v["id"]) ? "selected" : "" ?>>
                                        <?= e($v["placa"]) ?> - <?= e($v["marca"]) ?> <?= e($v["modelo"]) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Comentario administrador</label>
                            <textarea name="comentario_admin" rows="3" class="form-control"><?= e($sol["comentario_admin"] ?? "") ?></textarea>
                        </div>
                    </div>

                    <button name="accion" value="aprobar" class="btn btn-success me-2">
                        Aprobar solicitud
                    </button>
                    <button name="accion" value="rechazar" class="btn btn-danger"
                        onclick="return confirm('¿Seguro que desea rechazar esta solicitud?');">
                        Rechazar solicitud
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include "../../includes/footer.php"; ?>
