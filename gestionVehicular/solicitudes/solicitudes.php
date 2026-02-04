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
    SELECT s.*, u.nombre AS solicitante, v.placa
    FROM solicitudes_vehiculo s
    LEFT JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN vehiculos v ON v.id = s.vehiculo_id
";
$where = [];
$params = [];
$types  = "";

// Solicitante solo ve las suyas
if ($rol === "solicitante") {
    $where[] = "s.usuario_id = ?";
    $params[] = $uid;
    $types   .= "i";
}

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY s.id DESC LIMIT 200";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

include "../../includes/header.php";
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Solicitudes de vehículos</h3>
        <?php if ($rol === "solicitante" || $rol === "adminvehicular"): ?>
            <a href="solicitud_form.php" class="btn btn-primary">Nueva solicitud</a>
        <?php endif; ?>
    </div>

    <table class="table table-bordered table-sm">
        <thead class="table-light">
            <tr>
                <th>#</th>
                <th>Fecha salida</th>
                <th>Solicitante</th>
                <th>Vehículo</th>
                <th>Destino</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($res->num_rows === 0): ?>
            <tr><td colspan="7" class="text-center text-muted">No hay solicitudes.</td></tr>
        <?php else: ?>
            <?php while ($s = $res->fetch_assoc()): ?>
                <tr>
                    <td><?= (int)$s["id"] ?></td>
                    <td>
                        <?= e(date("d/m/Y", strtotime($s["fecha_salida"]))) ?>
                        <?= e(substr($s["hora_salida"], 0, 5)) ?>
                    </td>
                    <td><?= e($s["solicitante"] ?? "") ?></td>
                    <td><?= e($s["placa"] ?? "Por asignar") ?></td>
                    <td><?= e($s["destino"] ?? "") ?></td>
                    <td><?= e($s["estado"]) ?></td>
                    <td>
                        <a href="solicitud_ver.php?id=<?= (int)$s["id"] ?>" class="btn btn-sm btn-info">
                            Ver
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include "../../includes/footer.php"; ?>
