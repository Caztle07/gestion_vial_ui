<?php
require_once "../../config/db.php";
require_once "../auth.php";
include "../../includes/header.php";

$puedeEditar = can_edit("admin");

// Insertar nuevo tipo de obra
if ($_SERVER["REQUEST_METHOD"] === "POST" && $puedeEditar) {
    $sql = "INSERT INTO tipos_obra (nombre, descripcion) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $_POST["nombre"], $_POST["descripcion"]);
    $stmt->execute();
}

$tipos = $conn->query("SELECT * FROM tipos_obra ORDER BY id DESC");
?>
<div class="container-fluid py-4">
  <h2 class="fw-semibold mb-3">Tipos de Obra</h2>

  <?php if ($puedeEditar): ?>
  <form method="POST" class="row g-2 mb-4 card card-body shadow-sm">
    <div class="col-md-4"><input name="nombre" class="form-control" placeholder="Nombre del tipo de obra" required></div>
    <div class="col-md-8"><input name="descripcion" class="form-control" placeholder="Descripción"></div>
    <div class="col-12 text-end"><button class="btn btn-success"><i class="bi bi-plus-circle"></i> Agregar</button></div>
  </form>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0">
        <thead class="table-light">
          <tr><th>ID</th><th>Nombre</th><th>Descripción</th></tr>
        </thead>
        <tbody>
          <?php while ($t = $tipos->fetch_assoc()): ?>
          <tr>
            <td><?= $t["id"] ?></td>
            <td><?= htmlspecialchars($t["nombre"]) ?></td>
            <td><?= htmlspecialchars($t["descripcion"]) ?></td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
