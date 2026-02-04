<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../../config/db.php";
require_once "../../auth.php";

// 1) Usuario logueado
require_login();

// 2) Headers / charset (SIEMPRE ANTES DE CUALQUIER HTML)
header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// 3) Permisos (solo admin)
$puedeEditar = can_edit("admin");

// Datos básicos para el log
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// === Insertar nueva modalidad ===
// OJO: esto VA ANTES de incluir header.php porque usa header("Location...")
if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["accion"])
    && $_POST["accion"] === "nueva"
    && $puedeEditar
) {

    $nombre = trim($_POST["nombre"] ?? "");

    if ($nombre !== "") {
        $sql  = "INSERT INTO modalidades (nombre) VALUES (?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $nombre);
        $stmt->execute();
        $nuevoId = $conn->insert_id;
        $stmt->close();

        // LOG: obtener la modalidad recién creada
        $despues = [];
        $sel = $conn->prepare("SELECT * FROM modalidades WHERE id = ?");
        if ($sel) {
            $sel->bind_param("i", $nuevoId);
            $sel->execute();
            $res = $sel->get_result();
            if ($row = $res->fetch_assoc()) {
                $despues = $row;
            }
            $sel->close();
        }

        $detalle = [
            "tipo"         => "modalidad",
            "accion"       => "nueva",
            "modalidad_id" => $nuevoId,
            "antes"        => null,
            "despues"      => $despues
        ];

        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'MODALIDAD_NUEVA', ?, ?)
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }
    }

    // Redirigir DESPUÉS de guardar, pero ANTES de sacar HTML
    header("Location: modalidades.php");
    exit;
}

// === Consultar modalidades (ya no hay header() después) ===
$modalidades = $conn->query("SELECT * FROM modalidades ORDER BY id DESC");

// 4) Header visual
include "../../includes/header.php";

// Si no es admin, no ve nada (aquí ya no usamos header(), solo HTML)
if (!$puedeEditar) {
    echo "<div class='alert alert-danger m-4'>Sin permisos.</div>";
    include "../../includes/footer.php";
    exit;
}
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary"><i class="bi bi-diagram-3"></i> Modalidades de Proyectos</h3>

    <?php if ($puedeEditar): ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNuevaModalidad">
        <i class="bi bi-plus-circle"></i> Nueva Modalidad
      </button>
    <?php endif; ?>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold">Listado de Modalidades</div>
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0 text-center">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Nombre</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($modalidades->num_rows == 0): ?>
            <tr><td colspan="2" class="text-muted py-3">No hay modalidades registradas.</td></tr>
          <?php else: while ($m = $modalidades->fetch_assoc()): ?>
            <tr>
              <td><?= $m["id"] ?></td>
              <td><?= htmlspecialchars($m["nombre"]) ?></td>
            </tr>
          <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- === POPUP NUEVA MODALIDAD === -->
<div class="modal fade" id="modalNuevaModalidad" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Registrar Nueva Modalidad</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <form method="POST">
        <input type="hidden" name="accion" value="nueva">

        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Nombre</label>
            <input name="nombre" class="form-control" placeholder="Ejemplo: Convenio, Mixta..." required>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button class="btn btn-success"><i class="bi bi-save"></i> Guardar</button>
        </div>
      </form>

    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
