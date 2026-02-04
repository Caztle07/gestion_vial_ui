<?php
require_once "../../auth.php";
require_login();

if ($_SESSION["rol"] !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

require_once "../../config/db.php";

$id = $_GET["id"] ?? null;
$edit = false;
$data = [
    "placa" => "",
    "tipo" => "",
    "marca" => "",
    "modelo" => "",
    "anio" => "",
    "km_actual" => "",
    "combustible" => "",
    "estado" => "disponible",
    "dekra_vencimiento" => "",
    "marchamo_vencimiento" => "",
    "seguro_vencimiento" => "",
    "observaciones" => ""
];

if ($id) {
    $edit = true;
    $stmt = $conn->prepare("SELECT * FROM vehiculos WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows == 1) {
        $data = $res->fetch_assoc();
    }
    $stmt->close();
}

include "../../includes/header.php";
?>

<div class="container py-4">
  <h3><?= $edit ? "Editar vehículo" : "Nuevo vehículo" ?></h3>
  <form action="vehiculo_guardar.php" method="post">
    
    <?php if ($edit): ?>
      <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>

    <div class="row mb-3">
      <div class="col-md-4">
        <label class="form-label">Placa</label>
        <input type="text" name="placa" class="form-control" value="<?= $data['placa'] ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Tipo</label>
        <input type="text" name="tipo" class="form-control" value="<?= $data['tipo'] ?>" required>
      </div>

      <div class="col-md-4">
        <label class="form-label">Marca</label>
        <input type="text" name="marca" class="form-control" value="<?= $data['marca'] ?>" required>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-4">
        <label class="form-label">Modelo</label>
        <input type="text" name="modelo" class="form-control" value="<?= $data['modelo'] ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Año</label>
        <input type="number" name="anio" class="form-control" value="<?= $data['anio'] ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">KM Actual</label>
        <input type="number" name="km_actual" class="form-control" value="<?= $data['km_actual'] ?>">
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-4">
        <label class="form-label">Combustible</label>
        <select name="combustible" class="form-select">
          <option value="">—</option>
          <option value="gasolina" <?= $data['combustible']=='gasolina'?'selected':'' ?>>Gasolina</option>
          <option value="diesel" <?= $data['combustible']=='diesel'?'selected':'' ?>>Diesel</option>
          <option value="electrico" <?= $data['combustible']=='electrico'?'selected':'' ?>>Eléctrico</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label">Estado</label>
        <select name="estado" class="form-select">
          <option value="disponible" <?= $data['estado']=='disponible'?'selected':'' ?>>Disponible</option>
          <option value="en_uso" <?= $data['estado']=='en_uso'?'selected':'' ?>>En uso</option>
          <option value="mantenimiento" <?= $data['estado']=='mantenimiento'?'selected':'' ?>>Mantenimiento</option>
          <option value="inactivo" <?= $data['estado']=='inactivo'?'selected':'' ?>>Inactivo</option>
        </select>
      </div>
    </div>

    <div class="row mb-3">
      <div class="col-md-4">
        <label class="form-label">Dekra Vence</label>
        <input type="date" name="dekra_vencimiento" class="form-control" value="<?= $data['dekra_vencimiento'] ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Marchamo Vence</label>
        <input type="date" name="marchamo_vencimiento" class="form-control" value="<?= $data['marchamo_vencimiento'] ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Seguro Vence</label>
        <input type="date" name="seguro_vencimiento" class="form-control" value="<?= $data['seguro_vencimiento'] ?>">
      </div>
    </div>

    <div class="mb-3">
      <label class="form-label">Observaciones</label>
      <textarea name="observaciones" class="form-control" rows="3"><?= $data['observaciones'] ?></textarea>
    </div>

    <button class="btn btn-success">Guardar</button>
    <a href="vehiculos.php" class="btn btn-secondary">Cancelar</a>
  </form>
</div>

<?php include "../../includes/footer.php"; ?>
