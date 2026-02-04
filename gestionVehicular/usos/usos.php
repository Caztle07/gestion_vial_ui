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

$sql = "
    SELECT u.*, v.placa, v.marca, s.destino, s.motivo, s.estado AS estado_solicitud,
           us.nombre AS solicitante
    FROM vehiculo_usos u
    INNER JOIN vehiculos v          ON v.id = u.vehiculo_id
    LEFT JOIN solicitudes_vehiculo s ON s.id = u.solicitud_id
    LEFT JOIN usuarios us           ON us.id = u.usuario_id
";

$where = [];
$params = [];
$types  = "";

// Solicitante solo ve sus usos
if ($rol === "solicitante") {
    $where[] = "u.usuario_id = ?";
    $params[] = $uid;
    $types   .= "i";
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY u.id DESC LIMIT 100";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

include "../../includes/header.php";
?>

<div class="container py-4">
    <h3 class="mb-3">Usos de vehículos</h3>

    <?php if ($rol === "adminvehicular"): ?>
        <a href="entregar.php" class="btn btn-primary mb-3">Registrar entrega</a>
    <?php endif; ?>

    <table class="table table-bordered table-sm">
        <thead class="table-light">
            <tr>
                <th>Placa</th>
                <th>Solicitante</th>
                <th>Destino</th>
                <th>Salida</th>
                <th>Regreso</th>
                <th>Km</th>
                <th>Estado</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($res->num_rows === 0): ?>
            <tr><td colspan="7" class="text-center text-muted">No hay usos registrados.</td></tr>
        <?php else: ?>
            <?php while ($u = $res->fetch_assoc()): ?>
                <?php
                  $kmRec = null;
                  if (!is_null($u["km_salida"]) && !is_null($u["km_regreso"])) {
                      $kmRec = (int)$u["km_regreso"] - (int)$u["km_salida"];
                  }
                ?>
                <tr>
                    <td><?= e($u["placa"]) ?></td>
                    <td><?= e($u["solicitante"] ?? "") ?></td>
                    <td><?= e($u["destino"] ?? "") ?></td>
                    <td><?= $u["fecha_entrega"] ? e(date("d/m H:i", strtotime($u["fecha_entrega"]))) : "-" ?></td>
                    <td><?= $u["fecha_devolucion"] ? e(date("d/m H:i", strtotime($u["fecha_devolucion"]))) : "-" ?></td>
                    <td><?= $kmRec !== null ? $kmRec : "-" ?></td>
                    <td><?= e($u["estado_uso"] ?? "") ?></td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include "../../includes/footer.php"; ?>
