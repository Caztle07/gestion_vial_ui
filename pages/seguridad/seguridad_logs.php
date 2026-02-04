<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Solo ADMIN (según can_edit)
if (!can_edit("admin")) {
    header("Location: ../../index.php?err=" . urlencode("Acceso denegado. Solo admin puede ver este registro."));
    exit;
}

// 3) Charset / headers (antes de sacar HTML)
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn,"utf8");

// 4) Ahora sí, header visual
include "../../includes/header.php";

// TRAER LOGS
$logs = $conn->query("
    SELECT id, usuario, accion, fecha
    FROM logs_seguridad
    ORDER BY fecha DESC
");
?>

<div class="container py-4">

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary">
      <i class="bi bi-shield-lock"></i> Registro de Seguridad
    </h3>

    <a href="../pages/papelera.php" class="btn btn-outline-secondary">
      <i class="bi bi-arrow-left"></i> Volver
    </a>
  </div>

  <div class="card shadow-sm border-0">
    <div class="card-header bg-dark text-white fw-semibold">
      Historial de acciones críticas
    </div>

    <div class="table-responsive">
      <table class="table table-striped align-middle text-center mb-0">
        <thead class="table-dark">
          <tr>
            <th>#</th>
            <th>Usuario</th>
            <th>Acción</th>
            <th>Fecha</th>
          </tr>
        </thead>
        <tbody>
        <?php if($logs->num_rows == 0): ?>
          <tr>
            <td colspan="4" class="text-muted py-3">No hay registros.</td>
          </tr>
        <?php else: ?>
          <?php while($l = $logs->fetch_assoc()): ?>
          <tr>
            <td><?= $l['id'] ?></td>
            <td><?= htmlspecialchars($l['usuario']) ?></td>
            <td><?= nl2br(htmlspecialchars($l['accion'])) ?></td>
            <td><?= htmlspecialchars($l['fecha']) ?></td>
          </tr>
          <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php include "../../includes/footer.php"; ?>
