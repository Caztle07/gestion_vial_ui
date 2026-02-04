<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
$uid = (int)($_SESSION["id"] ?? 0);

// Pueden crear solicitudes: solicitante y adminvehicular (si quiere crear a nombre de otro)
if (!in_array($rol, ["solicitante", "adminvehicular"], true)) {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$errores = [];
$mensajeOk = "";

// Lista de vehículos opcional (si quieren seleccionar uno desde el formulario)
$vehiculos = [];
$rs = $conn->query("SELECT id, placa, marca, modelo FROM vehiculos WHERE estado IN ('disponible','en_uso','mantenimiento')");
if ($rs) while ($v = $rs->fetch_assoc()) $vehiculos[] = $v;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fecha_salida  = $_POST["fecha_salida"] ?? "";
    $hora_salida   = $_POST["hora_salida"] ?? "";
    $fecha_regreso = $_POST["fecha_regreso"] ?? "";
    $hora_regreso  = $_POST["hora_regreso"] ?? "";
    $lugar_salida  = trim($_POST["lugar_salida"] ?? "");
    $destino       = trim($_POST["destino"] ?? "");
    $motivo        = trim($_POST["motivo"] ?? "");
    $dias_uso      = (int)($_POST["dias_uso"] ?? 0);
    $personas      = trim($_POST["personas_adicionales"] ?? "");
    $comentario_f  = trim($_POST["comentario_funcionario"] ?? "");
    $vehiculo_id   = isset($_POST["vehiculo_id"]) && $_POST["vehiculo_id"] !== "" ? (int)$_POST["vehiculo_id"] : null;

    if (!$fecha_salida || !$hora_salida) $errores[] = "Debe indicar fecha y hora de salida.";
    if (!$fecha_regreso || !$hora_regreso) $errores[] = "Debe indicar fecha y hora estimada de regreso.";
    if ($destino === "") $errores[] = "Debe indicar el destino.";
    if ($motivo === "") $errores[] = "Debe indicar el motivo del viaje.";

    if (!$errores) {
        $stmt = $conn->prepare("
            INSERT INTO solicitudes_vehiculo
            (usuario_id, vehiculo_id,
             fecha_salida, hora_salida,
             fecha_regreso_prevista, hora_regreso,
             lugar_salida, destino, motivo,
             dias_uso, personas_adicionales,
             comentario_funcionario, estado, fecha_creado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'pendiente', NOW())
        ");

    // vehiculo_id puede ser null
    if ($vehiculo_id === null) {
    $null = null;
    $stmt->bind_param(
        "iisssssssssi",  // Aquí se especifica el tipo de cada parámetro: "i" para enteros, "s" para cadenas
        $uid,             // $uid es un entero
        $null,            // $vehiculo_id es null en este caso
        $fecha_salida,    // $fecha_salida es una cadena
        $hora_salida,     // $hora_salida es una cadena (hora en formato HH:mm)
        $fecha_regreso,   // $fecha_regreso es una cadena (fecha en formato YYYY-MM-DD)
        $hora_regreso,    // $hora_regreso es una cadena (hora en formato HH:mm)
        $lugar_salida,    // $lugar_salida es una cadena
        $destino,         // $destino es una cadena
        $motivo,          // $motivo es una cadena
        $dias_uso,        // $dias_uso es un entero
        $personas,        // $personas es una cadena
        $comentario_f     // $comentario_f es una cadena
    );
    } else {
    $stmt->bind_param(
        "iisssssssssi",  // Aquí también se especifica el tipo de cada parámetro
        $uid,             // $uid es un entero
        $vehiculo_id,     // $vehiculo_id es un entero
        $fecha_salida,    // $fecha_salida es una cadena
        $hora_salida,     // $hora_salida es una cadena (hora en formato HH:mm)
        $fecha_regreso,   // $fecha_regreso es una cadena (fecha en formato YYYY-MM-DD)
        $hora_regreso,    // $hora_regreso es una cadena (hora en formato HH:mm)
        $lugar_salida,    // $lugar_salida es una cadena
        $destino,         // $destino es una cadena
        $motivo,          // $motivo es una cadena
        $dias_uso,        // $dias_uso es un entero
        $personas,        // $personas es una cadena
        $comentario_f     // $comentario_f es una cadena
    );
}
        $stmt->execute();
        $stmt->close();

        $mensajeOk = "Solicitud registrada correctamente.";
    }
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <a href="solicitudes.php" class="btn btn-secondary mb-3">&larr; Volver</a>

    <h3 class="mb-3">Nueva solicitud de vehículo</h3>

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
        <div class="row">
            <div class="mb-3 col-md-3">
                <label class="form-label">Fecha salida</label>
                <input type="date" name="fecha_salida" class="form-control" required>
            </div>
            <div class="mb-3 col-md-3">
                <label class="form-label">Hora salida</label>
                <input type="time" name="hora_salida" class="form-control" required>
            </div>
            <div class="mb-3 col-md-3">
                <label class="form-label">Fecha regreso estimada</label>
                <input type="date" name="fecha_regreso" class="form-control" required>
            </div>
            <div class="mb-3 col-md-3">
                <label class="form-label">Hora regreso estimada</label>
                <input type="time" name="hora_regreso" class="form-control" required>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Lugar de salida</label>
            <input type="text" name="lugar_salida" class="form-control">
        </div>
        <div class="mb-3">
            <label class="form-label">Destino</label>
            <input type="text" name="destino" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Motivo del viaje</label>
            <textarea name="motivo" rows="3" class="form-control" required></textarea>
        </div>

        <div class="row">
            <div class="mb-3 col-md-3">
                <label class="form-label">Días de uso continuo</label>
                <input type="number" name="dias_uso" class="form-control" min="0" value="0">
            </div>
            <div class="mb-3 col-md-9">
                <label class="form-label">Personas adicionales</label>
                <input type="text" name="personas_adicionales" class="form-control">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Comentario / Recomendaciones (ej. mantenimiento, llantas, etc.)</label>
            <textarea name="comentario_funcionario" rows="3" class="form-control"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Vehículo (opcional, si el solicitante ya conoce cuál)</label>
            <select name="vehiculo_id" class="form-select">
                <option value="">Que lo asigne el administrador</option>
                <?php foreach ($vehiculos as $v): ?>
                    <option value="<?= (int)$v["id"] ?>">
                        <?= e($v["placa"]) ?> - <?= e($v["marca"]) ?> <?= e($v["modelo"]) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn btn-primary">Enviar solicitud</button>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>
