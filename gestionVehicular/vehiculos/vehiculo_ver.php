<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
$uid = (int)($_SESSION["id"] ?? 0);

// Solo estos roles pueden ver detalle de vehículo
$rolesPermitidos = ["adminvehicular", "financiero", "dashboard"];
if (!in_array($rol, $rolesPermitidos, true)) {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
if ($id <= 0) {
    header("Location: vehiculos.php");
    exit;
}

// Vehículo
$stmt = $conn->prepare("SELECT * FROM vehiculos WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$vehiculo = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$vehiculo) {
    header("Location: vehiculos.php");
    exit;
}

// Últimos usos
$stmt = $conn->prepare("
    SELECT u.*, s.destino, s.motivo, s.estado AS estado_solicitud
    FROM vehiculo_usos u
    LEFT JOIN solicitudes_vehiculo s ON s.id = u.solicitud_id
    WHERE u.vehiculo_id = ?
    ORDER BY u.id DESC
    LIMIT 5
");
$stmt->bind_param("i", $id);
$stmt->execute();
$usos = $stmt->get_result();
$stmt->close();

// Últimos mantenimientos
$stmt = $conn->prepare("
    SELECT m.*
    FROM vehiculo_mantenimientos m
    WHERE m.vehiculo_id = ?
    ORDER BY m.fecha DESC, m.id DESC
    LIMIT 5
");
$stmt->bind_param("i", $id);
$stmt->execute();
$mants = $stmt->get_result();
$stmt->close();

include "../../includes/header.php";
?>

<div class="container py-4">
    <a href="vehiculos.php" class="btn btn-secondary mb-3">&larr; Volver</a>

    <div class="row mb-4">
        <div class="col-md-8">
            <h3 class="mb-1">
                Vehículo <?= e($vehiculo["placa"]) ?>
            </h3>
            <p class="text-muted mb-0">
                <?= e($vehiculo["marca"]) ?> <?= e($vehiculo["modelo"]) ?> • 
                <?= e($vehiculo["tipo"]) ?> • Año <?= (int)$vehiculo["anio"] ?>
            </p>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <span class="badge bg-primary">
                Estado: <?= e($vehiculo["estado"]) ?>
            </span>
            <div class="mt-2">
                <?php if ($rol === "adminvehicular"): ?>
                    <a href="vehiculo_form.php?id=<?= (int)$vehiculo["id"] ?>" class="btn btn-sm btn-warning">
                        Editar
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Datos generales</div>
                <div class="card-body small">
                    <p class="mb-1"><strong>Placa:</strong> <?= e($vehiculo["placa"]) ?></p>
                    <p class="mb-1"><strong>Tipo:</strong> <?= e($vehiculo["tipo"]) ?></p>
                    <p class="mb-1"><strong>Combustible:</strong> <?= e($vehiculo["combustible"]) ?></p>
                    <p class="mb-1"><strong>Km actual:</strong> <?= (int)$vehiculo["km_actual"] ?></p>
                    <p class="mb-1"><strong>Estado:</strong> <?= e($vehiculo["estado"]) ?></p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Vencimientos</div>
                <div class="card-body small">
                    <p class="mb-1">
                        <strong>DEKRA:</strong>
                        <?= $vehiculo["dekra_vencimiento"] ? e(date("d/m/Y", strtotime($vehiculo["dekra_vencimiento"]))) : "No registrado" ?>
                    </p>
                    <p class="mb-1">
                        <strong>Marchamo:</strong>
                        <?= $vehiculo["marchamo_vencimiento"] ? e(date("d/m/Y", strtotime($vehiculo["marchamo_vencimiento"]))) : "No registrado" ?>
                    </p>
                    <p class="mb-1">
                        <strong>Seguro:</strong>
                        <?= $vehiculo["seguro_vencimiento"] ? e(date("d/m/Y", strtotime($vehiculo["seguro_vencimiento"]))) : "No registrado" ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header fw-semibold">Observaciones</div>
                <div class="card-body small">
                    <?php if (!empty($vehiculo["observaciones"])): ?>
                        <p class="mb-0"><?= nl2br(e($vehiculo["observaciones"])) ?></p>
                    <?php else: ?>
                        <span class="text-muted">Sin observaciones.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Últimos usos</div>
                <div class="card-body small">
                    <?php if ($usos->num_rows === 0): ?>
                        <span class="text-muted">Sin registros de uso.</span>
                    <?php else: ?>
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Salida</th>
                                    <th>Regreso</th>
                                    <th>Km</th>
                                    <th>Destino</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($u = $usos->fetch_assoc()): ?>
                                <?php
                                  $kmRec = null;
                                  if (!is_null($u["km_salida"]) && !is_null($u["km_regreso"])) {
                                      $kmRec = (int)$u["km_regreso"] - (int)$u["km_salida"];
                                  }
                                ?>
                                <tr>
                                    <td><?= $u["fecha_entrega"] ? e(date("d/m H:i", strtotime($u["fecha_entrega"]))) : "-" ?></td>
                                    <td><?= $u["fecha_devolucion"] ? e(date("d/m H:i", strtotime($u["fecha_devolucion"]))) : "-" ?></td>
                                    <td><?= $kmRec !== null ? $kmRec : "-" ?></td>
                                    <td><?= e($u["destino"] ?? "") ?></td>
                                    <td><?= e($u["estado_uso"] ?? "") ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header fw-semibold">Últimos mantenimientos</div>
                <div class="card-body small">
                    <?php if ($mants->num_rows === 0): ?>
                        <span class="text-muted">Sin mantenimientos registrados.</span>
                    <?php else: ?>
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Km</th>
                                    <th>Costo</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php while ($m = $mants->fetch_assoc()): ?>
                                <tr>
                                    <td><?= e(date("d/m/Y", strtotime($m["fecha"]))) ?></td>
                                    <td><?= e($m["tipo"]) ?></td>
                                    <td><?= (int)$m["km"] ?></td>
                                    <td><?= $m["costo"] !== null ? "₡ " . number_format($m["costo"], 0, ',', '.') : "-" ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include "../../includes/footer.php"; ?>
