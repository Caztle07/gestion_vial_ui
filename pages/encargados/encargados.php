<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "../../config/db.php";
require_once "../../auth.php";
require_login();

header('Content-Type: text/html; charset=utf-8');
mysqli_set_charset($conn, "utf8");

$puedeEditar = can_edit("admin");

// Datos básicos para el log
$usuarioLog = $_SESSION["usuario"] ?? "desconocido";
$rolLog     = $_SESSION["rol"] ?? "desconocido";
$ipLog      = $_SERVER["REMOTE_ADDR"] ?? "0.0.0.0";

// === Crear / Editar / Eliminar (ANTES del header.php) ===
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"]) && $puedeEditar) {
    
    if ($_POST["accion"] === "nuevo") {
        $stmt = $conn->prepare("INSERT INTO encargados (nombre, cargo, telefono, correo) VALUES (?,?,?,?)");
        $stmt->bind_param("ssss", $_POST["nombre"], $_POST["cargo"], $_POST["telefono"], $_POST["correo"]);
        $stmt->execute();
        $nuevoId = $conn->insert_id;
        $stmt->close();

        // LOG: después (encargado recién creado)
        $despues = [];
        $sel = $conn->prepare("SELECT * FROM encargados WHERE id = ?");
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
            "tipo"         => "encargado",
            "accion"       => "nuevo",
            "encargado_id" => $nuevoId,
            "antes"        => null,
            "despues"      => $despues
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'ENCARGADO_NUEVO', ?, ?)
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }

        header("Location: ../encargados/encargados.php");
        exit;
    }

    if ($_POST["accion"] === "editar") {
        $idEnc = intval($_POST["id"]);

        // LOG: antes
        $antes = [];
        $selA = $conn->prepare("SELECT * FROM encargados WHERE id = ?");
        if ($selA) {
            $selA->bind_param("i", $idEnc);
            $selA->execute();
            $resA = $selA->get_result();
            if ($rowA = $resA->fetch_assoc()) {
                $antes = $rowA;
            }
            $selA->close();
        }

        $stmt = $conn->prepare("UPDATE encargados SET nombre=?, cargo=?, telefono=?, correo=? WHERE id=?");
        $stmt->bind_param("ssssi", $_POST["nombre"], $_POST["cargo"], $_POST["telefono"], $_POST["correo"], $_POST["id"]);
        $stmt->execute();
        $stmt->close();

        // LOG: después
        $despues = [];
        $selD = $conn->prepare("SELECT * FROM encargados WHERE id = ?");
        if ($selD) {
            $selD->bind_param("i", $idEnc);
            $selD->execute();
            $resD = $selD->get_result();
            if ($rowD = $resD->fetch_assoc()) {
                $despues = $rowD;
            }
            $selD->close();
        }

        $detalle = [
            "tipo"         => "encargado",
            "accion"       => "editar",
            "encargado_id" => $idEnc,
            "antes"        => $antes,
            "despues"      => $despues
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'ENCARGADO_EDITAR', ?, ?)
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }

        header("Location: ../encargados/encargados.php");
        exit;
    }

    if ($_POST["accion"] === "eliminar") {
        $id = intval($_POST["id"]);

        // LOG: antes (lo que se va a borrar)
        $antes = [];
        $selE = $conn->prepare("SELECT * FROM encargados WHERE id = ?");
        if ($selE) {
            $selE->bind_param("i", $id);
            $selE->execute();
            $resE = $selE->get_result();
            if ($rowE = $resE->fetch_assoc()) {
                $antes = $rowE;
            }
            $selE->close();
        }

        $conn->query("DELETE FROM encargados WHERE id=$id");

        $detalle = [
            "tipo"         => "encargado",
            "accion"       => "eliminar",
            "encargado_id" => $id,
            "antes"        => $antes,
            "despues"      => null
        ];
        $jsonDetalle = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $log = $conn->prepare("
            INSERT INTO logs_acciones (usuario, rol, accion, detalle, ip)
            VALUES (?, ?, 'ENCARGADO_ELIMINAR', ?, ?)
        ");
        if ($log) {
            $log->bind_param("ssss", $usuarioLog, $rolLog, $jsonDetalle, $ipLog);
            $log->execute();
            $log->close();
        }

        header("Location: ../encargados/encargados.php");
        exit;
    }
}

// Consulta para mostrar
$rows = $conn->query("SELECT * FROM encargados ORDER BY id ASC");

include "../../includes/header.php";
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold text-primary"><i class="bi bi-person-vcard"></i> Encargados</h3>

    <?php if ($puedeEditar): ?>
      <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalNuevoEncargado">
        <i class="bi bi-plus-circle"></i> Nuevo Encargado
      </button>
    <?php endif; ?>
  </div>

  <div class="card shadow-sm">
    <div class="card-header fw-semibold">Listado de Encargados</div>
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0 text-center">
        <thead class="table-primary">
          <tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Cargo</th>
            <th>Teléfono</th>
            <th>Correo</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($rows->num_rows == 0): ?>
          <tr><td colspan="6" class="text-muted py-3">No hay encargados registrados.</td></tr>
        <?php else: while ($e = $rows->fetch_assoc()): ?>
          <tr>
            <td><?= $e["id"] ?></td>
            <td><?= htmlspecialchars($e["nombre"]) ?></td>
            <td><?= htmlspecialchars($e["cargo"]) ?></td>
            <td><?= htmlspecialchars($e["telefono"]) ?></td>
            <td><?= htmlspecialchars($e["correo"]) ?></td>
            <td>
              <?php if ($puedeEditar): ?>
              <button class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#edit<?= $e['id'] ?>"><i class="bi bi-pencil"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('¿Eliminar este encargado?')">
                <input type="hidden" name="accion" value="eliminar">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>

          <!-- Modal editar -->
          <div class="modal fade" id="edit<?= $e['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
              <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                  <h5 class="modal-title"><i class="bi bi-pencil-square"></i> Editar Encargado</h5>
                  <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                  <input type="hidden" name="accion" value="editar">
                  <input type="hidden" name="id" value="<?= $e['id'] ?>">
                  <div class="modal-body">
                    <div class="row g-2">
                      <div class="col-md-6"><label class="form-label">Nombre</label><input name="nombre" value="<?= htmlspecialchars($e['nombre']) ?>" class="form-control"></div>
                      <div class="col-md-6"><label class="form-label">Cargo</label><input name="cargo" value="<?= htmlspecialchars($e['cargo']) ?>" class="form-control"></div>
                      <div class="col-md-6"><label class="form-label">Teléfono</label><input name="telefono" value="<?= htmlspecialchars($e['telefono']) ?>" class="form-control"></div>
                      <div class="col-md-6"><label class="form-label">Correo</label><input name="correo" value="<?= htmlspecialchars($e['correo']) ?>" class="form-control"></div>
                    </div>
                  </div>
                  <div class="modal-footer d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> Cancelar</button>
                    <button class="btn btn-success"><i class="bi bi-save"></i> Guardar</button>
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

<!-- modal nuevo -->
<div class="modal fade" id="modalNuevoEncargado" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-person-plus"></i> Registrar Nuevo Encargado</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="accion" value="nuevo">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Nombre</label><input name="nombre" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Cargo</label><input name="cargo" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Teléfono</label><input name="telefono" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Correo</label><input name="correo" type="email" class="form-control"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-success"><i class="bi bi-save2"></i> Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include "../../includes/footer.php"; ?>
