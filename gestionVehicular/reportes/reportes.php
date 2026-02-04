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

// Km recorridos por vehículo
$sql = "
    SELECT v.id, v.placa, v.marca, v.modelo,
           COUNT(u.id) AS usos,
           SUM(CASE 
                   WHEN u.km_salida IS NOT NULL AND u.km_regreso IS NOT NULL 
                   THEN (u.km_regreso - u.km_salida)
                   ELSE 0
               END) AS km_totales
    FROM vehiculos v
    LEFT JOIN vehiculo_usos u ON u.vehiculo_id = v.id AND u.estado_uso = 'finalizado'
    GROUP BY v.id
    ORDER BY km_totales DESC, v.placa ASC
";
$rs = $conn->query($sql);

include "../../includes/header.php";
?>

<div class="container py-4">
    <h3 class="mb-3">Reportes vehiculares</h3>

    <div class="mb-4">
        <h5>Kilómetros recorridos por vehículo</h5>
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Placa</th>
                    <th>Vehículo</th>
                    <th>Usos</th>
                    <th>Km totales</th>
                    <th>Promedio por uso</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rs || $rs->num_rows === 0): ?>
                <tr><td colspan="5" class="text-center text-muted">Sin datos.</td></tr>
            <?php else: ?>
                <?php while ($r = $rs->fetch_assoc()): ?>
                    <?php
                      $km = (int)$r["km_totales"];
                      $us = (int)$r["usos"];
                      $prom = $us > 0 ? round($km / $us, 1) : 0;
                    ?>
                    <tr>
                        <td><?= e($r["placa"]) ?></td>
                        <td><?= e($r["marca"] . " " . $r["modelo"]) ?></td>
                        <td><?= $us ?></td>
                        <td><?= $km ?></td>
                        <td><?= $prom ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="alert alert-info small">
        Más adelante aquí podemos agregar:
        <ul class="mb-0">
            <li>Reporte de gastos de mantenimiento por mes y por vehículo.</li>
            <li>Consumo de combustible por dependencia / usuario.</li>
            <li>Exportar a PDF / Excel.</li>
        </ul>
    </div>
</div>

<?php include "../../includes/footer.php"; ?>
