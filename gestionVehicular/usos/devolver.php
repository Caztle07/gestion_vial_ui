<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");

if ($rol !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$errores = [];
$mensajeOk = "";

// Usos en curso
$sql = "
    SELECT u.id, u.fecha_entrega, u.km_salida, v.placa, v.marca,
           s.destino, us.nombre AS solicitante
    FROM vehiculo_usos u
    INNER JOIN vehiculos v ON v.id = u.vehiculo_id
    LEFT JOIN solicitudes_vehiculo s ON s.id = u.solicitud_id
    LEFT JOIN usuarios us ON us.id = u.usuario_id
    WHERE u.estado_uso = 'en_curso'
    ORDER BY u.fecha_entrega ASC
";
$usosRes = $conn->query($sql);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $uso_id       = (int)($_POST["uso_id"] ?? 0);
    $km_regreso   = (int)($_POST["km_regreso"] ?? 0);
    $comb_regreso = trim($_POST["combustible_regreso"] ?? "");
    $obs          = trim($_POST["observaciones_regreso"] ?? "");
    $fechaHora    = date("Y-m-d H:i:s");

    if ($uso_id <= 0) {
        $errores[] = "Debe seleccionar un registro de uso.";
    }
    if ($km_regreso <= 0) {
        $errores[] = "Debe indicar el kilometraje de regreso.";
    }

    if (!$errores) {
        // Obtener uso + vehículo
        $stmt = $conn->prepare("
            SELECT u.*, v.id AS vehiculo_id
            FROM vehiculo_usos u
            INNER JOIN vehiculos v ON v.id = u.vehiculo_id
            WHERE u.id = ? AND u.estado_uso = 'en_curso'
        ");
        $stmt->bind_param("i", $uso_id);
        $stmt->execute();
        $uso = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$uso) {
            $errores[] = "El uso seleccionado no existe o ya fue cerrado.";
        } elseif ($km_regreso < (int)$uso["km_salida"]) {
            $errores[] = "El km de regreso no puede ser menor al km de salida.";
        } else {
            $vehiculo_id = (int)$uso["vehiculo_id"];

            // Actualizar uso
            $stmt = $conn->prepare("
                UPDATE vehiculo_usos
                SET fecha_devolucion = ?,
                    km_regreso = ?,
                    combustible_regreso = ?,
                    observaciones_regreso = ?,
                    estado_uso = 'finalizado'
                WHERE id = ?
            ");
            $stmt->bind_param(
                "sissi",
                $fechaHora,
                $km_regreso,
                $comb_regreso,
                $obs,
                $uso_id
            );
            $stmt->execute();
            $stmt->close();

            // Actualizar vehículo
            $stmt = $conn->prepare("
                UPDATE vehiculos
                SET estado = 'disponible',
                    km_actual = GREATEST(km_actual, ?)
                WHERE id = ?
            ");
            $stmt->bind_param("ii", $km_regreso, $vehiculo_id);
            $stmt->execute();
            $stmt->close();

            // Actualizar solicitud (si existe)
            if (!empty($uso["solicitud_id"])) {
                $sid = (int)$uso["solicitud_id"];
                $stmt = $conn->prepare("UPDATE solicitudes_vehiculo SET estado='finalizada' WHERE id = ?");
                $stmt->bind_param("i", $sid);
                $stmt->execute();
                $stmt->close();
            }

            $mensajeOk = "Devolución registrada correctamente.";
        }
    }
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <h3 class="mb-3">Registrar devolución de vehículo</h3>

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

    <form method="POST" class="card p-3">
        <div class="mb-3">
            <label class="form-label">Uso en curso</label>
            <select name="uso_id" class="form-select" required>
                <option value="">-- Seleccione --</option>
                <?php while ($u = $usosRes->fetch_assoc()): ?>
                    <option value="<?= (int)$u["id"] ?>">
                        <?= e(date("d/m H:i", strtotime($u["fecha_entrega"]))) ?> |
                        <?= e($u["placa"]) ?> |
                        <?= e($u["solicitante"] ?? "") ?> |
                        Destino: <?= e($u["destino"] ?? "") ?> |
                        Km salida: <?= (int)$u["km_salida"] ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="row">
            <div class="mb-3 col-md-4">
                <label class="form-label">Km regreso</label>
                <input type="number" name="km_regreso" class="form-control" required>
            </div>
            <div class="mb-3 col-md-4">
                <label class="form-label">Combustible al regresar</label>
                <select name="combustible_regreso" class="form-select">
                    <option value="">-- Seleccione --</option>
                    <option>Llena</option>
                    <option>3/4</option>
                    <option>1/2</option>
                    <option>1/4</option>
                    <option>Casi vacío</option>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Observaciones de regreso</label>
            <textarea name="observaciones_regreso" class="form-control" rows="3"></textarea>
        </div>

        <button class="btn btn-primary">Guardar devolución</button>
        <a href="usos.php" class="btn btn-secondary">Volver</a>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>
