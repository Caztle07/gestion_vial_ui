<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
$uid = (int)($_SESSION["id"] ?? 0);

if ($rol !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$errores = [];
$mensajeOk = "";

// Cargar solicitudes aprobadas sin uso
$sqlSol = "
    SELECT s.id, s.fecha_salida, s.hora_salida, s.destino,
           u.nombre AS solicitante, v.placa
    FROM solicitudes_vehiculo s
    LEFT JOIN usuarios u ON u.id = s.usuario_id
    LEFT JOIN vehiculos v ON v.id = s.vehiculo_id
    WHERE s.estado = 'aprobada'
      AND s.id NOT IN (SELECT COALESCE(solicitud_id,0) FROM vehiculo_usos)
    ORDER BY s.fecha_salida ASC, s.hora_salida ASC
";
$solRes = $conn->query($sqlSol);

// POST => guardar entrega
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $solicitud_id = (int)($_POST["solicitud_id"] ?? 0);
    $km_salida    = (int)($_POST["km_salida"] ?? 0);
    $combustible  = trim($_POST["combustible_salida"] ?? "");
    $obs          = trim($_POST["observaciones_salida"] ?? "");
    $fechaHora    = date("Y-m-d H:i:s");

    if ($solicitud_id <= 0) {
        $errores[] = "Debe seleccionar una solicitud.";
    }
    if ($km_salida <= 0) {
        $errores[] = "Debe indicar el kilometraje de salida.";
    }

    // Cargar solicitud y vehículo asociado
    if (!$errores) {
        $stmt = $conn->prepare("
            SELECT s.*, v.id AS vehiculo_id, v.estado AS estado_vehiculo
            FROM solicitudes_vehiculo s
            LEFT JOIN vehiculos v ON v.id = s.vehiculo_id
            WHERE s.id = ?
        ");
        $stmt->bind_param("i", $solicitud_id);
        $stmt->execute();
        $sol = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sol) {
            $errores[] = "La solicitud seleccionada no existe.";
        } elseif ($sol["estado"] !== "aprobada") {
            $errores[] = "La solicitud ya no está en estado 'aprobada'.";
        } elseif (empty($sol["vehiculo_id"])) {
            $errores[] = "La solicitud no tiene un vehículo asignado.";
        } else {
            $vehiculo_id = (int)$sol["vehiculo_id"];

            // Insertar uso
            $stmt = $conn->prepare("
                INSERT INTO vehiculo_usos
                (solicitud_id, vehiculo_id, usuario_id,
                 fecha_entrega, km_salida, combustible_salida,
                 observaciones_salida, estado_uso, creado_en)
                VALUES (?,?,?,?,?,?,?,'en_curso',NOW())
            ");
            $stmt->bind_param(
                "iiissss",
                $solicitud_id,
                $vehiculo_id,
                $sol["usuario_id"],
                $fechaHora,
                $km_salida,
                $combustible,
                $obs
            );
            $stmt->execute();
            $stmt->close();

            // Actualizar vehículo
            $stmt = $conn->prepare("UPDATE vehiculos SET estado='en_uso', km_actual = GREATEST(km_actual, ?) WHERE id = ?");
            $stmt->bind_param("ii", $km_salida, $vehiculo_id);
            $stmt->execute();
            $stmt->close();

            // Actualizar solicitud
            $stmt = $conn->prepare("UPDATE solicitudes_vehiculo SET estado='en_curso' WHERE id = ?");
            $stmt->bind_param("i", $solicitud_id);
            $stmt->execute();
            $stmt->close();

            $mensajeOk = "Entrega registrada correctamente.";
        }
    }
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <h3 class="mb-3">Registrar entrega de vehículo</h3>

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
            <label class="form-label">Solicitud aprobada</label>
            <select name="solicitud_id" class="form-select" required>
                <option value="">-- Seleccione --</option>
                <?php while ($s = $solRes->fetch_assoc()): ?>
                    <option value="<?= (int)$s["id"] ?>">
                        <?= e($s["fecha_salida"]) ?> <?= e($s["hora_salida"]) ?> |
                        <?= e($s["solicitante"]) ?> |
                        Veh: <?= e($s["placa"] ?? "Por asignar") ?> |
                        Destino: <?= e($s["destino"] ?? "") ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="row">
            <div class="mb-3 col-md-4">
                <label class="form-label">Km salida</label>
                <input type="number" name="km_salida" class="form-control" required>
            </div>
            <div class="mb-3 col-md-4">
                <label class="form-label">Combustible al salir</label>
                <select name="combustible_salida" class="form-select">
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
            <label class="form-label">Observaciones de salida</label>
            <textarea name="observaciones_salida" class="form-control" rows="3"></textarea>
        </div>

        <button class="btn btn-primary">Guardar entrega</button>
        <a href="usos.php" class="btn btn-secondary">Volver</a>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>
