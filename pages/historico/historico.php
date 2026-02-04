<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../../config/db.php";
require_once "../../auth.php";

require_login(); // Siempre ANTES de sacar HTML

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

/**
 * Texto de estado para proyectos.
 * Usamos:
 *  - p.estado = '0'  -> En papelera
 *  - p.estado = '1'  -> Activo
 *  - p.estado = '2'  -> En ejecución
 *  - p.estado = '3'  -> Finalizado
 *  - si viene texto, lo devolvemos tal cual
 */
function estadoProyectoFila(array $p): string {
    $v = isset($p['estado']) ? (string)$p['estado'] : '';

    if ($v === '0') return 'En papelera';
    if ($v === '1') return 'Activo';
    if ($v === '2') return 'En ejecución';
    if ($v === '3') return 'Finalizado';

    if ($v !== '') return $v;
    return 'Sin estado';
}

/**
 * Texto de estado para crónicas
 *  - c.estado_registro: puede ser '1', 'activo', '0', 'papelera', etc.
 *  - c.estado: texto (Pendiente, En ejecución, Finalizado, etc.)
 */
function estadoCronicaFila(array $c): string {
    if (isset($c['estado_registro'])) {
        $v = (string)$c['estado_registro'];
        if ($v === '0' || $v === 'papelera') {
            return 'En papelera';
        }
    }
    if (!empty($c['estado'])) {
        return $c['estado'];
    }
    return 'Sin estado';
}

// ========================
// PROYECTOS ACTIVOS (NO PAPELERA)
// ========================
$proyectos = $conn->query("
    SELECT 
        p.*,
        e.nombre AS encargado_nombre,
        d.nombre AS distrito_nombre
    FROM proyectos p
    LEFT JOIN encargados e ON e.id = p.encargado_id
    LEFT JOIN distritos  d ON d.id = p.distrito_id
    WHERE p.estado <> '0'
    ORDER BY p.id DESC
");

// ========================
// CRÓNICAS ACTIVAS LIGADAS A PROYECTOS ACTIVOS
// ========================
// OJO: aquí cambiamos el filtro de estado_registro
$cronicas = $conn->query("
    SELECT 
        c.*,
        p.nombre AS proyecto_nombre,
        e.nombre AS encargado_nombre,
        d.nombre AS distrito_nombre
    FROM cronicas c
    INNER JOIN proyectos p 
        ON p.id = c.proyecto_id
        AND p.estado <> '0'                      -- solo proyectos NO en papelera
    LEFT JOIN encargados e ON e.id = c.encargado
    LEFT JOIN distritos  d ON d.id = c.distrito
    WHERE c.estado_registro IS NULL
       OR (c.estado_registro <> '0' AND c.estado_registro <> 'papelera')
    ORDER BY c.id DESC
");

include "../../includes/header.php";
?>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary">
      <i class="bi bi-clock-history"></i> Histórico General
    </h3>
  </div>

  <!-- ===================== -->
  <!-- PROYECTOS -->
  <!-- ===================== -->
  <div class="card mb-4 shadow-sm">
    <div class="card-header bg-primary text-white fw-semibold">
      <i class="bi bi-diagram-3"></i> Proyectos
    </div>
    <div class="table-responsive">
      <table class="table table-striped align-middle text-center mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Encargado</th>
            <th>Distrito</th>
            <th>Estado</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($proyectos && $proyectos->num_rows > 0): ?>
          <?php while ($p = $proyectos->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$p['id'] ?></td>
              <td><?= htmlspecialchars($p['nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($p['encargado_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($p['distrito_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="badge bg-info">
                  <?= htmlspecialchars(estadoProyectoFila($p), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <a href="../proyectos/proyecto_visor.php?id=<?= (int)$p['id'] ?>"
                   class="btn btn-outline-primary btn-sm">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="6" class="text-muted">No hay proyectos registrados.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ===================== -->
  <!-- CRÓNICAS -->
  <!-- ===================== -->
  <div class="card shadow-sm">
    <div class="card-header bg-success text-white fw-semibold">
      <i class="bi bi-journal-text"></i> Crónicas
    </div>
    <div class="table-responsive">
      <table class="table table-striped align-middle text-center mb-0">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Proyecto</th>
            <th>Encargado</th>
            <th>Distrito</th>
            <th>Estado</th>
            <th>Fecha</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($cronicas && $cronicas->num_rows > 0): ?>
          <?php while ($c = $cronicas->fetch_assoc()): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><?= htmlspecialchars($c['proyecto_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($c['encargado_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($c['distrito_nombre'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <span class="badge bg-secondary">
                  <?= htmlspecialchars(estadoCronicaFila($c), ENT_QUOTES, 'UTF-8') ?>
                </span>
              </td>
              <td>
                <?php
                  if (!empty($c['fecha'])) {
                      echo htmlspecialchars(date('d/m/Y', strtotime($c['fecha'])), ENT_QUOTES, 'UTF-8');
                  } else {
                      echo "-";
                  }
                ?>
              </td>
              <td>
                <a href="../cronicas/cronica_detalle.php?id=<?= (int)$c['id'] ?>"
                   class="btn btn-outline-success btn-sm">
                  <i class="bi bi-eye"></i>
                </a>
              </td>
            </tr>
          <?php endwhile; ?>
        <?php else: ?>
          <tr><td colspan="7" class="text-muted">No hay crónicas registradas.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
