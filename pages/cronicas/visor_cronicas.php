<?php
require_once "../../config/db.php";
require_once "../auth.php";
include "../../includes/header.php";

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ================================
   VISOR DE CRÓNICAS  actualizado
   ================================ */

$proyecto_id = isset($_GET['proyecto_id']) ? intval($_GET['proyecto_id']) : 0;

// Traer nombre de proyecto si viene filtrado
$proyecto_nombre = null;
if ($proyecto_id > 0) {
  $stmtP = $conn->prepare("SELECT nombre FROM proyectos WHERE id=?");
  $stmtP->bind_param("i", $proyecto_id);
  $stmtP->execute();
  $rp = $stmtP->get_result()->fetch_assoc();
  $proyecto_nombre = $rp['nombre'] ?? ("Proyecto #" . $proyecto_id);
  $stmtP->close();
}

try {
  if ($proyecto_id > 0) {

    $stmt = $conn->prepare("
      SELECT c.id, c.consecutivo, c.fecha, c.estado,
             p.nombre AS proyecto_nombre,
             e.nombre AS encargado_nombre,
             t.nombre AS tipo_nombre
      FROM cronicas c
      LEFT JOIN proyectos p       ON p.id = c.proyecto_id
      LEFT JOIN encargados e      ON e.id = c.encargado
      LEFT JOIN tipos_cronica t   ON t.id = c.tipo
      WHERE c.proyecto_id = ?
        AND (c.estado_registro = 'activo' OR c.estado_registro IS NULL)
      ORDER BY c.id DESC
    ");
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    $cronicas = $stmt->get_result();

  } else {

    $cronicas = $conn->query("
      SELECT c.id, c.consecutivo, c.fecha, c.estado,
             p.nombre AS proyecto_nombre,
             e.nombre AS encargado_nombre,
             t.nombre AS tipo_nombre
      FROM cronicas c
      LEFT JOIN proyectos p       ON p.id = c.proyecto_id
      LEFT JOIN encargados e      ON e.id = c.encargado
      LEFT JOIN tipos_cronica t   ON t.id = c.tipo
      WHERE (c.estado_registro = 'activo' OR c.estado_registro IS NULL)
      ORDER BY c.id DESC
    ");

  }
} catch (Exception $e) {
  echo "<div class='alert alert-danger m-4'><b>Error SQL:</b> ".htmlspecialchars($e->getMessage())."</div>";
  include "../../includes/footer.php";
  exit;
}
?>

<div class="container-fluid py-4">
  <h2 class="fw-semibold text-primary mb-3">
    <i class="bi bi-journal-text"></i> Visor de Crónicas
    <?php if ($proyecto_id > 0): ?>
      <small class="text-muted"> Proyecto: <?= htmlspecialchars($proyecto_nombre) ?></small>
    <?php endif; ?>
  </h2>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover table-bordered align-middle mb-0">
        <thead class="table-primary text-center">
          <tr>
            <th>ID</th>
            <th>Consecutivo</th>
            <th>Proyecto</th>
            <th>Encargado</th>
            <th>Tipo</th>
            <th>Estado</th>
            <th>Fecha</th>
            <th>Acción</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($cronicas->num_rows === 0): ?>
            <tr><td colspan="8" class="text-center text-muted py-3">No hay crónicas registradas.</td></tr>
          <?php else: ?>
            <?php while ($c = $cronicas->fetch_assoc()): ?>
              <tr>
                <td><?= $c["id"] ?></td>
                <td><?= htmlspecialchars($c["consecutivo"] ?? "(sin consecutivo)") ?></td>
                <td><?= htmlspecialchars($c["proyecto_nombre"] ?? "") ?></td>
                <td><?= htmlspecialchars($c["encargado_nombre"] ?? "") ?></td>
                <td><?= htmlspecialchars($c["tipo_nombre"] ?? "") ?></td>
                <td><?= htmlspecialchars($c["estado"] ?? "") ?></td>
                <td><?= htmlspecialchars($c["fecha"] ?? "") ?></td>
                <td class="text-center">
                  <a href="cronica_detalle.php?id=<?= $c["id"] ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-eye"></i> Ver
                  </a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>

