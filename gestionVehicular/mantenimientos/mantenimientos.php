<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
$rolesPermitidos = ["adminvehicular", "financiero", "dashboard"];
if (!in_array($rol, $rolesPermitidos, true)) {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$sql = "
    SELECT m.*, v.placa, v.marca, v.modelo
    FROM vehiculo_mantenimientos m
    INNER JOIN vehiculos v ON v.id = m.vehiculo_id
    ORDER BY m.fecha DESC, m.id DESC
    LIMIT 200
";
$rs = $conn->query($sql);

include "../../includes/header.php";
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0">Mantenimientos de vehículos</h3>
        <?php if ($rol === "adminvehicular"): ?>
            <a href="mantenimiento_form.php" class="btn btn-primary">Registrar mantenimiento</a>
        <?php endif; ?>
    </div>

    <table class="table table-bordered table-sm">
        <thead class="table-light">
            <tr>
                <th>Fecha</th>
                <th>Placa</th>
                <th>Vehículo</th>
                <th>Tipo</th>
                <th>Km</th>
                <th>Costo</th>
                <th>Taller</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rs || $rs->num_rows === 0): ?>
            <tr><td colspan="7" class="text-center text-muted">No hay mantenimientos registrados.</td></tr>
        <?php else: ?>
            <?php while ($m = $rs->fetch_assoc()): ?>
                <tr>
                    <td><?= e(date("d/m/Y", strtotime($m["fecha"]))) ?></td>
                    <td><?= e($m["placa"]) ?></td>
                    <td><?= e($m["marca"] . " " . $m["modelo"]) ?></td>
                    <td><?= e($m["tipo"]) ?></td>
                    <td><?= (int)$m["km"] ?></td>
                    <td><?= $m["costo"] !== null ? "₡ " . number_format($m["costo"], 0, ',', '.') : "-" ?></td>
                    <td><?= e($m["taller"] ?? "") ?></td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include "../../includes/footer.php"; ?>
