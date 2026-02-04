<?php
require_once "../../auth.php";
require_login();

if ($_SESSION["rol"] !== "adminvehicular") {
    header("Location: /gestion_vial_ui/no_autorizado.php");
    exit;
}

require_once "../../config/db.php";
$res = $conn->query("SELECT * FROM vehiculos ORDER BY id DESC");
?>

<?php include "../../includes/header.php"; ?>

<div class="container py-4">
  <h3>Vehículos</h3>
  <a href="vehiculo_form.php" class="btn btn-primary mb-3">+ Nuevo vehículo</a>

  <table class="table table-bordered">
    <thead>
      <tr>
        <th>Placa</th>
        <th>Marca</th>
        <th>Modelo</th>
        <th>Año</th>
        <th>Estado</th>
        <th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php while ($v = $res->fetch_assoc()): ?>
      <tr>
        <td><?= $v["placa"] ?></td>
        <td><?= $v["marca"] ?></td>
        <td><?= $v["modelo"] ?></td>
        <td><?= $v["anio"] ?></td>
        <td><?= $v["estado"] ?></td>
        <td>
          <a href="vehiculo_ver.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-info">Ver</a>
          <a href="vehiculo_form.php?id=<?= $v['id'] ?>" class="btn btn-sm btn-warning">Editar</a>
          <a href="vehiculo_eliminar.php?id=<?= $v['id'] ?>"
             class="btn btn-sm btn-danger"
             onclick="return confirm('¿Eliminar vehículo? Esta acción no se puede deshacer');">
             Eliminar
          </a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php include "../../includes/footer.php"; ?>
