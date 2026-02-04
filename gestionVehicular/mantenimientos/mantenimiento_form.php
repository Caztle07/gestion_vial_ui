<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
if ($rol !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

$id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

$vehiculos = [];
$rs = $conn->query("SELECT id, placa, marca, modelo FROM vehiculos ORDER BY placa ASC");
if ($rs) while ($v = $rs->fetch_assoc()) $vehiculos[] = $v;

$mantenimiento = [
    "vehiculo_id"  => "",
    "fecha"        => date("Y-m-d"),
    "tipo"         => "",
    "km"           => "",
    "costo"        => "",
    "taller"       => "",
    "descripcion"  => "",
    "observaciones"=> ""
];

if ($id > 0) {
    $stmt = $conn->prepare("SELECT * FROM vehiculo_mantenimientos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($r) $mantenimiento = $r;
}

$errores = [];
$mensajeOk = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $vehiculo_id  = (int)($_POST["vehiculo_id"] ?? 0);
    $fecha        = $_POST["fecha"] ?? "";
    $tipo         = trim($_POST["tipo"] ?? "");
    $km           = (int)($_POST["km"] ?? 0);
    $costo        = $_POST["costo"] !== "" ? (float)$_POST["costo"] : null;
    $taller       = trim($_POST["taller"] ?? "");
    $descripcion  = trim($_POST["descripcion"] ?? "");
    $observaciones= trim($_POST["observaciones"] ?? "");

    if ($vehiculo_id <= 0) $errores[] = "Debe seleccionar un vehículo.";
    if (!$fecha) $errores[] = "Debe indicar la fecha.";
    if ($tipo === "") $errores[] = "Debe indicar el tipo de mantenimiento.";

    if (!$errores) {
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE vehiculo_mantenimientos
                SET vehiculo_id=?, fecha=?, tipo=?, km=?, costo=?, taller=?, descripcion=?, observaciones=?
                WHERE id=?
            ");
            $stmt->bind_param(
                "issidsssi",
                $vehiculo_id,
                $fecha,
                $tipo,
                $km,
                $costo,
                $taller,
                $descripcion,
                $observaciones,
                $id
            );
            $stmt->execute();
            $stmt->close();
            $mensajeOk = "Mantenimiento actualizado.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO vehiculo_mantenimientos
                (vehiculo_id, fecha,t ipo, km, costo, taller, descripcion, observaciones, creado_en)
                VALUES (?,?,?,?,?,?,?,?,NOW())
            ");
            $stmt->bind_param(
                "issidsss",
                $vehiculo_id,
                $fecha,
                $tipo,
                $km,
                $costo,
                $taller,
                $descripcion,
                $observaciones
            );
            $stmt->execute();
            $stmt->close();
            $mensajeOk = "Mantenimiento registrado.";
        }
    }

    // Para mostrar nuevamente en el form
    $mantenimiento = [
        "vehiculo_id"  => $vehiculo_id,
        "fecha"        => $fecha,
        "tipo"         => $tipo,
        "km"           => $km,
        "costo"        => $costo,
        "taller"       => $taller,
        "descripcion"  => $descripcion,
        "observaciones"=> $observaciones
    ];
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <a href="mantenimientos.php" class="btn btn-secondary mb-3">&larr; Volver</a>

    <h3 class="mb-3"><?= $id > 0 ? "Editar mantenimiento" : "Registrar mantenimiento" ?></h3>

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
            <div class="mb-3 col-md-4">
                <label class="form-label">Vehículo</label>
                <select name="vehiculo_id" class="form-select" required>
                    <option value="">-- Seleccione --</option>
                    <?php foreach ($vehiculos as $v): ?>
                        <option value="<?= (int)$v["id"] ?>" <?= $mantenimiento["vehiculo_id"] == $v["id"] ? "selected" : "" ?>>
                            <?= e($v["placa"]) ?> - <?= e($v["marca"] . " " . $v["modelo"]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-3 col-md-4">
                <label class="form-label">Fecha</label>
                <input type="date" name="fecha" class="form-control" value="<?= e($mantenimiento["fecha"] ?? "") ?>" required>
            </div>

            <div class="mb-3 col-md-4">
                <label class="form-label">Tipo de mantenimiento</label>
                <input type="text" name="tipo" class="form-control" value="<?= e($mantenimiento["tipo"] ?? "") ?>" required>
            </div>
        </div>

        <div class="row">
            <div class="mb-3 col-md-3">
                <label class="form-label">Km</label>
                <input type="number" name="km" class="form-control" value="<?= e($mantenimiento["km"] ?? "") ?>">
            </div>
            <div class="mb-3 col-md-3">
                <label class="form-label">Costo (₡)</label>
                <input type="number" step="0.01" name="costo" class="form-control" value="<?= e($mantenimiento["costo"] ?? "") ?>">
            </div>
            <div class="mb-3 col-md-6">
                <label class="form-label">Taller / Proveedor</label>
                <input type="text" name="taller" class="form-control" value="<?= e($mantenimiento["taller"] ?? "") ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Descripción / trabajos realizados</label>
            <textarea name="descripcion" class="form-control" rows="3"><?= e($mantenimiento["descripcion"] ?? "") ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Observaciones</label>
            <textarea name="observaciones" class="form-control" rows="3"><?= e($mantenimiento["observaciones"] ?? "") ?></textarea>
        </div>

        <button class="btn btn-primary"><?= $id > 0 ? "Guardar cambios" : "Registrar" ?></button>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>
