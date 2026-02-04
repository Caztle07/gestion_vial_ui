<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Charset / headers
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// 3) Solo Admin
if (!can_edit("admin")) {
    include "../../includes/header.php";
    echo "<div class='alert alert-danger m-4'>Sin permisos.</div>";
    include "../../includes/footer.php";
    exit;
}

// 4) Ya con todo validado, sacamos el header visual
include "../../includes/header.php";

/* ======================================
   CONSULTAS
====================================== */

/* CRÓNICAS EN PAPELERA
   - Si la crónica está marcada como papelera (estado_registro = '0')
   - O si su PROYECTO está en papelera (p.activo = 0)
*/
$cronicas = $conn->query("
    SELECT c.*, 
           p.nombre AS proyecto_nombre,
           e.nombre AS encargado_nombre,
           d.nombre AS distrito_nombre
    FROM cronicas c
    LEFT JOIN proyectos p ON p.id = c.proyecto_id
    LEFT JOIN encargados e ON e.id = c.encargado
    LEFT JOIN distritos d ON d.id = c.distrito
    WHERE c.estado_registro = '0'
       OR p.activo = 0
    ORDER BY c.id DESC
");

/* PROYECTOS EN PAPELERA
   Usamos activo = 0
*/
$proyectos = $conn->query("
    SELECT p.*, 
           i.codigo, 
           i.nombre AS camino,
           e.nombre AS encargado,
           d.nombre AS distrito,
           m.nombre AS modalidad
    FROM proyectos p
    LEFT JOIN caminos i ON i.id = p.inventario_id
    LEFT JOIN encargados e ON e.id = p.encargado_id
    LEFT JOIN distritos d ON d.id = p.distrito_id
    LEFT JOIN modalidades m ON m.id = p.modalidad_id
    WHERE p.activo = 0
    ORDER BY p.id DESC
");

?>

<div class="container py-4">

    <h2 class="fw-bold text-danger mb-4">
        <i class="bi bi-trash"></i> Papelera
    </h2>

    <!-- ALERTAS -->
    <?php if(isset($_GET["err"])): ?>
        <div class="alert alert-danger">
            <i class="bi bi-x-circle"></i>
            <?= htmlspecialchars($_GET["err"]) ?>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET["ok"])): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle"></i>
            Eliminación permanente realizada correctamente.
        </div>
    <?php endif; ?>


    <!-- BOTÓN VACÍAR -->
    <button class="btn btn-danger btn-lg fw-bold px-5 mb-4"
            data-bs-toggle="modal" data-bs-target="#modalVaciar">
        <i class="bi bi-trash3"></i> Vaciar Papelera
    </button>

    <!-- CRÓNICAS EN PAPELERA -->
    <h3 class="fw-bold text-danger mb-3">
        <i class="bi bi-journal-x"></i> Crónicas en Papelera
    </h3>

    <table class="table table-bordered table-striped text-center">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Proyecto</th>
                <th>Encargado</th>
                <th>Distrito</th>
                <th>Estado</th>
                <th>Fecha</th>
                <th>Restaurar</th>
            </tr>
        </thead>
        <tbody>
        <?php if($cronicas->num_rows == 0): ?>
            <tr><td colspan="7" class="text-muted">No hay crónicas.</td></tr>
        <?php else: ?>
            <?php while($c = $cronicas->fetch_assoc()): ?>
                <tr>
                    <td><?= $c["id"] ?></td>
                    <td><?= htmlspecialchars($c["proyecto_nombre"] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c["encargado_nombre"] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c["distrito_nombre"] ?? '-') ?></td>
                    <td><?= htmlspecialchars($c["estado"]) ?></td>
                    <td><?= date("d/m/Y", strtotime($c["fecha"])) ?></td>
                    <td>
                        <a href="../cronicas/cronica_restaurar.php?id=<?= $c['id'] ?>"
                           class="btn btn-success btn-sm">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <!-- PROYECTOS EN PAPELERA -->
    <h3 class="fw-bold text-secondary mt-5 mb-3">
        <i class="bi bi-folder-x"></i> Proyectos en Papelera
    </h3>

    <table class="table table-bordered table-striped text-center">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Camino</th>
                <th>Encargado</th>
                <th>Distrito</th>
                <th>Modalidad</th>
                <th>Estado</th>
                <th>Inicio</th>
                <th>Restaurar</th>
            </tr>
        </thead>
        <tbody>
        <?php if($proyectos->num_rows == 0): ?>
            <tr><td colspan="9" class="text-muted">No hay proyectos.</td></tr>
        <?php else: ?>
            <?php while($p = $proyectos->fetch_assoc()): ?>
                <tr>
                    <td><?= $p['id'] ?></td>
                    <td><?= htmlspecialchars($p['nombre']) ?></td>
                    <td><?= htmlspecialchars($p['codigo'] . " - " . $p['camino']) ?></td>
                    <td><?= htmlspecialchars($p['encargado']) ?></td>
                    <td><?= htmlspecialchars($p['distrito']) ?></td>
                    <td><?= htmlspecialchars($p['modalidad']) ?></td>
                    <td><?= htmlspecialchars($p['estado']) ?></td>
                    <td><?= ($p['fecha_inicio'] ? date("d/m/Y", strtotime($p['fecha_inicio'])) : "") ?></td>
                    <td>
                        <a href="../proyectos/proyecto_restaurar.php?id=<?= $p['id'] ?>"
                           class="btn btn-success btn-sm">
                           <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
        </tbody>
    </table>

</div>


<!-- MODAL VACÍAR PAPELERA -->
<div class="modal fade" id="modalVaciar" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header bg-danger text-white">
          <h5 class="modal-title fw-bold">
              <i class="bi bi-trash3"></i> Vaciar Papelera
          </h5>
          <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST" action="papelera_eliminar.php">

      <div class="modal-body">

        <p class="text-muted text-center mb-4">
            Seleccione los elementos a eliminar de forma permanente.
        </p>

        <!-- CRÓNICAS -->
        <h5 class="text-danger fw-bold"><i class="bi bi-journal-x"></i> Crónicas</h5>

        <table class="table table-sm table-bordered text-center mb-4">
            <thead class="table-dark">
                <tr>
                    <th>Sel</th>
                    <th>ID</th>
                    <th>Proyecto</th>
                    <th>Encargado</th>
                    <th>Distrito</th>
                </tr>
            </thead>
            <tbody>
            <?php mysqli_data_seek($cronicas, 0); ?>
            <?php while($c = $cronicas->fetch_assoc()): ?>
                <tr>
                    <td><input type="checkbox" name="cronicas[]" value="<?= $c['id'] ?>"></td>
                    <td><?= $c['id'] ?></td>
                    <td><?= $c['proyecto_nombre'] ?></td>
                    <td><?= $c['encargado_nombre'] ?></td>
                    <td><?= $c['distrito_nombre'] ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <!-- PROYECTOS -->
        <h5 class="text-secondary fw-bold"><i class="bi bi-folder-x"></i> Proyectos</h5>

        <table class="table table-sm table-bordered text-center">
            <thead class="table-dark">
                <tr>
                    <th>Sel</th>
                    <th>ID</th>
                    <th>Nombre</th>
                    <th>Camino</th>
                </tr>
            </thead>
            <tbody>
            <?php mysqli_data_seek($proyectos, 0); ?>
            <?php while($p = $proyectos->fetch_assoc()): ?>
                <tr>
                    <td><input type="checkbox" name="proyectos[]" value="<?= $p['id'] ?>"></td>
                    <td><?= $p['id'] ?></td>
                    <td><?= $p['nombre'] ?></td>
                    <td><?= $p['codigo']." - ".$p['camino'] ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>

        <hr>

        <!-- AUTENTICACIÓN -->
        <h5 class="text-center fw-bold text-danger">Autenticación requerida</h5>

        <div class="row mt-3">
            <div class="col-md-4">
                <label>Usuario administrador</label>
                <input name="auth_user" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label>Contraseña</label>
                <input type="password" name="auth_pass" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label>Escriba CONFIRMO</label>
                <input name="confirm" class="form-control" required>
            </div>
        </div>

      </div>

      <div class="modal-footer">
        <button class="btn btn-danger btn-lg fw-bold px-5">
            <i class="bi bi-trash-fill"></i> Eliminar Definitivamente
        </button>
      </div>

      </form>

    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
