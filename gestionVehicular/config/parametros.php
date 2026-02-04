<?php
require_once "../../auth.php";
require_login();
require_once "../../config/db.php";

$rol = strtolower($_SESSION["rol"] ?? "");
if ($rol !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

// Cargar (o crear en memoria) parámetros
$rs = $conn->query("SELECT * FROM vehicular_parametros LIMIT 1");
$param = $rs && $rs->num_rows ? $rs->fetch_assoc() : [
    "km_cambio_aceite"    => 5000,
    "km_cambio_llantas"   => 40000,
    "aviso_dekra_dias"      => 30,
    "aviso_marchamo_dias" => 30,
    "aviso_seguro_dias"   => 30,
];
$idParam = $param["id"] ?? null;

$mensajeOk = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $aceite   = (int)($_POST["km_cambio_aceite"] ?? 5000);
    $llantas  = (int)($_POST["km_cambio_llantas"] ?? 40000);
    $dekra      = (int)($_POST["aviso_dekra_dias"] ?? 30);
    $marchamo = (int)($_POST["aviso_marchamo_dias"] ?? 30);
    $seguro   = (int)($_POST["aviso_seguro_dias"] ?? 30);

    if ($idParam) {
        $stmt = $conn->prepare("
            UPDATE vehicular_parametros
            SET km_cambio_aceite=?, km_cambio_llantas=?,
                aviso_dekra_dias=?, aviso_marchamo_dias=?, aviso_seguro_dias=?
            WHERE id=?
        ");
        $stmt->bind_param("iiiiii", $aceite, $llantas, $dekra, $marchamo, $seguro, $idParam);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("
            INSERT INTO vehicular_parametros
            (km_cambio_aceite, km_cambio_llantas,
             aviso_dekra_dias, aviso_marchamo_dias, aviso_seguro_dias)
            VALUES (?,?,?,?,?)
        ");
        $stmt->bind_param("iiiii", $aceite, $llantas, $dekra, $marchamo, $seguro);
        $stmt->execute();
        $stmt->close();
    }

    $mensajeOk = "Parámetros guardados correctamente.";

    $param["km_cambio_aceite"]    = $aceite;
    $param["km_cambio_llantas"]   = $llantas;
    $param["aviso_dekra_dias"]      = $dekra;
    $param["aviso_marchamo_dias"] = $marchamo;
    $param["aviso_seguro_dias"]   = $seguro;
}

include "../../includes/header.php";
?>

<div class="container py-4">
    <h3 class="mb-3">Configuración vehicular</h3>

    <?php if ($mensajeOk): ?>
        <div class="alert alert-success"><?= e($mensajeOk) ?></div>
    <?php endif; ?>

    <form method="POST" class="card p-3">
        <h5>Parámetros de mantenimiento</h5>
        <div class="row">
            <div class="mb-3 col-md-4">
                <label class="form-label">Km por defecto para cambio de aceite</label>
                <input type="number" name="km_cambio_aceite" class="form-control"
                       value="<?= (int)($param["km_cambio_aceite"] ?? 5000) ?>">
            </div>
            <div class="mb-3 col-md-4">
                <label class="form-label">Km por defecto para cambio de llantas</label>
                <input type="number" name="km_cambio_llantas" class="form-control"
                       value="<?= (int)($param["km_cambio_llantas"] ?? 40000) ?>">
            </div>
        </div>

        <h5 class="mt-3">Avisos de vencimiento (días antes)</h5>
        <div class="row">
            <div class="mb-3 col-md-4">
                <label class="form-label">DEKRA</label>
                <input type="number" name="aviso_dekra_dias" class="form-control"
                       value="<?= (int)($param["aviso_dekra_dias"] ?? 30) ?>">
            </div>
            <div class="mb-3 col-md-4">
                <label class="form-label">Marchamo</label>
                <input type="number" name="aviso_marchamo_dias" class="form-control"
                       value="<?= (int)($param["aviso_marchamo_dias"] ?? 30) ?>">
            </div>
            <div class="mb-3 col-md-4">
                <label class="form-label">Seguro</label>
                <input type="number" name="aviso_seguro_dias" class="form-control"
                       value="<?= (int)($param["aviso_seguro_dias"] ?? 30) ?>">
            </div>
        </div>

        <button class="btn btn-primary">Guardar cambios</button>
    </form>

    <div class="alert alert-info small mt-3">
        Más adelante aquí podemos agregar:
        <ul class="mb-0">
            <li>Tipos de mantenimiento configurables.</li>
            <li>Políticas de alertas (dashboard, correo, etc.).</li>
        </ul>
    </div>
</div>

<?php include "../../includes/footer.php"; ?>