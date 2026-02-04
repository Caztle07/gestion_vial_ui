<?php
require_once "../../config/db.php";
require_once "../../auth.php";

require_login();

// Solo admin puede entrar a Inspectores
if (!can_edit("admin")) {
    echo "<div class='alert alert-danger m-3'>❌ Solo el administrador puede ver los inspectores.</div>";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

// Datos básicos para el log
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// === Crear / Editar / Eliminar (siempre ANTES de sacar HTML) ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"])) {

    $accion = $_POST["accion"];

    if ($accion === "nuevo") {
        $stmt = $conn->prepare("INSERT INTO inspectores (nombre, correo, telefono) VALUES (?,?,?)");
        $stmt->bind_param("sss", $_POST["nombre"], $_POST["correo"], $_POST["telefono"]);
        $stmt->execute();
        $nuevoId = $conn->insert_id;
        $stmt->close();

        // LOG después (inspector recién creado)
        $despues = [];
        $sel = $conn->prepare("SELECT * FROM inspectores WHERE id = ?");
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
            "tipo"         => "inspector",
            "accion"       => "nuevo",
            "inspector_id" => $nuevoId,
            "antes"        => null,
            "despues"      => $despues
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip, fecha)
            VALUES (?, ?, 'INSPECTOR_NUEVO', ?, ?, NOW())
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }

    } elseif ($accion === "editar") {

        $idIns = intval($_POST["id"]);

        // LOG: antes
        $antes = [];
        $selA = $conn->prepare("SELECT * FROM inspectores WHERE id = ?");
        if ($selA) {
            $selA->bind_param("i", $idIns);
            $selA->execute();
            $resA = $selA->get_result();
            if ($rowA = $resA->fetch_assoc()) {
                $antes = $rowA;
            }
            $selA->close();
        }

        $stmt = $conn->prepare("UPDATE inspectores SET nombre=?, correo=?, telefono=? WHERE id=?");
        $stmt->bind_param("sssi", $_POST["nombre"], $_POST["correo"], $_POST["telefono"], $_POST["id"]);
        $stmt->execute();
        $stmt->close();

        // LOG: después
        $despues = [];
        $selD = $conn->prepare("SELECT * FROM inspectores WHERE id = ?");
        if ($selD) {
            $selD->bind_param("i", $idIns);
            $selD->execute();
            $resD = $selD->get_result();
            if ($rowD = $resD->fetch_assoc()) {
                $despues = $rowD;
            }
            $selD->close();
        }

        $detalle = [
            "tipo"         => "inspector",
            "accion"       => "editar",
            "inspector_id" => $idIns,
            "antes"        => $antes,
            "despues"      => $despues
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip, fecha)
            VALUES (?, ?, 'INSPECTOR_EDITAR', ?, ?, NOW())
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }

    } elseif ($accion === "eliminar") {

        $id = intval($_POST["id"]);

        // LOG: antes (el inspector que se va a borrar)
        $antes = [];
        $selE = $conn->prepare("SELECT * FROM inspectores WHERE id = ?");
        if ($selE) {
            $selE->bind_param("i", $id);
            $selE->execute();
            $resE = $selE->get_result();
            if ($rowE = $resE->fetch_assoc()) {
                $antes = $rowE;
            }
            $selE->close();
        }

        $conn->query("DELETE FROM inspectores WHERE id=$id");

        $detalle = [
            "tipo"         => "inspector",
            "accion"       => "eliminar",
            "inspector_id" => $id,
            "antes"        => $antes,
            "despues"      => null
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip, fecha)
            VALUES (?, ?, 'INSPECTOR_ELIMINAR', ?, ?, NOW())
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }
    }

    // Redirección post/redirect/get para evitar recargas dobles
    header("Location: inspectores.php");
    exit;
}

// Listado para la vista
$rows = $conn->query("SELECT * FROM inspectores ORDER BY id ASC");

include "../../includes/header.php";
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary"><i class="bi bi-person-badge"></i> Inspectores</h3>

    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNuevoInspector">
      <i class="bi bi-plus-circle"></i> Nuevo Inspector
    </button>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold">Listado de Inspectores</div>
    <div class="table-responsive">
      <table class="table table-striped mb-0 align-middle text-center">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Correo</th>
            <th>Teléfono</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows->num_rows == 0): ?>
          <tr><td colspan="5" class="text-muted py-3">No hay inspectores registrados.</td></tr>
        <?php else: while ($i = $rows->fetch_assoc()): ?>
          <tr>
            <td><?= (int)$i["id"] ?></td>
            <td><?= htmlspecialchars($i["nombre"], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($i["correo"], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($i["telefono"], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <button class="btn btn-outline-warning btn-sm"
                      data-bs-toggle="modal"
                      data-bs-target="#edit<?= $i['id'] ?>">
                <i class="bi bi-pencil"></i>
              </button>

              <form method="POST" class="d-inline"
                    onsubmit="return confirm('¿Eliminar este inspector?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button class="btn btn-outline-danger btn-sm">
                  <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>

          <!-- MODAL EDITAR -->
          <div class="modal fade" id="edit<?= $i['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                  <h5 class="modal-title">
                    <i class="bi bi-pencil-square"></i> Editar Inspector
                  </h5>
                  <button type="button" class="btn-close btn-close-white"
                          data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                  <input type="hidden" name="accion" value="editar">
                  <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">

                  <div class="modal-body">
                    <div class="row g-2">
                      <div class="col-md-6">
                        <label class="form-label">Nombre</label>
                        <input name="nombre" value="<?= htmlspecialchars($i['nombre'], ENT_QUOTES, 'UTF-8') ?>"
                               class="form-control">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Correo</label>
                        <input name="correo" value="<?= htmlspecialchars($i['correo'], ENT_QUOTES, 'UTF-8') ?>"
                               class="form-control">
                      </div>
                      <div class="col-md-6">
                        <label class="form-label">Teléfono</label>
                        <input name="telefono" value="<?= htmlspecialchars($i['telefono'], ENT_QUOTES, 'UTF-8') ?>"
                               class="form-control">
                      </div>
                    </div>
                  </div>

                  <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                      <i class="bi bi-x-circle"></i> Cancelar
                    </button>
                    <button class="btn btn-success">
                      <i class="bi bi-save"></i> Guardar
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </div>

        <?php endwhile; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- MODAL NUEVO -->
<div class="modal fade" id="modalNuevoInspector" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title">
          <i class="bi bi-person-plus"></i> Registrar Nuevo Inspector
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="nuevo">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Nombre</label>
              <input name="nombre" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Correo</label>
              <input name="correo" type="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Teléfono</label>
              <input name="telefono" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-success">
            <i class="bi bi-save2"></i> Guardar
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
